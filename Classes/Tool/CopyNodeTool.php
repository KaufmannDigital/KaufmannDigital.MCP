<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use KaufmannDigital\MCP\Utility\PropertyAdjustmentDetector;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class CopyNodeTool implements ToolInterface
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
     * @var NodeServiceInterface
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var PropertyAdjustmentDetector
     */
    protected $propertyAdjustmentDetector;

    public function getDefinition(): array
    {
        return [
            'name' => 'copy_node',
            'description' => 'Copy a Neos node including its whole subtree (child nodes, properties, internal flags like hidden, order and node names are kept) into, before or after a target node. The copy gets new identifiers. For document nodes Neos keeps the uriPathSegment unique among the new siblings — the response contains "warnings" if the node name or uriPathSegment differ from the source/requested values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to copy'],
                    'targetNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the target node: the new parent (position "into") or the sibling (position "before"/"after")'],
                    'position' => ['type' => 'string', 'enum' => NodeCreator::POSITIONS, 'description' => '"into" (default): append as last child of the target. "before"/"after": insert as sibling directly before/after the target.'],
                    'nodeName' => ['type' => 'string', 'description' => 'Optional node name of the copy. Default: the source node\'s name. If invalid or already taken below the new parent, a random name is used and a warning is returned.'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to copy in (default: live)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields of the copied (root) node to include in the response. Default: only "identifier". Add node property names and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodeIdentifier', 'targetNodeIdentifier'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $nodeIdentifier = $args['nodeIdentifier'] ?? null;
        $targetNodeIdentifier = $args['targetNodeIdentifier'] ?? null;
        $position = $args['position'] ?? 'into';

        if (empty($nodeIdentifier) || empty($targetNodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier and targetNodeIdentifier are required']];
        }
        if (!in_array($position, NodeCreator::POSITIONS, true)) {
            return [['type' => 'text', 'text' => 'Error: position must be one of: ' . implode(', ', NodeCreator::POSITIONS)]];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $node = $context->getNodeByIdentifier($nodeIdentifier);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $nodeIdentifier]];
        }
        $targetNode = $context->getNodeByIdentifier($targetNodeIdentifier);
        if ($targetNode === null) {
            return [['type' => 'text', 'text' => 'Target node not found: ' . $targetNodeIdentifier]];
        }

        $newParentNode = $position === 'into' ? $targetNode : $targetNode->getParent();
        if ($newParentNode === null) {
            return [['type' => 'text', 'text' => 'Error: target node has no parent, cannot copy ' . $position . ' it']];
        }
        if ($newParentNode->getPath() === $node->getPath() || str_starts_with($newParentNode->getPath(), $node->getPath() . '/')) {
            return [['type' => 'text', 'text' => 'Error: a node cannot be copied into itself or one of its descendants']];
        }

        $requestedNodeName = isset($args['nodeName']) && $args['nodeName'] !== '' ? (string)$args['nodeName'] : null;
        $idealNodeName = $requestedNodeName ?? $node->getName();
        $nodeName = $this->nodeService->generateUniqueNodeName($newParentNode->getPath(), $idealNodeName);

        $warnings = [];
        if ($nodeName !== $idealNodeName) {
            $warnings[] = $this->propertyAdjustmentDetector->buildWarning(
                'nodeName',
                $idealNodeName,
                $nodeName,
                $requestedNodeName !== null
                    ? 'Requested node name was invalid or already taken below the new parent; a different name was used.'
                    : 'The source node name is already taken below the new parent; a different name was used.'
            );
        }

        $copiedNode = null;
        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        try {
            $securityContext->withoutAuthorizationChecks(function () use ($node, $targetNode, $position, $nodeName, &$copiedNode) {
                $copiedNode = match ($position) {
                    'before' => $node->copyBefore($targetNode, $nodeName),
                    'after' => $node->copyAfter($targetNode, $nodeName),
                    default => $this->copyIntoAsLastChild($node, $targetNode, $nodeName),
                };
            });
        } catch (\Neos\ContentRepository\Exception $e) {
            return [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]];
        }

        $this->persistenceManager->persistAll();

        if ($copiedNode instanceof NodeInterface && $node->getNodeType()->isOfType('Neos.Neos:Document')) {
            $sourceSegment = $node->getProperty('uriPathSegment');
            $copiedSegment = $copiedNode->getProperty('uriPathSegment');
            if ($sourceSegment !== $copiedSegment) {
                $warnings[] = $this->propertyAdjustmentDetector->buildWarning(
                    'uriPathSegment',
                    $sourceSegment,
                    $copiedSegment,
                    'uriPathSegment was made unique among the new siblings by Neos.'
                );
            }
        }

        $response = ['copied' => $this->nodeSerializer->serializeNodeFiltered($copiedNode, $args['responseProperties'] ?? null)];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        return [['type' => 'text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }

    /**
     * Node::copyInto() keeps the source's sorting index, so the copy may end up anywhere
     * among the new siblings — move it behind the current last child to append it.
     */
    protected function copyIntoAsLastChild(NodeInterface $node, NodeInterface $targetNode, string $nodeName): NodeInterface
    {
        $existingChildNodes = $targetNode->getChildNodes();
        $lastChildNode = $existingChildNodes !== [] ? end($existingChildNodes) : null;

        $copiedNode = $node->copyInto($targetNode, $nodeName);
        if ($lastChildNode instanceof NodeInterface) {
            $copiedNode->moveAfter($lastChildNode);
        }
        return $copiedNode;
    }
}
