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

        if (str_contains($type, 'Neos\\Media\\Domain\\Model\\') && class_exists($type)) {
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Doctrine\ORM\EntityManagerInterface::class);
            return $em->find($type, $value) ?? $value;
        }

        return $value;
    }
}