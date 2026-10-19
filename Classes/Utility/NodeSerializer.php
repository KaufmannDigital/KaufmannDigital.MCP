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
            'hiddenInIndex' => $node->isHiddenInIndex(),
            'hiddenBeforeDateTime' => $node->getHiddenBeforeDateTime()?->format(\DateTimeInterface::ATOM),
            'hiddenAfterDateTime' => $node->getHiddenAfterDateTime()?->format(\DateTimeInterface::ATOM),
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
        foreach ($node->getPropertyNames() as $name) {
            $properties[$name] = $this->serializeProperty($node, (string)$name);
        }
        return $properties;
    }

    /**
     * Serialize a single node property. Errors are caught per property so that one failing
     * property does not abort the serialization of the whole node (or node list).
     */
    private function serializeProperty(NodeInterface $node, string $propertyName): mixed
    {
        try {
            return $this->serializeValue($node->getProperty($propertyName));
        } catch (\Throwable $e) {
            return ['__type' => 'Error', 'error' => $e->getMessage()];
        }
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
     *   - Meta-fields (returned top-level): "nodeType", "label", "name", "path", "workspace", "hidden", "hiddenInIndex",
     *     "hiddenBeforeDateTime", "hiddenAfterDateTime" (the flags are also accessible with "_" prefix, e.g. "_hidden"),
     *     "creationDate",     *     "parentIdentifier", "ancestors"
     *   - Node property names (returned in a nested "properties" object): "title", "releaseDate", etc.
     *     Prefix with "properties." to request a property whose name collides with a meta-field (e.g. "properties.identifier").
     *   - "*" returns all meta-fields and all node properties, except "ancestors" (which walks the
     *     whole parent chain and would bloat list responses); request "ancestors" explicitly if needed.
     * Requested names that are neither meta-fields nor declared/stored node properties are listed in "unknownProperties".
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
            'hiddenInIndex' => fn() => $node->isHiddenInIndex(),
            'hiddenBeforeDateTime' => fn() => $node->getHiddenBeforeDateTime()?->format(\DateTimeInterface::ATOM),
            'hiddenAfterDateTime' => fn() => $node->getHiddenAfterDateTime()?->format(\DateTimeInterface::ATOM),
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
            $result['properties'] = $this->serializeProperties($node);
            return $result;
        }
        $properties = [];
        $unknownProperties = [];
        $declaredProperties = $node->getNodeType()->getProperties();
        foreach ($responseProperties as $field) {
            $field = (string)$field;
            if (isset($metaFields[$field])) {
                $result[$field] = ($metaFields[$field])();
                continue;
            }
            if (str_starts_with($field, '_') && isset($metaFields[substr($field, 1)])) {
                // Internal flag names as used by the write tools, e.g. "_hidden", "_hiddenInIndex"
                $result[$field] = ($metaFields[substr($field, 1)])();
                continue;
            }
            $propertyName = str_starts_with($field, 'properties.') ? substr($field, strlen('properties.')) : $field;
            if (array_key_exists($propertyName, $declaredProperties) || $node->hasProperty($propertyName)) {
                $properties[$propertyName] = $this->serializeProperty($node, $propertyName);
            } else {
                $unknownProperties[] = $field;
            }
        }
        if ($properties !== []) {
            $result['properties'] = $properties;
        }
        if ($unknownProperties !== []) {
            $result['unknownProperties'] = $unknownProperties;
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
        if ($value instanceof NodeInterface) {
            return ['__type' => 'Node', 'identifier' => $value->getIdentifier(), 'path' => $value->getPath(), 'nodeType' => $value->getNodeType()->getName()];
        }
        if ($value instanceof ImageInterface) {
            return $this->serializeAsset($value, 'Image');
        }
        if ($value instanceof Asset) {
            return $this->serializeAsset($value, 'Asset');
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->serializeValue($v), $value);
        }
        if (is_object($value)) {
            return ['__type' => get_class($value), '__string' => method_exists($value, '__toString') ? (string)$value : null];
        }
        return $value;
    }

    /**
     * Serialize an image or asset. Errors while resolving the resource/URL are reported in an "error" field
     * instead of failing the whole request.
     */
    private function serializeAsset(object $asset, string $type): array
    {
        $result = [
            '__type' => $type,
            'identifier' => $this->persistenceManager->getIdentifierByObject($asset),
            'filename' => null,
            'url' => null,
        ];
        try {
            $resource = method_exists($asset, 'getResource') ? $asset->getResource() : null;
            $result['filename'] = $resource?->getFilename();
            $result['url'] = $resource ? ($this->resourceManager->getPublicPersistentResourceUri($resource) ?: null) : null;
        } catch (\Throwable $e) {
            $result['url'] = null;
            $result['error'] = $e->getMessage();
        }
        return $result;
    }
}
