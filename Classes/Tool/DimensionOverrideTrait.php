<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

/**
 * Shared handling of an optional per-call "dimensions" override for tools that
 * resolve nodes through a ContentContext.
 *
 * Without an override no dimensions are passed to the ContentContextFactory at
 * all, so it applies the configured Neos.ContentRepository.contentDimensions
 * defaults (e.g. language=de_DE) automatically. A caller may pass a "dimensions"
 * argument to operate on content stored under a different dimension preset.
 */
trait DimensionOverrideTrait
{
    /**
     * JSON schema fragment describing the optional "dimensions" input property.
     *
     * @return array<string, mixed>
     */
    protected function dimensionsInputSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Content dimensions to resolve the node in, e.g. {"language": ["de_DE"]}. '
                . 'Optional — defaults to the ContentRepository default dimensions. '
                . 'Pass this only to operate on content stored under a non-default dimension.',
        ];
    }

    /**
     * Builds the dimension-related ContentContext properties for an optional
     * override. Returns an empty array when no override is given, so the
     * ContentContextFactory falls back to the ContentRepository defaults.
     * targetDimensions are derived from the first value of each dimension.
     *
     * Accepts either an associative array or a JSON-encoded string (some MCP
     * clients pass object arguments as a string).
     *
     * @param array<string, array<int, string>>|string|null $override
     * @return array<string, mixed>
     */
    protected function dimensionContextProperties($override): array
    {
        if (is_string($override)) {
            $override = json_decode($override, true);
        }
        if (empty($override) || !is_array($override)) {
            return [];
        }

        $targetDimensions = [];
        foreach ($override as $dimensionName => $values) {
            $targetDimensions[$dimensionName] = is_array($values) ? ($values[0] ?? null) : $values;
        }

        return [
            'dimensions' => $override,
            'targetDimensions' => $targetDimensions,
        ];
    }
}
