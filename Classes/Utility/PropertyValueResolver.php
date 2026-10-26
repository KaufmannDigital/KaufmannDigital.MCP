<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Validation\Validator\NodeIdentifierValidator;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;

/**
 * @Flow\Scope("singleton")
 */
class PropertyValueResolver
{
    public function resolve(string $propertyName, mixed $value, NodeType $nodeType): mixed
    {
        $type = $nodeType->getPropertyType($propertyName);

        // Node references must be normalized explicitly: Neos' AbstractNodeData::setProperty()
        // silently replaces any non-array "references" value (e.g. a JSON-encoded string or a
        // single identifier) with [] and drops entries that are not valid node identifiers.
        if ($type === 'references') {
            return $this->resolveReferences($propertyName, $value);
        }
        if ($type === 'reference') {
            return $this->resolveReference($propertyName, $value);
        }

        $isAssetType = $this->isAssetType($type);

        // Clearing an asset property: some clients send null as the string "null". Passing that
        // string on would store it as is and break every later read of the property.
        if ($isAssetType && ($value === null || $value === '' || $value === 'null')) {
            return null;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($type === 'DateTime') {
            try {
                return new \DateTime($value);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid date/time value for property "%s": %s', $propertyName, $value),
                    1710000001,
                    $e
                );
            }
        }

        // Asset-like properties (Image, Asset, Document and their interfaces) are referenced
        // by asset identifier. Interfaces (e.g. ImageInterface) must be resolved via the
        // AssetRepository, since class_exists()/EntityManager::find() cannot handle them.
        if ($isAssetType) {
            $assetRepository = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Media\Domain\Repository\AssetRepository::class);
            $asset = $assetRepository->findByIdentifier($value);
            if ($asset === null && class_exists($type)) {
                /** @var \Doctrine\ORM\EntityManagerInterface $em */
                $em = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Doctrine\ORM\EntityManagerInterface::class);
                $asset = $em->find($type, $value);
            }
            // An unresolvable identifier must not be stored as plain string, the node property would be unreadable
            if ($asset === null) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid value for asset property "%s": no asset found for identifier "%s"', $propertyName, $value),
                    1710000006
                );
            }
            return $asset;
        }

        return $value;
    }

    /**
     * Asset-like property types (Asset, Image, Document, custom asset classes and their interfaces) are referenced
     * by asset identifier. ImageInterface does not extend AssetInterface, so both have to be checked.
     */
    private function isAssetType(string $type): bool
    {
        return is_a($type, AssetInterface::class, true) || is_a($type, ImageInterface::class, true);
    }

    /**
     * Normalizes a "references" value to a list of node identifiers.
     *
     * Accepts an array of identifiers (or nodes), a JSON-encoded array string, a single
     * identifier string, or null/"" (= no references). Throws on anything that Neos would
     * otherwise silently discard.
     *
     * @return string[]
     */
    private function resolveReferences(string $propertyName, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException(
                        sprintf('Invalid value for references property "%s": could not decode JSON array %s', $propertyName, $value),
                        1710000002
                    );
                }
                $value = $decoded;
            } else {
                $value = [$trimmed];
            }
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid value for references property "%s": expected an array of node identifiers, got %s', $propertyName, get_debug_type($value)),
                1710000003
            );
        }

        $identifiers = [];
        foreach (array_values($value) as $entry) {
            $identifiers[] = $this->normalizeNodeIdentifier($propertyName, $entry);
        }
        return $identifiers;
    }

    /**
     * Normalizes a "reference" value to a single node identifier (or null).
     */
    private function resolveReference(string $propertyName, mixed $value): ?string
    {
        if (is_array($value)) {
            if (count($value) > 1) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid value for reference property "%s": expected a single node identifier, got %d', $propertyName, count($value)),
                    1710000004
                );
            }
            $value = $value === [] ? null : reset($value);
        }
        if ($value === null || $value === '') {
            return null;
        }
        return $this->normalizeNodeIdentifier($propertyName, $value);
    }

    private function normalizeNodeIdentifier(string $propertyName, mixed $entry): string
    {
        if ($entry instanceof NodeInterface) {
            return $entry->getIdentifier();
        }
        if (is_string($entry) && preg_match(NodeIdentifierValidator::PATTERN_MATCH_NODE_IDENTIFIER, trim($entry)) === 1) {
            return trim($entry);
        }
        throw new \InvalidArgumentException(
            sprintf('Invalid node identifier for property "%s": %s', $propertyName, is_scalar($entry) ? (string)$entry : get_debug_type($entry)),
            1710000005
        );
    }
}
