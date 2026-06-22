# KaufmannDigital.MCP — Neos MCP Server

> **⚠ Work in Progress**
> This package is experimental and under active development. It is not recommended for production use.
> APIs and tool signatures may change without notice. Use at your own risk.

A [Model Context Protocol](https://modelcontextprotocol.io/) server for Neos CMS. Allows AI assistants (Claude Code, Codex, etc.) to directly access and manipulate the Neos Content Repository via a simple HTTP+JSON-RPC interface.

**USE WITH CAUTION!!!**  
> **Giving an AI direct access to your CR could end in absolute chaos. Know what AI is doing and double-check everything, before allowing AI to do changes. Be sure to have Backups available!**

---

## Prerequisites

- **Neos CMS** 7.x or 8.x
- **Flowpack.ElasticSearch.ContentRepositoryAdaptor** — required for `search_nodes` and `find_by_property`
- PHP 8.1+

---

## Security Considerations

> **Read this section before deploying.**

- **The API token (and IP-Filter) is the only access control.** Anyone who obtains the token has full read/write access to your Neos content repository, including the ability to create, update, and publish nodes.
- **Use a strong, randomly generated token** (at minimum 32 characters). Never commit it to version control.
- **The `upload_asset` tool accepts local filesystem paths.** If enabled, a token holder can import any file readable by the web server process (e.g. config files). Restrict access to trusted users only.
- **All write operations bypass Flow's authorization checks** (`withoutAuthorizationChecks`). This is intentional for AI-driven automation, but means no Neos backend role restrictions apply.
- **Only expose the `/mcp` endpoint in development environments** or behind a firewall. Do not expose it on a public production server.
- **Use HTTP only within ddev/localhost.** If you expose the endpoint over a network, use HTTPS to protect the token in transit.

---

## Installation

```bash
composer require kaufmanndigital/neos-mcp
```

---

## Configuration

**1. Set the API token and optionally restrict access by IP** in `Configuration/Development/Settings.yaml` (never `Settings.yaml` — to keep it out of version control):

```yaml
KaufmannDigital:
  MCP:
    Token: 'your-strong-random-token-here'
    allowedIps:
      - '127.0.0.1'       # localhost IPv4
      - '::1'             # localhost IPv6
      - '172.16.0.0/12'   # Docker bridge (ddev, docker-compose, ...)
      - '1.2.3.4'         # your office IP
```

> **`allowedIps` is required — an empty list blocks all requests (deny by default).**
> Supports exact IPs and CIDR notation for both IPv4 and IPv6.
>
> **Note on ddev:** Requests from the host arrive at PHP with a Docker bridge IP (`172.x.x.x`), not your machine's actual IP. The `172.16.0.0/12` CIDR covers the entire Docker bridge range and is the recommended way to allow local ddev access.

**2. Configure MCP (example for Claude Code)** (`~/.claude.json`):

```json
{
  "mcpServers": {
    "neos": {
      "type": "http",
      "url": "https://<your-site>/mcp",
      "headers": { "X-Api-Token": "your-strong-random-token-here" }
    }
  }
}
```

> **Note:** Use HTTP (not HTTPS) when connecting from Claude Code via ddev — ddev's self-signed certificates are not trusted by Bun's HTTP client.

---

## Tools

### `get_node`
Loads a single node by its UUID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID |
| `workspaceName` | string | | Workspace (default: `live`) |
| `includeChildren` | boolean | | Include direct child nodes (default: `false`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `search_nodes`
Full-text search across all nodes via Elasticsearch.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | | Search term (optional if `nodeType` is set) |
| `nodeType` | string | | Filter by node type, e.g. `Neos.Neos:Document` |
| `workspaceName` | string | | Workspace (default: `live`) |
| `limit` | integer | | Max results (default: `10`) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `find_by_property`
Finds nodes with an exact property value match via Elasticsearch.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `propertyName` | string | ✓ | Property name to match |
| `propertyValue` | any | ✓ | Exact value to search for |
| `nodeType` | string | | Filter by node type (optional) |
| `workspaceName` | string | | Workspace (default: `live`) |
| `limit` | integer | | Max results (default: `10`) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `get_children`
Returns direct child nodes of a given node.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Parent node UUID |
| `nodeTypeFilter` | string | | Node type filter, e.g. `Neos.Neos:Document` |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `create_node`
Creates a new node under a given parent node.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `parentNodeIdentifier` | string | ✓ | Parent node UUID |
| `nodeType` | string | ✓ | Node type name, e.g. `Neos.Neos:Document` |
| `properties` | object | | Key/value map of properties to set |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to create the node in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `delete_node`
Deletes a node by UUID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID to delete |
| `workspaceName` | string | | Workspace (default: `live`) |
| `publishAfterDelete` | boolean | | Publish delete from user workspace to base workspace (default: `false`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return for deleted node (default: `identifier` only) |

---

### `update_property`
Sets a single property on a node.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID |
| `propertyName` | string | ✓ | Property name |
| `propertyValue` | any | ✓ | New value |
| `workspaceName` | string | ✓ | Workspace to write to |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `batch_update_property`
Sets properties on multiple nodes in a single call.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodes` | array | ✓ | List of `{nodeIdentifier, properties}` objects |
| `workspaceName` | string | ✓ | Workspace to write to |
| `dimensions` | object | | Content dimensions to resolve the nodes in, e.g. `{"language":["de_DE"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields per node (default: `identifier` only) |

---

### `publish_nodes`
Publishes nodes from a user workspace to the base workspace (usually `live`).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifiers` | array | ✓ | UUIDs of nodes to publish |
| `workspaceName` | string | ✓ | Source workspace |
| `responseProperties` | array | | Fields per node (default: `identifier` only) |

---

### `upload_asset`
Imports a file into the Neos media library from a URL or a local absolute path.

> **Security note:** Local path access is restricted to the directory configured via `localImportBasePath` in `Settings.yaml` (default: `Data/MCP-Import`). Files outside that directory are rejected.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | ✓ | URL or absolute local path (e.g. `/var/www/html/images/file.jpg`) |
| `filename` | string | | Original filename — required when using a local path |
| `title` | string | | Title/label for the asset in the media library |
| `caption` | string | | Caption/description text for the asset |
| `copyrightNotice` | string | | Copyright notice, e.g. the photographer name |
| `tag` | string | | Tag to assign (created if it does not exist) |

---

### `update_asset`
Updates metadata fields of an existing asset, identified by its asset identifier. Only the provided fields are changed.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `identifier` | string | ✓ | Asset identifier (UUID) of the asset to update |
| `title` | string | | Title/label for the asset |
| `caption` | string | | Caption/description text for the asset |
| `copyrightNotice` | string | | Copyright notice, e.g. the photographer name |
| `tags` | array | | Tag labels to assign (created if they do not exist); added to existing tags unless `replaceTags` is `true` |
| `replaceTags` | boolean | | Replace all existing tags with the given `tags` instead of adding (default: `false`) |

---

## responseProperties — Minimizing token costs

All tools return **only the `identifier` by default**. Use `responseProperties` to request additional fields — keeping this minimal significantly reduces AI context usage.

**Available values:**
- Node properties: any property name defined on the node type, e.g. `"title"`, `"releaseDate"`, `"uriPathSegment"`
- Meta fields: `"nodeType"`, `"label"`, `"name"`, `"path"`, `"workspace"`, `"hidden"`

**Examples:**
```json
// Search: request title and date for identification
"responseProperties": ["title", "releaseDate"]

// After write operations: omit responseProperties entirely — only identifier is needed
```

---

## Content Dimensions

The node-resolving tools (`get_node`, `get_children`, `create_node`, `delete_node`, `update_property`, `batch_update_property`) build their `ContentContext` from the site's content dimension configuration.

- **No `dimensions` argument (default):** the tools do **not** set any dimensions, so the `ContentContextFactory` automatically applies the configured `Neos.ContentRepository.contentDimensions` defaults (e.g. `language` → its `default` preset). This means you get the site's primary dimension out of the box without any package configuration.
- **Explicit `dimensions` override:** pass an object mapping each dimension to its value(s), e.g. `{"language":["en_DE"]}`, to resolve nodes in another dimension. `targetDimensions` are derived automatically from the first value of each dimension.
- The override may be passed as a JSON object or a JSON-encoded string — both are accepted (some MCP clients serialize object arguments as strings).
- If a node has no variant in the requested dimension, the tool returns `Node not found` (no silent fallback to the default dimension).

```jsonc
// Resolve the English (en_DE) variant of a node
{ "nodeIdentifier": "…", "dimensions": { "language": ["en_DE"] }, "responseProperties": ["title"] }
```

> The Elasticsearch-based tools (`search_nodes`, `find_by_property`) and `publish_nodes` always operate on the ContentRepository default dimension and do not accept a `dimensions` argument.

---

## Architecture

```
McpController
  └── McpHandler          JSON-RPC dispatcher
        └── Tool/*        One class per operation (Flow Singletons)
              └── NodeSerializer   Shared node serialization
```

- **Write tools** bypass Flow's authorization via `SecurityContext::withoutAuthorizationChecks()`
- **Asset resolution:** For properties typed as a `Neos\Media\Domain\Model\*` class or interface (e.g. `ImageInterface`), a UUID string is resolved to the Asset object via `AssetRepository::findByIdentifier()`. This handles interface-typed properties that `EntityManager::find()` cannot.
- **DateTime resolution:** ISO-8601 strings are automatically converted to `DateTime` objects
- **ElasticSearchQueryBuilder** is prototype-scoped — always instantiate via `$objectManager->get()` for a fresh instance per call

---

## Maintainer

This package is maintained by the [Neos Agency Kaufmann Digital](https://www.kaufmann.digital).  
Feel free to send us your questions or requests to [support@kaufmann.digital](mailto:support@kaufmann.digital)

### Issues and Pull-Requests are welcome!
You got stuck while installing or configuring? You are missing something? You found a bug?  
No problem, just create an issue or open a pull request. We'll have a look at it ASAP.

## License

Licensed under GPL-3, see [LICENSE](LICENSE)
