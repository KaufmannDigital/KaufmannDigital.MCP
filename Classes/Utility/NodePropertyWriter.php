<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Shared write path for update_property and batch_update_property:
 * resolve values (incl. internal flags like "_hidden"), set them on the node, persist and re-read the persisted state.
 *
 * @Flow\Scope("singleton")
 */
class NodePropertyWriter
{
    /**
     * @Flow\Inject
     * @var PropertyValueResolver
     */
    protected $propertyValueResolver;

    /**
     * @Flow\Inject
     * @var NodeFlagHandler
     */
    protected $nodeFlagHandler;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Resolves all given property values first (so an invalid value leaves the node untouched)
     * and then sets them on the node. Internal flags ("_hidden", ...) are applied via NodeFlagHandler. Must be called inside withoutAuthorizationChecks().
     *
     * @param array<string, mixed> $properties
     * @return string[] Warnings, e.g. for referenced nodes that are not visible in the node's context
     * @throws \InvalidArgumentException if a value cannot be converted to the property's type
     */
    public function setProperties(NodeInterface $node, array $properties): array
    {
        $nodeType = $node->getNodeType();
        $resolved = [];
        $flags = [];
        foreach ($properties as $name => $value) {
            if ($this->nodeFlagHandler->isInternalName((string)$name)) {
                $flags[$name] = $this->nodeFlagHandler->normalize((string)$name, $value);
                continue;
            }
            $resolved[$name] = $this->propertyValueResolver->resolve((string)$name, $value, $nodeType);
        }

        foreach ($flags as $name => $value) {
            $this->nodeFlagHandler->apply($node, (string)$name, $value);
        }

        $warnings = [];
        foreach ($resolved as $name => $value) {
            $node->setProperty((string)$name, $value);
            $warnings = array_merge($warnings, $this->unresolvableReferenceWarnings($node, (string)$name, $value));
        }
        return $warnings;
    }

    /**
     * Persists all pending changes and returns the given nodes freshly loaded from the
     * database, so responses reflect what was actually stored (not the in-memory state).
     *
     * @param string[] $nodeIdentifiers
     * @return array<string, NodeInterface|null> keyed by node identifier
     */
    public function persistAndReload(Context $context, array $nodeIdentifiers): array
    {
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
        $context->getFirstLevelNodeCache()->flush();

        $nodes = [];
        foreach ($nodeIdentifiers as $nodeIdentifier) {
            $nodes[$nodeIdentifier] = $context->getNodeByIdentifier($nodeIdentifier);
        }
        return $nodes;
    }

    /**
     * @return string[]
     */
    private function unresolvableReferenceWarnings(NodeInterface $node, string $propertyName, mixed $value): array
    {
        $type = $node->getNodeType()->getPropertyType($propertyName);
        if (($type !== 'references' && $type !== 'reference') || empty($value)) {
            return [];
        }

        $warnings = [];
        foreach ((array)$value as $identifier) {
            if ($node->getContext()->getNodeByIdentifier($identifier) === null) {
                $warnings[] = sprintf(
                    'Property "%s" of node %s: referenced node %s does not exist in workspace "%s" / these dimensions; it is stored but will not be returned when reading the property.',
                    $propertyName,
                    $node->getIdentifier(),
                    $identifier,
                    $node->getContext()->getWorkspaceName()
                );
            }
        }
        return $warnings;
    }
}
