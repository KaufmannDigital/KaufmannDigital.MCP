<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Detects write side effects: properties whose stored value differs from the
 * value the caller requested (e.g. Neos making a "uriPathSegment" unique by
 * appending "-1"), so tools can report them as warnings instead of silently
 * returning success.
 *
 * Only scalar values are compared — object values (assets, DateTimes,
 * references) are converted by the ContentRepository and would otherwise
 * produce false positives.
 *
 * @Flow\Scope("singleton")
 */
class PropertyAdjustmentDetector
{
    /**
     * @param NodeInterface $node The node after the write
     * @param array<string, mixed> $requestedValues Property name => value as passed to setProperty()
     * @return array<int, array<string, mixed>> List of warnings (empty if everything was stored as requested)
     */
    public function detect(NodeInterface $node, array $requestedValues): array
    {
        $warnings = [];
        foreach ($requestedValues as $propertyName => $requestedValue) {
            $propertyName = (string)$propertyName;
            if (!is_scalar($requestedValue) || str_starts_with($propertyName, '_')) {
                continue;
            }
            $storedValue = $node->getProperty($propertyName);
            if ($storedValue === $requestedValue) {
                continue;
            }
            if ($storedValue !== null && !is_scalar($storedValue)) {
                continue;
            }
            if ($storedValue !== null && (string)$storedValue === (string)$requestedValue) {
                continue;
            }
            $warnings[] = $this->buildWarning($propertyName, $requestedValue, $storedValue);
        }
        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWarning(string $field, mixed $requestedValue, mixed $storedValue, ?string $message = null): array
    {
        return [
            'field' => $field,
            'requested' => $requestedValue,
            'stored' => $storedValue,
            'message' => $message ?? sprintf(
                'Stored value of "%s" differs from the requested value (adjusted by Neos, e.g. to keep it unique).',
                $field
            ),
        ];
    }
}
