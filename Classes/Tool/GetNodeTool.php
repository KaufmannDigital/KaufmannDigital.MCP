<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class GetNodeTool implements ToolInterface
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

    /**
     * @Flow\InjectConfiguration(path="dimensions")
     * @var array
     */
    protected $dimensions;

    /**
     * @Flow\InjectConfiguration(path="targetDimensions")
     * @var array
     */
    protected $targetDimensions;

    public function getDefinition(): array
    {
        return [
            'name' => 'get_node',
            'description' => 'Load a single Neos node by its identifier. By default returns only the identifier — use responseProperties to request additional fields.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'The UUID of the Neos node'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace name (default: live)'],
                    'includeChildren' => ['type' => 'boolean', 'description' => 'Include direct child nodes in the response (default: false)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node. Default: only "identifier". Add node property names (e.g. "title", "releaseDate") and/or meta-fields: "nodeType", "label", "name", "path", "workspace", "hidden". Use ["*"] to return all node properties and all meta-fields at once.',
                    ],
                ],
                'required' => ['nodeIdentifier'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $nodeIdentifier = $args['nodeIdentifier'] ?? null;
        if (empty($nodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier is required']];
        }

        $context = $this->contentContextFactory->create([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'dimensions' => $this->dimensions,
            'targetDimensions' => $this->targetDimensions,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $node = $context->getNodeByIdentifier($nodeIdentifier);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $nodeIdentifier]];
        }

        $responseProperties = $args['responseProperties'] ?? null;
        $result = $this->nodeSerializer->serializeNodeFiltered($node, $responseProperties);

        if (!empty($args['includeChildren'])) {
            $result['children'] = array_map(
                fn($child) => $this->nodeSerializer->serializeNodeFiltered($child, $responseProperties),
                $node->getChildNodes()
            );
        }

        return [['type' => 'text', 'text' => json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
