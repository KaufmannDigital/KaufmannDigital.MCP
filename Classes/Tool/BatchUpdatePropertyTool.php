<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use KaufmannDigital\MCP\Utility\PropertyAdjustmentDetector;
use KaufmannDigital\MCP\Utility\PropertyValueResolver;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class BatchUpdatePropertyTool implements ToolInterface
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

    /**
     * @Flow\Inject
     * @var PropertyAdjustmentDetector
     */
    protected $propertyAdjustmentDetector;

    public function getDefinition(): array
    {
        return [
            'name' => 'batch_update_property',
            'description' => 'Set properties on multiple Neos nodes in a single call. The response contains "warnings" (with nodeIdentifier) if a stored value differs from the requested one (e.g. uriPathSegment made unique).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodes' => [
                        'type' => 'array',
                        'description' => 'List of nodes to update',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'nodeIdentifier' => ['type' => 'string'],
                                'properties' => ['type' => 'object', 'description' => 'Key-value map of properties to set'],
                            ],
                            'required' => ['nodeIdentifier', 'properties'],
                        ],
                    ],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to write to (e.g. "live" or a user workspace name)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node in the response. Default: only "identifier". Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodes', 'workspaceName'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (is_string($args['nodes'] ?? null)) {
            $args['nodes'] = json_decode($args['nodes'], true) ?: [];
        }
        if (empty($args['nodes']) || empty($args['workspaceName'])) {
            return [['type' => 'text', 'text' => 'Error: nodes and workspaceName are required']];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $updated = [];
        $errors = [];
        $warnings = [];

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($context, $args, &$updated, &$errors, &$warnings) {
            foreach ($args['nodes'] as $nodeArgs) {
                $nodeIdentifier = $nodeArgs['nodeIdentifier'] ?? null;
                $properties = $nodeArgs['properties'] ?? [];

                if (empty($nodeIdentifier) || empty($properties)) {
                    $errors[] = 'Skipped entry: missing nodeIdentifier or properties';
                    continue;
                }

                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node === null) {
                    $errors[] = 'Node not found: ' . $nodeIdentifier;
                    continue;
                }

                $resolvedProperties = [];
                foreach ($properties as $name => $value) {
                    $value = $this->propertyValueResolver->resolve($name, $value, $node->getNodeType());
                    $node->setProperty($name, $value);
                    $resolvedProperties[$name] = $value;
                }
                foreach ($this->propertyAdjustmentDetector->detect($node, $resolvedProperties) as $warning) {
                    $warnings[] = ['nodeIdentifier' => $nodeIdentifier] + $warning;
                }

                $updated[] = $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null);
            }
        });

        $this->persistenceManager->persistAll();

        $response = ['updatedCount' => count($updated), 'updated' => $updated, 'errors' => $errors];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        return [['type' => 'text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
