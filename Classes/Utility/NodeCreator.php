<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeServiceInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;

/**
 * Shared node creation logic for create_node and batch_create_nodes.
 *
 * Must be called inside SecurityContext::withoutAuthorizationChecks(); persisting
 * is left to the caller.
 *
 * @Flow\Scope("singleton")
 */
class NodeCreator
{
    public const POSITIONS = ['into', 'before', 'after'];

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeServiceInterface
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var PropertyValueResolver
     */
    protected $propertyValueResolver;

    /**
     * @Flow\Inject
     * @var NodeUriPathSegmentGenerator
     */
    protected $nodeUriPathSegmentGenerator;

    /**
     * @Flow\Inject
     * @var PropertyAdjustmentDetector
     */
    protected $propertyAdjustmentDetector;

    /**
     * Creates a node relative to $referenceNode.
     *
     * @param NodeInterface $referenceNode The parent node (position "into") or the sibling node (position "before"/"after")
     * @param string $position "into" (append as last child), "before" or "after" (sibling of $referenceNode)
     * @param string $nodeTypeName Node type name of the new node
     * @param array<string, mixed> $properties Raw property values (resolved via PropertyValueResolver)
     * @param string|null $nodeName Requested node name; null generates a random "node-…" name
     * @param bool $applyDefaults false removes NodeType default values (also on tethered child nodes) that were not explicitly requested
     * @return array{node: NodeInterface, warnings: array<int, array<string, mixed>>}
     * @throws \InvalidArgumentException on invalid input
     */
    public function create(
        NodeInterface $referenceNode,
        string $position,
        string $nodeTypeName,
        array $properties = [],
        ?string $nodeName = null,
        bool $applyDefaults = true
    ): array {
        if (!in_array($position, self::POSITIONS, true)) {
            throw new \InvalidArgumentException('Invalid position "' . $position . '" — allowed: into, before, after');
        }
        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            throw new \InvalidArgumentException('Unknown node type: ' . $nodeTypeName);
        }
        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);

        $parentNode = $position === 'into' ? $referenceNode : $referenceNode->getParent();
        if ($parentNode === null) {
            throw new \InvalidArgumentException('Cannot create a node ' . $position . ' a node without parent');
        }

        // Resolve values first, so invalid input fails before anything is written
        $resolvedProperties = [];
        foreach ($properties as $name => $value) {
            $resolvedProperties[(string)$name] = $this->propertyValueResolver->resolve((string)$name, $value, $nodeType);
        }

        $warnings = [];
        $requestedNodeName = ($nodeName !== null && $nodeName !== '') ? $nodeName : null;
        $finalNodeName = $this->nodeService->generateUniqueNodeName($parentNode->getPath(), $requestedNodeName);
        if ($requestedNodeName !== null && $finalNodeName !== $requestedNodeName) {
            $warnings[] = $this->propertyAdjustmentDetector->buildWarning(
                'nodeName',
                $requestedNodeName,
                $finalNodeName,
                'Requested node name was invalid or already taken below the parent; a different name was used.'
            );
        }

        $newNode = $parentNode->createNode($finalNodeName, $nodeType);
        if ($position === 'before') {
            $newNode->moveBefore($referenceNode);
        } elseif ($position === 'after') {
            $newNode->moveAfter($referenceNode);
        }

        if (!$applyDefaults) {
            $this->removeDefaultValues($newNode, array_keys($resolvedProperties));
        }

        foreach ($resolvedProperties as $propertyName => $propertyValue) {
            $newNode->setProperty($propertyName, $propertyValue);
        }

        if ($newNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            if (empty($properties['uriPathSegment'])) {
                $newNode->setProperty('uriPathSegment', $this->nodeUriPathSegmentGenerator->generateUriPathSegment($newNode));
            }
            NodeUriPathSegmentGenerator::setUniqueUriPathSegment($newNode);
        }

        $warnings = array_merge($warnings, $this->propertyAdjustmentDetector->detect($newNode, $resolvedProperties));

        return ['node' => $newNode, 'warnings' => $warnings];
    }

    /**
     * Removes the NodeType default values Neos applied in createNode() from the node and,
     * recursively, from its tethered (auto-created) child nodes. Internal properties
     * ("_hidden" etc.) and explicitly requested properties are kept.
     *
     * @param string[] $keepPropertyNames
     */
    protected function removeDefaultValues(NodeInterface $node, array $keepPropertyNames = []): void
    {
        foreach (array_keys($node->getNodeType()->getDefaultValuesForProperties()) as $propertyName) {
            $propertyName = (string)$propertyName;
            if (str_starts_with($propertyName, '_') || in_array($propertyName, $keepPropertyNames, true)) {
                continue;
            }
            $node->removeProperty($propertyName);
        }
        foreach ($node->getChildNodes() as $childNode) {
            if ($childNode->isAutoCreated()) {
                $this->removeDefaultValues($childNode);
            }
        }
    }

    /**
     * Parses an optional boolean argument that may arrive as bool, int or string.
     */
    public static function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $parsed ?? $default;
        }
        return (bool)$value;
    }
}
