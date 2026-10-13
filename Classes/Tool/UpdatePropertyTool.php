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
            'name' => 'update_property',
            'description' => 'Set a single property on a Neos node. The response contains the property as persisted (re-read after saving). ' . NodeFlagHandler::DESCRIPTION,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to update'],
                    'propertyName' => ['type' => 'string', 'description' => 'Property name to set, or an internal flag: "_hidden", "_hiddenInIndex", "_hiddenBeforeDateTime", "_hiddenAfterDateTime"'],
                    'propertyValue' => ['description' => 'New property value. For "references" properties pass an array of node identifiers, for "reference" properties a single node identifier.'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to write to (e.g. "live" or a user workspace name)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include in the response. Default: "identifier" and the updated property. Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path", "hidden", "hiddenInIndex", "hiddenBeforeDateTime", "hiddenAfterDateTime", "parentIdentifier", "ancestors". Meta-fields are returned top-level, node properties in a nested "properties" object (prefix "properties." to request a property named like a meta-field, e.g. "properties.identifier"); unknown names are listed in "unknownProperties".',
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

        $warnings = [];
        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        try {
            $securityContext->withoutAuthorizationChecks(function () use ($node, $args, &$warnings) {
                $warnings = $this->nodePropertyWriter->setProperties($node, [$args['propertyName'] => $args['propertyValue'] ?? null]);
            });
        } catch (\InvalidArgumentException $e) {
            return [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]];
        }

        $node = $this->nodePropertyWriter->persistAndReload($context, [$args['nodeIdentifier']])[$args['nodeIdentifier']];
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Error: node ' . $args['nodeIdentifier'] . ' could not be re-read after saving']];
        }

        $responseProperties = $args['responseProperties'] ?? null;
        if (is_string($responseProperties)) {
            $responseProperties = json_decode($responseProperties, true) ?: null;
        }
        $responseProperties = array_values(array_unique(array_merge((array)($responseProperties ?? []), [$args['propertyName']])));

        $result = ['updated' => $this->nodeSerializer->serializeNodeFiltered($node, $responseProperties)];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
