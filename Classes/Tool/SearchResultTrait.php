<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel\ElasticSearchQueryBuilder;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Shared result handling for the Elasticsearch-based tools. Executes the query,
 * serializes the resolved nodes and reports hits that could not be resolved to
 * nodes (stale index, other dimension/workspace) instead of dropping them silently.
 */
trait SearchResultTrait
{
    /**
     * Maximum number of unresolved identifiers listed in the response.
     */
    protected int $maxUnresolvedIdentifiers = 20;

    /**
     * @param ElasticSearchQueryBuilder $queryBuilder query builder, already initialized via query($contextNode)
     * @param NodeInterface $contextNode the node the query was started from
     * @param array|string|null $responseProperties
     * @return array<string, mixed>
     */
    protected function executeSearch(ElasticSearchQueryBuilder $queryBuilder, NodeInterface $contextNode, $responseProperties): array
    {
        // Also fetch the node identifier, so unresolved hits can be reported by identifier
        $queryBuilder->getRequest()->setValueByPath('_source', ['neos_path', 'neos_node_identifier']);

        $result = $queryBuilder->fetch();
        /** @var NodeInterface[] $resolvedNodes */
        $resolvedNodes = $result['nodes'] ?? [];

        $response = [
            'total' => $queryBuilder->getTotalItems(),
            'nodes' => array_map(
                fn(NodeInterface $node) => $this->nodeSerializer->serializeNodeFiltered($node, $responseProperties),
                $resolvedNodes
            ),
        ];

        $dimensionsJson = json_encode($contextNode->getContext()->getDimensions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $workspaceName = $contextNode->getContext()->getWorkspaceName();

        if (!isset($result['hits'])) {
            $response['hint'] = sprintf(
                'The Elasticsearch request failed (see the Flow exception log). Possibly there is no index for the dimensions %s — check the "dimensions" argument.',
                $dimensionsJson
            );
            return $response;
        }

        $resolvedPaths = [];
        foreach ($resolvedNodes as $node) {
            $resolvedPaths[$node->getPath()] = true;
        }

        $unresolved = [];
        foreach ($result['hits']['hits'] ?? [] as $hit) {
            $source = isset($hit['fields']['neos_path']) ? $hit['fields'] : ($hit['_source'] ?? []);
            $nodePath = $source['neos_path'] ?? null;
            $nodePath = is_array($nodePath) ? current($nodePath) : $nodePath;
            $identifier = $source['neos_node_identifier'] ?? ($hit['_source']['neos_node_identifier'] ?? null);
            $identifier = is_array($identifier) ? current($identifier) : $identifier;

            if (!is_string($nodePath) || isset($resolvedPaths[$nodePath])) {
                continue;
            }
            // Hit was resolvable but skipped by the adaptor (duplicate across workspaces or beyond the limit)
            if ($contextNode->getNode($nodePath) instanceof NodeInterface) {
                continue;
            }
            $unresolved[(string)($identifier ?: $nodePath)] = true;
        }

        if ($unresolved !== []) {
            $unresolvedIdentifiers = array_keys($unresolved);
            $response['unresolved'] = count($unresolvedIdentifiers);
            $response['unresolvedIdentifiers'] = array_slice($unresolvedIdentifiers, 0, $this->maxUnresolvedIdentifiers);
            $response['hint'] = sprintf(
                '%d search hit(s) could not be resolved to nodes in workspace "%s" with dimensions %s. '
                . 'The Elasticsearch index may be outdated (rebuild it with ./flow nodeindex:build), '
                . 'or the nodes exist only in another dimension or workspace — try the "dimensions" or "workspaceName" argument.',
                count($unresolvedIdentifiers),
                $workspaceName,
                $dimensionsJson
            );
        }

        return $response;
    }
}
