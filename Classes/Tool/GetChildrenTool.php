<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class GetChildrenTool implements ToolInterface
{
    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    public function getDefinition(): array
    {
        return [
            'name' => 'get_children',
            'description' => 'Get direct child nodes of a Neos node, optionally filtered by node type.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the parent node'],
                    'nodeTypeFilter' => ['type' => 'string', 'description' => 'Node type filter, e.g. Neos.Neos:Document (optional)'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace name (default: live)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node. Default: only "identifier". Add node property names (e.g. "title", "releaseDate") and/or meta-fields: "nodeType", "label", "name", "path", "workspace", "hidden".',
                    ],
                ],
                'required' => ['nodeIdentifier'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (empty($args['nodeIdentifier'])) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier is required']];
        }

        $context = $this->contentContextFactory->create([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $node = $context->getNodeByIdentifier($args['nodeIdentifier']);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $args['nodeIdentifier']]];
        }

        $q = new FlowQuery([$node]);
        $filter = !empty($args['nodeTypeFilter']) ? '[instanceof ' . $args['nodeTypeFilter'] . ']' : '';
        $children = $q->children($filter)->get();

        $responseProperties = $args['responseProperties'] ?? null;
        return [['type' => 'text', 'text' => json_encode(
            array_map(fn($child) => $this->nodeSerializer->serializeNodeFiltered($child, $responseProperties), $children),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
