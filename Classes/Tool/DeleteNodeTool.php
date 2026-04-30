<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;

/**
 * @Flow\Scope("singleton")
 */
class DeleteNodeTool implements ToolInterface
{
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
     * @var PublishingService
     */
    protected $publishingService;

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
            'name' => 'delete_node',
            'description' => 'Delete a Neos node by identifier. Supports optional recursive deletion and optional publish from user workspace.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to delete'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to delete in (default: live)'],
                    'publishAfterDelete' => ['type' => 'boolean', 'description' => 'Publish the deletion from a user workspace to its base workspace (default: false)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include in deleted node response. Default: only "identifier".',
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

        $workspaceName = $args['workspaceName'] ?? 'live';
        $publishAfterDelete = (bool)($args['publishAfterDelete'] ?? false);

        $context = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->dimensions,
            'targetDimensions' => $this->targetDimensions,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $node = $context->getNodeByIdentifier($nodeIdentifier);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $nodeIdentifier]];
        }

        $serializedNode = $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null);

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($node, $publishAfterDelete, $workspaceName) {
            $node->remove();

            if ($publishAfterDelete && $workspaceName !== 'live') {
                $this->publishingService->publishNode($node);
            }
        });

        $this->persistenceManager->persistAll();

        return [[
            'type' => 'text',
            'text' => json_encode(
                [
                    'deleted' => true,
                    'workspace' => $workspaceName,
                    'published' => $publishAfterDelete && $workspaceName !== 'live',
                    'node' => $serializedNode,
                ],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ),
        ]];
    }
}
