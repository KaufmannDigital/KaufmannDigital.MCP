<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\ImageInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeSerializer
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    public function serializeNode(NodeInterface $node, bool $includeChildren = true): array
    {
        $result = [
            'identifier' => $node->getIdentifier(),
            'nodeType' => $node->getNodeType()->getName(),
            'path' => $node->getPath(),
            'name' => $node->getName(),
            'depth' => $node->getDepth(),
            'hidden' => $node->isHidden(),
            'workspace' => $node->getWorkspace()->getName(),
            'dimensions' => $node->getDimensions(),
            'properties' => $this->serializeProperties($node),
        ];

        if ($includeChildren) {
            $result['children'] = $this->serializeChildren($node);
        }

        return $result;
    }

    public function serializeNodeSummary(NodeInterface $node): array
    {
        return [
            'identifier' => $node->getIdentifier(),
            'label' => $node->getLabel(),
            'name' => $node->getName(),
            'nodeType' => $node->getNodeType()->getName(),
            'path' => $node->getPath(),
            'properties' => $this->serializeProperties($node),
        ];
    }

    private function serializeProperties(NodeInterface $node): array
    {
        $properties = [];
        foreach ($node->getProperties() as $name => $value) {
            $properties[$name] = $this->serializeValue($value);
        }
        return $properties;
    }

    private function serializeChildren(NodeInterface $node): array
    {
        $children = [];
        foreach ($node->getChildNodes() as $child) {
            $children[] = $this->serializeNodeSummary($child);
        }
        return $children;
    }

    /**
     * Serialize a node returning only the requested fields.
     *
     * By default (responseProperties = null or []) only "identifier" is returned.
     * Pass an array of field names to include additional data:
     *   - Node property names: "title", "releaseDate", "uriPathSegment", etc.
     *   - Meta-fields: "nodeType", "label", "name", "path", "workspace", "hidden", "creationDate",
     *     "parentIdentifier", "ancestors"
     *
     * "*" expands to all node properties and all meta-fields except "ancestors" (which walks the
     * whole parent chain and would bloat list responses); request "ancestors" explicitly if needed.
     */
    public function serializeNodeFiltered(NodeInterface $node, array|string|null $responseProperties): array
    {
        if (is_string($responseProperties)) {
            $responseProperties = json_decode($responseProperties, true) ?: null;
        }
        $result = ['identifier' => $node->getIdentifier()];
        if (empty($responseProperties)) {
            return $result;
        }
        $metaFields = [
            'identifier'   => fn() => $node->getIdentifier(),
            'nodeType'     => fn() => $node->getNodeType()->getName(),
            'label'        => fn() => $node->getLabel(),
            'name'         => fn() => $node->getName(),
            'path'         => fn() => $node->getPath(),
            'workspace'    => fn() => $node->getWorkspace()->getName(),
            'hidden'       => fn() => $node->isHidden(),
            'creationDate' => fn() => $node->getCreationDateTime()?->format(\DateTimeInterface::ATOM),
            'parentIdentifier' => fn() => $node->getParent()?->getIdentifier(),
            'ancestors'    => fn() => $this->serializeAncestors($node),
        ];
        if (in_array('*', $responseProperties, true)) {
            foreach ($metaFields as $field => $resolver) {
                if ($field === 'ancestors' && !in_array('ancestors', $responseProperties, true)) {
                    continue;
                }
                $result[$field] = $resolver();
            }
            foreach ($node->getProperties() as $name => $value) {
                $result[$name] = $this->serializeValue($value);
            }
            return $result;
        }
        foreach ($responseProperties as $field) {
            if (isset($metaFields[$field])) {
                $result[$field] = ($metaFields[$field])();
            } else {
                $result[$field] = $this->serializeValue($node->getProperty($field));
            }
        }
        return $result;
    }

    /**
     * Ancestors of the node, ordered from the topmost ancestor (below the root node "/", i.e. "/sites")
     * down to the direct parent. Each entry contains identifier, nodeType and name.
     */
    private function serializeAncestors(NodeInterface $node): array
    {
        $ancestors = [];
        $parent = $node->getParent();
        while ($parent !== null && $parent->getPath() !== '/') {
            $ancestors[] = [
                'identifier' => $parent->getIdentifier(),
                'nodeType' => $parent->getNodeType()->getName(),
                'name' => $parent->getName(),
            ];
            $parent = $parent->getParent();
        }
        return array_reverse($ancestors);
    }

    public function serializeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof ImageInterface) {
            $resource = method_exists($value, 'getResource') ? $value->getResource() : null;
            $url = $resource ? $this->resourceManager->getPublicPersistentResourceUri($resource) : null;
            return ['__type' => 'Image', 'identifier' => $this->persistenceManager->getIdentifierByObject($value), 'url' => $url, 'filename' => $resource?->getFilename()];
        }
        if ($value instanceof Asset) {
            $resource = $value->getResource();
            $url = $resource ? $this->resourceManager->getPublicPersistentResourceUri($resource) : null;
            return ['__type' => 'Asset', 'identifier' => $this->persistenceManager->getIdentifierByObject($value), 'filename' => $resource?->getFilename(), 'url' => $url];
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeValue($v), $value);
        }
        if (is_object($value)) {
            return ['__type' => get_class($value), '__string' => method_exists($value, '__toString') ? (string)$value : null];
        }
        return $value;
    }
}
