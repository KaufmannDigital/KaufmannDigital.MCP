<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;

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
            return new \DateTime($value);
        }

        if (str_contains($type, 'Neos\\Media\\Domain\\Model\\')) {
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Doctrine\ORM\EntityManagerInterface::class);
            return $em->find(Asset::class, $value) ?? $value;
        }

        return $value;
    }
}