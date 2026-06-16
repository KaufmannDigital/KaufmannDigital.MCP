<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class PropertyValueResolver
{
    public function resolve(string $propertyName, mixed $value, NodeType $nodeType): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $type = $nodeType->getPropertyType($propertyName);

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
        if (str_contains($type, 'Neos\\Media\\Domain\\Model\\') && (class_exists($type) || interface_exists($type))) {
            $assetRepository = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Media\Domain\Repository\AssetRepository::class);
            $asset = $assetRepository->findByIdentifier($value);
            if ($asset !== null) {
                return $asset;
            }
            if (class_exists($type)) {
                /** @var \Doctrine\ORM\EntityManagerInterface $em */
                $em = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Doctrine\ORM\EntityManagerInterface::class);
                return $em->find($type, $value) ?? $value;
            }
            return $value;
        }

        return $value;
    }
}