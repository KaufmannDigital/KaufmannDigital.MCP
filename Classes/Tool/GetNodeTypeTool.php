<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeTypeDescriber;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class GetNodeTypeTool implements ToolInterface
{
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

    public function getDefinition(): array
    {
        return [
            'name' => 'get_node_type',
            'description' => 'Return the effective (merged) configuration of a NodeType: all properties (type, defaultValue, label, inspector group/editor, validation), tethered childNodes (type + constraints), superTypes, constraints, abstract/final. Use it to find out which properties a node can have — get_node only returns stored values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeType' => ['type' => 'string', 'description' => 'Node type name, e.g. Neos.Neos:Document'],
                    'includeInternalProperties' => ['type' => 'boolean', 'description' => 'Include internal "_"-prefixed properties like _hidden (default: false)'],
                    'includeAllowedChildNodeTypes' => ['type' => 'boolean', 'description' => 'Resolve constraints into lists of concrete (non-abstract) NodeTypes allowed as children of this NodeType ("allowedChildNodeTypes") and of each tethered child node ("childNodes.<name>.allowedChildNodeTypes"). Default: false. For an existing node use list_node_types with allowedUnderNodeIdentifier.'],
                ],
                'required' => ['nodeType'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $nodeTypeName = $args['nodeType'] ?? null;
        if (empty($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Error: nodeType is required']];
        }
        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Unknown node type: ' . $nodeTypeName]];
        }

        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);
        $result = $this->nodeTypeDescriber->describe(
            $nodeType,
            NodeCreator::parseBool($args['includeInternalProperties'] ?? null, false)
        );

        if (NodeCreator::parseBool($args['includeAllowedChildNodeTypes'] ?? null, false)) {
            $result['allowedChildNodeTypes'] = $this->nodeTypeDescriber->allowedChildNodeTypeNames($nodeType);
            foreach (array_keys($result['childNodes']) as $childNodeName) {
                $result['childNodes'][$childNodeName]['allowedChildNodeTypes'] = $this->nodeTypeDescriber->allowedChildNodeTypeNames($nodeType, (string)$childNodeName);
            }
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
