<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;

/**
 * Builds JSON-friendly descriptions of NodeTypes (effective, i.e. merged configuration)
 * for get_node_type and list_node_types.
 *
 * @Flow\Scope("singleton")
 */
class NodeTypeDescriber
{
    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * Compact description: name, label, flags, direct super types.
     *
     * @return array<string, mixed>
     */
    public function summarize(NodeType $nodeType): array
    {
        return [
            'name' => $nodeType->getName(),
            'label' => $this->translateLabel($nodeType->getLabel()),
            'abstract' => $nodeType->isAbstract(),
            'final' => $nodeType->isFinal(),
            'superTypes' => array_values(array_map(fn(NodeType $superType) => $superType->getName(), $nodeType->getDeclaredSuperTypes())),
        ];
    }

    /**
     * Full description of the effective NodeType configuration.
     *
     * @return array<string, mixed>
     */
    public function describe(NodeType $nodeType, bool $includeInternalProperties = false): array
    {
        $result = $this->summarize($nodeType);
        $result['allSuperTypes'] = $this->collectAllSuperTypeNames($nodeType);
        $result['aggregate'] = $nodeType->isAggregate();
        $result['isDocument'] = $nodeType->isOfType('Neos.Neos:Document');
        $result['isContent'] = $nodeType->isOfType('Neos.Neos:Content');
        $result['properties'] = $this->describeProperties($nodeType, $includeInternalProperties);
        $result['childNodes'] = $this->describeChildNodes($nodeType);
        $result['constraints'] = $nodeType->getConfiguration('constraints.nodeTypes') ?: (object)[];

        return $result;
    }

    /**
     * Names of all non-abstract NodeTypes that may be created directly below $node,
     * taking tethered-node constraints into account (NodeInterface::isNodeTypeAllowedAsChildNode).
     *
     * @return string[]
     */
    public function allowedChildNodeTypeNamesForNode(NodeInterface $node): array
    {
        $names = [];
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $candidate) {
            if ($node->isNodeTypeAllowedAsChildNode($candidate)) {
                $names[] = $candidate->getName();
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Names of all non-abstract NodeTypes allowed below a node of $nodeType — or, when
     * $tetheredChildNodeName is given, below its tethered child node of that name.
     *
     * @return string[]
     */
    public function allowedChildNodeTypeNames(NodeType $nodeType, ?string $tetheredChildNodeName = null): array
    {
        $names = [];
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $candidate) {
            $allowed = $tetheredChildNodeName === null
                ? $nodeType->allowsChildNodeType($candidate)
                : $nodeType->allowsGrandchildNodeType($tetheredChildNodeName, $candidate);
            if ($allowed) {
                $names[] = $candidate->getName();
            }
        }
        sort($names);
        return $names;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function describeProperties(NodeType $nodeType, bool $includeInternalProperties): array
    {
        $properties = [];
        foreach ($nodeType->getProperties() as $propertyName => $configuration) {
            $propertyName = (string)$propertyName;
            if (!$includeInternalProperties && str_starts_with($propertyName, '_')) {
                continue;
            }
            if (!is_array($configuration)) {
                continue;
            }
            $description = ['type' => $configuration['type'] ?? 'string'];
            if (array_key_exists('defaultValue', $configuration)) {
                $description['defaultValue'] = $configuration['defaultValue'];
            }
            $label = $configuration['ui']['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $description['label'] = $this->translateLabel($label);
            }
            if (!empty($configuration['ui']['inspector']['group'])) {
                $description['group'] = $configuration['ui']['inspector']['group'];
            }
            if (!empty($configuration['ui']['inspector']['editor'])) {
                $description['editor'] = $configuration['ui']['inspector']['editor'];
            }
            if (!empty($configuration['validation']) && is_array($configuration['validation'])) {
                $description['validation'] = array_keys(array_filter($configuration['validation'], fn($v) => $v !== null && $v !== false));
            }
            $properties[$propertyName] = $description;
        }
        ksort($properties);
        return $properties;
    }

    /**
     * Tethered (auto-created) child nodes with their type and effective constraints.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function describeChildNodes(NodeType $nodeType): array
    {
        $childNodes = [];
        $configuration = $nodeType->getConfiguration('childNodes') ?: [];
        foreach ($nodeType->getAutoCreatedChildNodes() as $childNodeName => $childNodeType) {
            $childNodeName = (string)$childNodeName;
            $childConfiguration = $configuration[$childNodeName] ?? [];
            $constraints = array_merge(
                $childNodeType->getConfiguration('constraints.nodeTypes') ?: [],
                is_array($childConfiguration) ? ($childConfiguration['constraints']['nodeTypes'] ?? []) : []
            );
            $childNodes[$childNodeName] = [
                'type' => $childNodeType->getName(),
                'constraints' => $constraints ?: (object)[],
            ];
        }
        return $childNodes;
    }

    /**
     * @return string[]
     */
    public function collectAllSuperTypeNames(NodeType $nodeType): array
    {
        $names = [];
        foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
            $names[] = $superType->getName();
            foreach ($this->collectAllSuperTypeNames($superType) as $name) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Best-effort translation of an i18n label id ("Vendor.Package:Source.Path:id").
     * Returns the raw value if it is no translation id or no translation exists.
     */
    public function translateLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return $label;
        }
        $parts = explode(':', $label);
        if (count($parts) !== 3 || preg_match('/\s/', $label)) {
            return $label;
        }
        [$packageKey, $source, $id] = $parts;
        try {
            $translated = $this->translator->translateById($id, [], null, null, str_replace('.', '/', $source), $packageKey);
        } catch (\Throwable $e) {
            return $label;
        }
        return ($translated !== null && $translated !== '') ? $translated : $label;
    }
}
