<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use KaufmannDigital\MCP\Utility\PropertyValueResolver;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class UpdatePropertyTool implements ToolInterface
{
    use DimensionOverrideTrait;

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
     * @var PropertyValueResolver
     */
    protected $propertyValueResolver;

    public function getDefinition(): array
    {
        return [
            'name' => 'update_property',
            'description' => 'Set a single property on a Neos node.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to update'],
                    'propertyName' => ['type' => 'string', 'description' => 'Property name to set'],
                    'propertyValue' => ['description' => 'New property value'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to write to (e.g. "live" or a user workspace name)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include in the response. Default: only "identifier". Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodeIdentifier', 'propertyName', 'propertyValue', 'workspaceName'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (empty($args['nodeIdentifier']) || empty($args['propertyName']) || empty($args['workspaceName'])) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier, propertyName and workspaceName are required']];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $node = $context->getNodeByIdentifier($args['nodeIdentifier']);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $args['nodeIdentifier']]];
        }

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($node, $args) {
            $value = $this->propertyValueResolver->resolve($args['propertyName'], $args['propertyValue'], $node->getNodeType());
            $node->setProperty($args['propertyName'], $value);
        });
        $this->persistenceManager->persistAll();

        return [['type' => 'text', 'text' => json_encode(
            ['updated' => $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null)],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
