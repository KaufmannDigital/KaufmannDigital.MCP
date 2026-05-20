<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel\ElasticSearchQueryBuilder;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class FindByPropertyTool implements ToolInterface
{
    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    public function getDefinition(): array
    {
        return [
            'name' => 'find_by_property',
            'description' => 'Find Neos nodes with an exact property value match via Elasticsearch.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'propertyName' => ['type' => 'string', 'description' => 'Property name to match'],
                    'propertyValue' => ['description' => 'Value to match exactly'],
                    'nodeType' => ['type' => 'string', 'description' => 'Filter by node type (optional)'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace name (default: live)'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default: 10)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node. Default: only "identifier". Add node property names (e.g. "title", "releaseDate") and/or meta-fields: "nodeType", "label", "name", "path", "workspace", "hidden". Use ["*"] to return all node properties and all meta-fields at once.',
                    ],
                ],
                'required' => ['propertyName', 'propertyValue'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (empty($args['propertyName']) || !array_key_exists('propertyValue', $args)) {
            return [['type' => 'text', 'text' => 'Error: propertyName and propertyValue are required']];
        }

        $context = $this->contentContextFactory->create([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $sitesNode = $context->getNode('/sites');
        if ($sitesNode === null) {
            return [['type' => 'text', 'text' => 'Error: /sites node not found']];
        }

        /** @var ElasticSearchQueryBuilder $qb */
        $qb = $this->objectManager->get(ElasticSearchQueryBuilder::class);
        $qb->query($sitesNode)->exactMatch($args['propertyName'], $args['propertyValue'])->limit((int)($args['limit'] ?? 10));

        if (!empty($args['nodeType'])) {
            $qb->nodeType($args['nodeType']);
        }

        $responseProperties = $args['responseProperties'] ?? null;
        $result = $qb->fetch();
        $nodes = array_map(
            fn(NodeInterface $node) => $this->nodeSerializer->serializeNodeFiltered($node, $responseProperties),
            $result['nodes'] ?? []
        );

        return [['type' => 'text', 'text' => json_encode(
            ['total' => $qb->getTotalItems(), 'nodes' => $nodes],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
