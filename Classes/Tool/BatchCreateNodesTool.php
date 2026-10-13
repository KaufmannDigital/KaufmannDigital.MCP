<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class BatchCreateNodesTool implements ToolInterface
{
    use DimensionOverrideTrait;

    protected const MAX_DEPTH = 20;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    /**
     * @Flow\Inject
     * @var NodeCreator
     */
    protected $nodeCreator;

    private int $createdCount = 0;

    private int $errorCount = 0;

    public function getDefinition(): array
    {
        $itemProperties = [
            'nodeType' => ['type' => 'string', 'description' => 'Node type name'],
            'nodeName' => ['type' => 'string', 'description' => 'Optional node name (default: random "node-…")'],
            'properties' => ['type' => 'object', 'description' => 'Key/value map of properties to set'],
            'applyDefaults' => ['type' => 'boolean', 'description' => 'Overrides the batch-level applyDefaults for this node'],
            'children' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Nested child items (same structure as a top-level item, but without parentNodeIdentifier/position/siblingNodeIdentifier). They are appended in order into the created node — or into the descendant given by their "parentPath".'],
        ];

        return [
            'name' => 'batch_create_nodes',
            'description' => 'Create multiple Neos nodes (optionally nested trees) in a single call. Each item is processed independently: a failing item is reported in its result and does not abort the batch (its children are skipped). Results mirror the input structure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodes' => [
                        'type' => 'array',
                        'description' => 'List of nodes to create, processed in order',
                        'items' => [
                            'type' => 'object',
                            'properties' => array_merge([
                                'parentNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the parent node (top-level items, position "into")'],
                                'position' => ['type' => 'string', 'enum' => NodeCreator::POSITIONS, 'description' => '"into" (default), "before" or "after" (top-level items only)'],
                                'siblingNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the sibling node for position "before"/"after"'],
                                'parentPath' => ['type' => 'string', 'description' => 'Nested items only: relative path below the enclosing created node to create this node in, e.g. "main" for a tethered content collection'],
                            ], $itemProperties),
                            'required' => ['nodeType'],
                        ],
                    ],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to create the nodes in (default: live)'],
                    'applyDefaults' => ['type' => 'boolean', 'description' => 'Apply NodeType default property values (default: true); see create_node'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per created node in the response. Default: only "identifier". Add node property names and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodes'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (is_string($args['nodes'] ?? null)) {
            $args['nodes'] = json_decode($args['nodes'], true) ?: [];
        }
        if (empty($args['nodes']) || !is_array($args['nodes'])) {
            return [['type' => 'text', 'text' => 'Error: nodes is required']];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $applyDefaults = NodeCreator::parseBool($args['applyDefaults'] ?? null, true);
        $responseProperties = $args['responseProperties'] ?? null;
        $this->createdCount = 0;
        $this->errorCount = 0;
        $results = [];

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($context, $args, $applyDefaults, $responseProperties, &$results) {
            foreach (array_values($args['nodes']) as $index => $item) {
                $results[] = $this->processItem($item, $index, null, $context, $applyDefaults, $responseProperties, 0);
            }
        });

        $this->persistenceManager->persistAll();

        return [['type' => 'text', 'text' => json_encode(
            ['createdCount' => $this->createdCount, 'errorCount' => $this->errorCount, 'results' => $results],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }

    /**
     * Creates one item (and recursively its children) and returns its result entry.
     *
     * @param mixed $item
     * @param NodeInterface|null $enclosingNode The created parent item's node (null for top-level items)
     * @return array<string, mixed>
     */
    protected function processItem(mixed $item, int $index, ?NodeInterface $enclosingNode, Context $context, bool $applyDefaults, mixed $responseProperties, int $depth): array
    {
        $result = ['index' => $index];
        try {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Item must be an object');
            }
            if ($depth > self::MAX_DEPTH) {
                throw new \InvalidArgumentException('Maximum nesting depth of ' . self::MAX_DEPTH . ' exceeded');
            }
            if (empty($item['nodeType'])) {
                throw new \InvalidArgumentException('nodeType is required');
            }

            [$referenceNode, $position] = $this->resolveReference($item, $enclosingNode, $context);

            $properties = $item['properties'] ?? [];
            if (is_string($properties)) {
                $properties = json_decode($properties, true) ?: [];
            }
            $nodeName = isset($item['nodeName']) && $item['nodeName'] !== '' ? (string)$item['nodeName'] : null;

            $created = $this->nodeCreator->create(
                $referenceNode,
                $position,
                (string)$item['nodeType'],
                is_array($properties) ? $properties : [],
                $nodeName,
                NodeCreator::parseBool($item['applyDefaults'] ?? null, $applyDefaults)
            );
            // Persist per node, so name/uriPathSegment uniqueness checks of later items see it
            $this->persistenceManager->persistAll();
            $this->createdCount++;

            $result += $this->nodeSerializer->serializeNodeFiltered($created['node'], $responseProperties);
            if (!empty($created['warnings'])) {
                $result['warnings'] = $created['warnings'];
            }
        } catch (\Throwable $e) {
            $this->errorCount++;
            $result['error'] = $e->getMessage();
            $skipped = $this->countItems(is_array($item) ? ($item['children'] ?? []) : []);
            if ($skipped > 0) {
                $result['skippedChildren'] = $skipped;
            }
            return $result;
        }

        $children = $item['children'] ?? [];
        if (is_string($children)) {
            $children = json_decode($children, true) ?: [];
        }
        if (!empty($children) && is_array($children)) {
            $result['children'] = [];
            foreach (array_values($children) as $childIndex => $childItem) {
                $result['children'][] = $this->processItem($childItem, $childIndex, $created['node'], $context, $applyDefaults, $responseProperties, $depth + 1);
            }
        }

        return $result;
    }

    /**
     * @return array{0: NodeInterface, 1: string}
     */
    protected function resolveReference(array $item, ?NodeInterface $enclosingNode, Context $context): array
    {
        if ($enclosingNode !== null) {
            if (!empty($item['parentNodeIdentifier']) || !empty($item['siblingNodeIdentifier']) || (($item['position'] ?? 'into') !== 'into')) {
                throw new \InvalidArgumentException('Nested items must not set parentNodeIdentifier, siblingNodeIdentifier or position — use "parentPath" instead');
            }
            $parentNode = $enclosingNode;
            if (!empty($item['parentPath'])) {
                $parentNode = $enclosingNode->getNode(trim((string)$item['parentPath'], '/'));
                if ($parentNode === null) {
                    throw new \InvalidArgumentException('parentPath "' . $item['parentPath'] . '" not found below ' . $enclosingNode->getPath());
                }
            }
            return [$parentNode, 'into'];
        }

        if (!empty($item['parentPath'])) {
            throw new \InvalidArgumentException('parentPath is only allowed on nested items');
        }
        $position = $item['position'] ?? 'into';
        if (!in_array($position, NodeCreator::POSITIONS, true)) {
            throw new \InvalidArgumentException('position must be one of: ' . implode(', ', NodeCreator::POSITIONS));
        }
        if ($position === 'into') {
            if (empty($item['parentNodeIdentifier'])) {
                throw new \InvalidArgumentException('parentNodeIdentifier is required for position "into"');
            }
            $parentNode = $context->getNodeByIdentifier((string)$item['parentNodeIdentifier']);
            if ($parentNode === null) {
                throw new \InvalidArgumentException('Parent node not found: ' . $item['parentNodeIdentifier']);
            }
            return [$parentNode, 'into'];
        }
        if (empty($item['siblingNodeIdentifier'])) {
            throw new \InvalidArgumentException('siblingNodeIdentifier is required for position "' . $position . '"');
        }
        $siblingNode = $context->getNodeByIdentifier((string)$item['siblingNodeIdentifier']);
        if ($siblingNode === null) {
            throw new \InvalidArgumentException('Sibling node not found: ' . $item['siblingNodeIdentifier']);
        }
        if (!empty($item['parentNodeIdentifier']) && $siblingNode->getParent()?->getIdentifier() !== $item['parentNodeIdentifier']) {
            throw new \InvalidArgumentException('Sibling node ' . $item['siblingNodeIdentifier'] . ' is not a child of parent node ' . $item['parentNodeIdentifier']);
        }
        return [$siblingNode, $position];
    }

    protected function countItems(mixed $items): int
    {
        if (!is_array($items)) {
            return 0;
        }
        $count = 0;
        foreach ($items as $item) {
            $count++;
            if (is_array($item)) {
                $count += $this->countItems($item['children'] ?? []);
            }
        }
        return $count;
    }
}
