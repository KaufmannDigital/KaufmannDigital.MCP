<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeTypeDescriber;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class ListNodeTypesTool implements ToolInterface
{
    use DimensionOverrideTrait;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeTypeDescriber
     */
    protected $nodeTypeDescriber;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    public function getDefinition(): array
    {
        return [
            'name' => 'list_node_types',
            'description' => 'List the available NodeTypes (for discovering the project without knowing its code). Compact by default (name, label, abstract, final, direct superTypes). Filters can be combined. Use allowedUnderNodeIdentifier to answer "which NodeTypes may I create under node X" (constraints incl. tethered-node constraints are resolved).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'superType' => ['type' => 'string', 'description' => 'Only NodeTypes that are of this type (transitively), e.g. "Neos.Neos:Document" or "Neos.Neos:Content"'],
                    'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match on the NodeType name and its (translated) label'],
                    'namePrefix' => ['type' => 'string', 'description' => 'Only NodeTypes whose name starts with this prefix, e.g. "KaufmannDigital.Nova."'],
                    'includeAbstract' => ['type' => 'boolean', 'description' => 'Include abstract NodeTypes (mixins etc.; default: false). Ignored with allowedUnderNodeIdentifier (only concrete types can be created).'],
                    'detailed' => ['type' => 'boolean', 'description' => 'Return the full get_node_type structure per entry (properties, childNodes, constraints, …). Default: false — combine with filters to keep the response small.'],
                    'allowedUnderNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of an existing node: only NodeTypes that may be created directly below it. The response also contains "allowedUnder" with the node\'s tethered child nodes (e.g. "main") — content usually has to be created inside those.'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to resolve allowedUnderNodeIdentifier in (default: live)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $superType = !empty($args['superType']) ? (string)$args['superType'] : null;
        $search = !empty($args['search']) ? mb_strtolower((string)$args['search']) : null;
        $namePrefix = !empty($args['namePrefix']) ? (string)$args['namePrefix'] : null;
        $includeAbstract = NodeCreator::parseBool($args['includeAbstract'] ?? null, false);
        $detailed = NodeCreator::parseBool($args['detailed'] ?? null, false);

        if ($superType !== null && !$this->nodeTypeManager->hasNodeType($superType)) {
            return [['type' => 'text', 'text' => 'Unknown node type: ' . $superType]];
        }

        $response = [];
        $parentNode = null;
        if (!empty($args['allowedUnderNodeIdentifier'])) {
            $context = $this->contentContextFactory->create(array_merge([
                'workspaceName' => $args['workspaceName'] ?? 'live',
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true,
            ], $this->dimensionContextProperties($args['dimensions'] ?? null)));
            $parentNode = $context->getNodeByIdentifier((string)$args['allowedUnderNodeIdentifier']);
            if ($parentNode === null) {
                return [['type' => 'text', 'text' => 'Node not found: ' . $args['allowedUnderNodeIdentifier']]];
            }
            $tetheredChildNodes = [];
            foreach ($parentNode->getNodeType()->getAutoCreatedChildNodes() as $childNodeName => $childNodeType) {
                $childNode = $parentNode->getNode((string)$childNodeName);
                $tetheredChildNodes[(string)$childNodeName] = [
                    'identifier' => $childNode?->getIdentifier(),
                    'nodeType' => $childNodeType->getName(),
                ];
            }
            $response['allowedUnder'] = [
                'identifier' => $parentNode->getIdentifier(),
                'nodeType' => $parentNode->getNodeType()->getName(),
                'path' => $parentNode->getPath(),
                'tethered' => $parentNode->isTethered(),
                'tetheredChildNodes' => $tetheredChildNodes ?: (object)[],
            ];
            $includeAbstract = false;
        }

        $nodeTypes = [];
        /** @var NodeType $nodeType */
        foreach ($this->nodeTypeManager->getNodeTypes($includeAbstract) as $nodeType) {
            $name = $nodeType->getName();
            if ($namePrefix !== null && !str_starts_with($name, $namePrefix)) {
                continue;
            }
            if ($superType !== null && !$nodeType->isOfType($superType)) {
                continue;
            }
            if ($parentNode !== null && !$parentNode->isNodeTypeAllowedAsChildNode($nodeType)) {
                continue;
            }
            $entry = $detailed ? $this->nodeTypeDescriber->describe($nodeType) : $this->nodeTypeDescriber->summarize($nodeType);
            if ($search !== null
                && !str_contains(mb_strtolower($name), $search)
                && !str_contains(mb_strtolower((string)($entry['label'] ?? '')), $search)
            ) {
                continue;
            }
            $nodeTypes[$name] = $entry;
        }
        ksort($nodeTypes);

        $response['count'] = count($nodeTypes);
        $response['nodeTypes'] = array_values($nodeTypes);

        return [['type' => 'text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
