<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
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
     *   - Meta-fields: "nodeType", "label", "name", "path", "workspace", "hidden"
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
            'nodeType'  => fn() => $node->getNodeType()->getName(),
            'label'     => fn() => $node->getLabel(),
            'name'      => fn() => $node->getName(),
            'path'      => fn() => $node->getPath(),
            'workspace' => fn() => $node->getWorkspace()->getName(),
            'hidden'    => fn() => $node->isHidden(),
        ];
        foreach ($responseProperties as $field) {
            if (isset($metaFields[$field])) {
                $result[$field] = ($metaFields[$field])();
            } else {
                $result[$field] = $this->serializeValue($node->getProperty($field));
            }
        }
        return $result;
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
            return ['__type' => 'Image', 'identifier' => $this->persistenceManager->getIdentifierByObject($value)];
        }
        if ($value instanceof Asset) {
            return ['__type' => 'Asset', 'identifier' => $this->persistenceManager->getIdentifierByObject($value), 'filename' => $value->getResource()?->getFilename()];
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
