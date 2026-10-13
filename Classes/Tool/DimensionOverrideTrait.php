<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use Neos\Flow\Annotations as Flow;

/**
 * Shared handling of an optional per-call "dimensions" override for tools that
 * resolve nodes through a ContentContext.
 *
 * Without an override no dimensions are passed to the ContentContextFactory at
 * all, so it applies the configured Neos.ContentRepository.contentDimensions
 * defaults automatically. A caller may pass a "dimensions" argument to operate
 * on content stored under a different dimension preset.
 */
trait DimensionOverrideTrait
{
    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array|null
     */
    protected $configuredContentDimensions;

    /**
     * JSON schema fragment describing the optional "dimensions" input property.
     * The description is generated from the configured content dimensions, so
     * it always names the real default values of this installation.
     *
     * @return array<string, mixed>
     */
    protected function dimensionsInputSchema(): array
    {
        return [
            'type' => 'object',
            'description' => $this->dimensionsDescription(),
        ];
    }

    /**
     * @return string
     */
    protected function dimensionsDescription(): string
    {
        $dimensions = is_array($this->configuredContentDimensions) ? $this->configuredContentDimensions : [];
        if ($dimensions === []) {
            return 'Content dimensions override. No content dimensions are configured on this installation — omit this argument.';
        }

        $example = [];
        $defaults = [];
        $available = [];
        foreach ($dimensions as $dimensionName => $dimensionConfiguration) {
            $default = (string)($dimensionConfiguration['default'] ?? '');
            $defaults[] = $dimensionName . '=' . $default;

            $defaultPreset = $dimensionConfiguration['defaultPreset'] ?? null;
            $presetValues = $dimensionConfiguration['presets'][$defaultPreset]['values'] ?? null;
            $example[$dimensionName] = is_array($presetValues) && $presetValues !== [] ? array_values($presetValues) : [$default];

            $values = [];
            foreach ($dimensionConfiguration['presets'] ?? [] as $preset) {
                if (is_array($preset) && !empty($preset['values'])) {
                    $values[] = json_encode(array_values($preset['values']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            $values = array_values(array_unique($values));
            if ($values !== []) {
                $available[] = $dimensionName . ': ' . implode(', ', array_slice($values, 0, 20)) . (count($values) > 20 ? ', …' : '');
            }
        }

        return 'Content dimensions to resolve nodes in, e.g. '
            . json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '. '
            . 'Optional — defaults to the ContentRepository default dimensions (' . implode(', ', $defaults) . '). '
            . ($available !== [] ? 'Available values per dimension: ' . implode('; ', $available) . '. ' : '')
            . 'Pass this only to operate on content stored under a non-default dimension.';
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
