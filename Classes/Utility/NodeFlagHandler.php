<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Maps internal node flags ("_"-prefixed names like "_hidden") to the corresponding
 * NodeInterface setters instead of storing them as regular node properties.
 *
 * @Flow\Scope("singleton")
 */
class NodeFlagHandler
{
    public const BOOLEAN_FLAGS = ['_hidden', '_hiddenInIndex'];
    public const DATETIME_FLAGS = ['_hiddenBeforeDateTime', '_hiddenAfterDateTime'];

    /**
     * Short description of the supported flags, for use in tool descriptions.
     */
    public const DESCRIPTION = 'Internal node flags can be set like properties: "_hidden" (bool, hide node), "_hiddenInIndex" (bool, hide in menus), "_hiddenBeforeDateTime" / "_hiddenAfterDateTime" (ISO 8601 string or null). Other "_"-prefixed names are rejected.';

    /**
     * Whether the given name is an internal ("_"-prefixed) name that must not be written via setProperty().
     */
    public function isInternalName(string $name): bool
    {
        return str_starts_with($name, '_');
    }

    /**
     * Validate and convert a flag value. Throws \InvalidArgumentException for unsupported
     * flag names or invalid values.
     */
    public function normalize(string $name, mixed $value): bool|\DateTimeInterface|null
    {
        if (in_array($name, self::BOOLEAN_FLAGS, true)) {
            $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null || $value === null) {
                throw new \InvalidArgumentException(sprintf('Invalid value for "%s": expected boolean', $name), 1760000001);
            }
            return $bool;
        }

        if (in_array($name, self::DATETIME_FLAGS, true)) {
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('Invalid value for "%s": expected ISO 8601 date/time string or null', $name), 1760000002);
            }
            try {
                // The flags are stored in a datetime column without timezone, so convert to the
                // server timezone to keep the point in time intact.
                return (new \DateTime($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(sprintf('Invalid date/time value for "%s": %s', $name, $value), 1760000003, $e);
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unsupported internal property "%s". Supported: %s',
            $name,
            implode(', ', array_merge(self::BOOLEAN_FLAGS, self::DATETIME_FLAGS))
        ), 1760000004);
    }

    /**
     * Apply an already normalized flag value (see normalize()) to the node.
     */
    public function apply(NodeInterface $node, string $name, bool|\DateTimeInterface|null $value): void
    {
        match ($name) {
            '_hidden' => $node->setHidden($value),
            '_hiddenInIndex' => $node->setHiddenInIndex($value),
            '_hiddenBeforeDateTime' => $node->setHiddenBeforeDateTime($value),
            '_hiddenAfterDateTime' => $node->setHiddenAfterDateTime($value),
        };
    }
}
