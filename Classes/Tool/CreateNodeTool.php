<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use KaufmannDigital\MCP\Utility\PropertyValueResolver;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class CreateNodeTool implements ToolInterface
{
    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

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
            'name' => 'create_node',
            'description' => 'Create a new Neos node under a given parent node. Default workspace is "live" — use a user workspace if you want to review before publishing.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'parentNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the parent node'],
                    'nodeType' => ['type' => 'string', 'description' => 'Node type name, e.g. KaufmannDigital.Nova.Magazine:Page.Magazine'],
                    'properties' => ['type' => 'object', 'description' => 'Optional key/value map of properties to set on the new node'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to create the node in (default: live)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include in the response. Default: only "identifier". Add node property names (e.g. "title", "uriPathSegment") and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['parentNodeIdentifier', 'nodeType'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $parentNodeIdentifier = $args['parentNodeIdentifier'] ?? null;
        $nodeTypeName = $args['nodeType'] ?? null;

        if (empty($parentNodeIdentifier) || empty($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Error: parentNodeIdentifier and nodeType are required']];
        }

        $workspaceName = $args['workspaceName'] ?? 'live';

        $context = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->dimensions,
            'targetDimensions' => $this->targetDimensions,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);

        $parentNode = $context->getNodeByIdentifier($parentNodeIdentifier);
        if ($parentNode === null) {
            return [['type' => 'text', 'text' => 'Parent node not found: ' . $parentNodeIdentifier]];
        }

        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            return [['type' => 'text', 'text' => 'Unknown node type: ' . $nodeTypeName]];
        }

        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);
        $nodeName = uniqid('node-');
        $properties = $args['properties'] ?? [];
        if (is_string($properties)) {
            $properties = json_decode($properties, true) ?: [];
        }

        $resolvedProperties = [];
        if (is_array($properties)) {
            foreach ($properties as $name => $value) {
                $resolvedProperties[$name] = $this->propertyValueResolver->resolve($name, $value, $nodeType);
            }
        }

        $newNode = null;
        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($parentNode, $nodeName, $nodeType, $resolvedProperties, &$newNode) {
            $newNode = $parentNode->createNode($nodeName, $nodeType);
            foreach ($resolvedProperties as $propertyName => $propertyValue) {
                $newNode->setProperty($propertyName, $propertyValue);
            }
        });

        $this->persistenceManager->persistAll();

        return [['type' => 'text', 'text' => json_encode(
            ['created' => $this->nodeSerializer->serializeNodeFiltered($newNode, $args['responseProperties'] ?? null)],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
