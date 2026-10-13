<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeFlagHandler;
use KaufmannDigital\MCP\Utility\NodePropertyWriter;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
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
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    /**
     * @Flow\Inject
     * @var NodePropertyWriter
     */
    protected $nodePropertyWriter;

    public function getDefinition(): array
    {
        return [
            'name' => 'batch_update_property',
            'description' => 'Set properties on multiple Neos nodes in a single call. The response reflects the persisted state (re-read after saving). ' . NodeFlagHandler::DESCRIPTION,
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
                                'properties' => ['type' => 'object', 'description' => 'Key-value map of properties to set (may include internal flags like "_hidden", "_hiddenInIndex")'],
                            ],
                            'required' => ['nodeIdentifier', 'properties'],
                        ],
                    ],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to write to (e.g. "live" or a user workspace name)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node in the response. Default: only "identifier". Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path", "hidden", "hiddenInIndex", "hiddenBeforeDateTime", "hiddenAfterDateTime", "parentIdentifier", "ancestors". Meta-fields are returned top-level, node properties in a nested "properties" object (prefix "properties." to request a property named like a meta-field, e.g. "properties.identifier"); unknown names are listed in "unknownProperties".',
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

        $updatedIdentifiers = [];
        $errors = [];
        $warnings = [];

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($context, $args, &$updatedIdentifiers, &$errors, &$warnings) {
            foreach ($args['nodes'] as $nodeArgs) {
                $nodeIdentifier = $nodeArgs['nodeIdentifier'] ?? null;
                $properties = $nodeArgs['properties'] ?? [];
                if (is_string($properties)) {
                    $properties = json_decode($properties, true) ?: [];
                }

                if (empty($nodeIdentifier) || empty($properties) || !is_array($properties)) {
                    $errors[] = 'Skipped entry: missing nodeIdentifier or properties';
                    continue;
                }

                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node === null) {
                    $errors[] = 'Node not found: ' . $nodeIdentifier;
                    continue;
                }

                try {
                    $warnings = array_merge($warnings, $this->nodePropertyWriter->setProperties($node, $properties));
                } catch (\InvalidArgumentException $e) {
                    $errors[] = 'Node ' . $nodeIdentifier . ': ' . $e->getMessage();
                    continue;
                }

                $updatedIdentifiers[] = $nodeIdentifier;
            }
        });

        $updated = [];
        foreach ($this->nodePropertyWriter->persistAndReload($context, array_values(array_unique($updatedIdentifiers))) as $nodeIdentifier => $node) {
            if ($node === null) {
                $errors[] = 'Node ' . $nodeIdentifier . ' could not be re-read after saving';
                continue;
            }
            $updated[] = $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null);
        }

        $result = ['updatedCount' => count($updated), 'updated' => $updated, 'errors' => $errors];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
