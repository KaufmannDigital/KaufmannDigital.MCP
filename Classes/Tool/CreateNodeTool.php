<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class CreateNodeTool implements ToolInterface
{
    use DimensionOverrideTrait;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

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

    public function getDefinition(): array
    {
        return [
            'name' => 'create_node',
            'description' => 'Create a new Neos node under a given parent node (appended as last child) or before/after a sibling node. Default workspace is "live" — use a user workspace if you want to review before publishing. The response contains "warnings" if the stored node name or property values differ from the requested ones (e.g. uriPathSegment made unique).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'parentNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the parent node. Required for position "into"; optional for "before"/"after" (the sibling\'s parent is used, if given it must match).'],
                    'nodeType' => ['type' => 'string', 'description' => 'Node type name, e.g. KaufmannDigital.Nova.Magazine:Page.Magazine'],
                    'properties' => ['type' => 'object', 'description' => 'Optional key/value map of properties to set on the new node'],
                    'nodeName' => ['type' => 'string', 'description' => 'Optional node name (path segment). Default: random "node-…" name. If the name is invalid or already taken below the parent, a different name is used and a warning is returned.'],
                    'position' => ['type' => 'string', 'enum' => NodeCreator::POSITIONS, 'description' => '"into" (default): append as last child of parentNodeIdentifier. "before"/"after": insert as sibling directly before/after siblingNodeIdentifier.'],
                    'siblingNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the sibling node — required for position "before"/"after"'],
                    'applyDefaults' => ['type' => 'boolean', 'description' => 'Apply NodeType default property values (default: true). false: default values are not stored on the new node and its tethered child nodes (internal "_"-properties excepted); explicitly passed properties are always set.'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to create the node in (default: live)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include in the response. Default: only "identifier". Add node property names (e.g. "title", "uriPathSegment") and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodeType'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $parentNodeIdentifier = $args['parentNodeIdentifier'] ?? null;
        $nodeTypeName = $args['nodeType'] ?? null;
        $position = $args['position'] ?? 'into';
        $siblingNodeIdentifier = $args['siblingNodeIdentifier'] ?? null;

        if (empty($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Error: nodeType is required']];
        }
        if (!in_array($position, NodeCreator::POSITIONS, true)) {
            return [['type' => 'text', 'text' => 'Error: position must be one of: ' . implode(', ', NodeCreator::POSITIONS)]];
        }
        if ($position === 'into' && empty($parentNodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: parentNodeIdentifier is required for position "into"']];
        }
        if ($position !== 'into' && empty($siblingNodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: siblingNodeIdentifier is required for position "' . $position . '"']];
        }

        $workspaceName = $args['workspaceName'] ?? 'live';

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        if ($position === 'into') {
            $referenceNode = $context->getNodeByIdentifier($parentNodeIdentifier);
            if ($referenceNode === null) {
                return [['type' => 'text', 'text' => 'Parent node not found: ' . $parentNodeIdentifier]];
            }
        } else {
            $referenceNode = $context->getNodeByIdentifier($siblingNodeIdentifier);
            if ($referenceNode === null) {
                return [['type' => 'text', 'text' => 'Sibling node not found: ' . $siblingNodeIdentifier]];
            }
            if (!empty($parentNodeIdentifier) && $referenceNode->getParent()?->getIdentifier() !== $parentNodeIdentifier) {
                return [['type' => 'text', 'text' => 'Error: sibling node ' . $siblingNodeIdentifier . ' is not a child of parent node ' . $parentNodeIdentifier]];
            }
        }

        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Unknown node type: ' . $nodeTypeName]];
        }

        $properties = $args['properties'] ?? [];
        if (is_string($properties)) {
            $properties = json_decode($properties, true) ?: [];
        }
        if (!is_array($properties)) {
            $properties = [];
        }
        $nodeName = isset($args['nodeName']) && $args['nodeName'] !== '' ? (string)$args['nodeName'] : null;
        $applyDefaults = NodeCreator::parseBool($args['applyDefaults'] ?? null, true);

        $result = null;
        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        try {
            $securityContext->withoutAuthorizationChecks(function () use ($referenceNode, $position, $nodeTypeName, $properties, $nodeName, $applyDefaults, &$result) {
                $result = $this->nodeCreator->create($referenceNode, $position, $nodeTypeName, $properties, $nodeName, $applyDefaults);
            });
        } catch (\InvalidArgumentException | \Neos\ContentRepository\Exception $e) {
            return [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]];
        }

        $this->persistenceManager->persistAll();

        $response = ['created' => $this->nodeSerializer->serializeNodeFiltered($result['node'], $args['responseProperties'] ?? null)];
        if (!empty($result['warnings'])) {
            $response['warnings'] = $result['warnings'];
        }

        return [['type' => 'text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
