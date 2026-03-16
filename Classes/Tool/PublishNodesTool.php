<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;

/**
 * @Flow\Scope("singleton")
 */
class PublishNodesTool implements ToolInterface
{
    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    public function getDefinition(): array
    {
        return [
            'name' => 'publish_nodes',
            'description' => 'Publish nodes from a user workspace to its base workspace (usually live).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifiers' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'UUIDs of the nodes to publish',
                    ],
                    'workspaceName' => ['type' => 'string', 'description' => 'Source workspace containing the unpublished nodes'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node in the response. Default: only "identifier". Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodeIdentifiers', 'workspaceName'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (empty($args['nodeIdentifiers']) || empty($args['workspaceName'])) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifiers and workspaceName are required']];
        }

        $context = $this->contentContextFactory->create([
            'workspaceName' => $args['workspaceName'],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $published = [];
        $errors = [];

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($context, $args, &$published, &$errors) {
            foreach ($args['nodeIdentifiers'] as $nodeIdentifier) {
                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node === null) {
                    $errors[] = 'Node not found: ' . $nodeIdentifier;
                    continue;
                }

                $this->publishingService->publishNode($node);
                $published[] = $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null);
            }
        });

        return [['type' => 'text', 'text' => json_encode(
            ['publishedCount' => count($published), 'published' => $published, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
