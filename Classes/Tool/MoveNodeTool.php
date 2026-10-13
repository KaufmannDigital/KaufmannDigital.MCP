<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeCreator;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use KaufmannDigital\MCP\Utility\PropertyAdjustmentDetector;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class MoveNodeTool implements ToolInterface
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
            'name' => 'move_node',
            'description' => 'Move a Neos node including its subtree into, before or after a target node (also used to reorder siblings). Identifiers stay the same. For document nodes moved to a new parent Neos keeps the uriPathSegment unique — the response contains "warnings" if the node name or uriPathSegment changed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to move'],
                    'targetNodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the target node: the new parent (position "into") or the sibling (position "before"/"after")'],
                    'position' => ['type' => 'string', 'enum' => NodeCreator::POSITIONS, 'description' => '"into" (default): append as last child of the target. "before"/"after": place directly before/after the target sibling.'],
                    'nodeName' => ['type' => 'string', 'description' => 'Optional new node name. Default: keep the current name (a random name is used if it is already taken below the new parent, with a warning).'],
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to move in (default: live)'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields of the moved node to include in the response. Default: only "identifier". Add node property names and/or meta-fields: "nodeType", "label", "name", "path".',
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
        if ($targetNode === $node || $targetNode->getIdentifier() === $node->getIdentifier()) {
            return [['type' => 'text', 'text' => 'Error: a node cannot be moved relative to itself']];
        }

        $newParentNode = $position === 'into' ? $targetNode : $targetNode->getParent();
        if ($newParentNode === null) {
            return [['type' => 'text', 'text' => 'Error: target node has no parent, cannot move ' . $position . ' it']];
        }
        if ($newParentNode->getPath() === $node->getPath() || str_starts_with($newParentNode->getPath(), $node->getPath() . '/')) {
            return [['type' => 'text', 'text' => 'Error: a node cannot be moved into itself or one of its descendants']];
        }

        $oldName = $node->getName();
        $requestedNodeName = isset($args['nodeName']) && $args['nodeName'] !== '' ? (string)$args['nodeName'] : null;
        $idealNodeName = $requestedNodeName ?? $oldName;
        $sameParent = $newParentNode->getPath() === $node->getParentPath();

        $warnings = [];
        $newName = $idealNodeName;
        if (!$sameParent || $idealNodeName !== $oldName) {
            $designatedPath = NodePaths::addNodePathSegment($newParentNode->getPath(), $idealNodeName);
            if ($this->nodeService->nodePathAvailableForNode($designatedPath, $node) === false) {
                $newName = $this->nodeService->generateUniqueNodeName($newParentNode->getPath(), $idealNodeName);
            }
        }
        if ($newName !== $idealNodeName) {
            $warnings[] = $this->propertyAdjustmentDetector->buildWarning(
                'nodeName',
                $idealNodeName,
                $newName,
                'Node name is invalid or already taken below the new parent; a different name was used.'
            );
        }

        $isDocument = $node->getNodeType()->isOfType('Neos.Neos:Document');
        $oldUriPathSegment = $isDocument ? $node->getProperty('uriPathSegment') : null;

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        try {
            $securityContext->withoutAuthorizationChecks(function () use ($node, $targetNode, $position, $newName, $oldName, $sameParent) {
                if ($sameParent && $newName !== $oldName) {
                    // Rename in place first — move* would otherwise skip the rename when the parent does not change
                    $node->setName($newName);
                }
                $nameArgument = (!$sameParent && $newName !== $oldName) ? $newName : null;
                if ($position === 'into' && $sameParent) {
                    // Node::moveInto() is a no-op for the current parent — append behind the last sibling instead
                    $siblings = $targetNode->getChildNodes();
                    $lastChildNode = $siblings !== [] ? end($siblings) : null;
                    if ($lastChildNode !== null && $lastChildNode->getIdentifier() !== $node->getIdentifier()) {
                        $node->moveAfter($lastChildNode);
                    }
                    return;
                }
                match ($position) {
                    'before' => $node->moveBefore($targetNode, $nameArgument),
                    'after' => $node->moveAfter($targetNode, $nameArgument),
                    default => $node->moveInto($targetNode, $nameArgument),
                };
            });
        } catch (\Neos\ContentRepository\Exception $e) {
            return [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]];
        }

        $this->persistenceManager->persistAll();

        if ($isDocument) {
            $newUriPathSegment = $node->getProperty('uriPathSegment');
            if ($newUriPathSegment !== $oldUriPathSegment) {
                $warnings[] = $this->propertyAdjustmentDetector->buildWarning(
                    'uriPathSegment',
                    $oldUriPathSegment,
                    $newUriPathSegment,
                    'uriPathSegment was made unique among the new siblings by Neos.'
                );
            }
        }

        $response = ['moved' => $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null)];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        return [['type' => 'text', 'text' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
