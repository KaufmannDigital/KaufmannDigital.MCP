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
- **All tool calls (read and write) bypass Flow's authorization checks** (`withoutAuthorizationChecks`). This is intentional for AI-driven automation, but means no Neos backend role restrictions apply — including access to any workspace.
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

**3. Error handling (optional)**

```yaml
KaufmannDigital:
  MCP:
    # Return exception class, code, message, the affected node/property arguments and the log reference
    # to the client when a tool fails (default: true). false = generic message + log reference only.
    exposeErrorDetails: true
    # Headers masked as '***' in Data/Logs/Exceptions (default: X-Api-Token, Authorization, Proxy-Authorization)
    maskedRequestHeaders:
      - 'X-Api-Token'
      - 'Authorization'
      - 'Proxy-Authorization'
```

Example error response (`isError: true`) with `exposeErrorDetails: true`:

```
Tool "create_node" failed: Neos\ContentRepository\Exception\NodeTypeNotFoundException (code 1316598370): The node type "Foo:Bar" is not available
Arguments: parentNodeIdentifier=…, nodeType=Foo:Bar, workspaceName=live
Log reference: 20261013120000abcdef.txt
```

> **Token masking in exception logs:** The package replaces the request information and backtrace renderers of Flow's throwable storage, so the configured headers and the configured `Token` value are written as `***` to `Data/Logs/Exceptions/*.txt` — for errors caught in tool execution as well as for uncaught exceptions. Flow's `Neos.Flow.log.throwables.renderRequestInformation` setting is still respected. Exception dumps written before this change may still contain the token in plain text — check and clean them up manually.
> The masking is registered in the package's `Package.php` — if you update the package without Composer, run `./flow flow:package:rescan` once.

---

## Tools

### `get_node`
Loads a single node by its UUID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID |
| `workspaceName` | string | | Workspace (default: `live`) |
| `includeChildren` | boolean | | Include direct child nodes (default: `false`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `search_nodes`
Full-text search across all nodes via Elasticsearch.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | | Search term (optional if `nodeType` is set) |
| `nodeType` | string | | Filter by node type, e.g. `Neos.Neos:Document` |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to search in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `limit` | integer | | Max results (default: `10`) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

Search hits that cannot be resolved to nodes (outdated index, node only exists in another dimension or workspace) are no longer dropped silently. The response then contains `unresolved` (count), `unresolvedIdentifiers` (node identifiers of those hits, max. 20) and a `hint`. If the Elasticsearch request itself fails (e.g. no index exists for the requested dimensions), `hint` explains that as well.

```json
{ "total": 477, "nodes": [], "unresolved": 10, "unresolvedIdentifiers": ["…"], "hint": "10 search hit(s) could not be resolved to nodes in workspace \"live\" with dimensions {\"language\":[\"de\"]}. The Elasticsearch index may be outdated …" }
```

---

### `find_by_property`
Finds nodes with an exact property value match via Elasticsearch.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `propertyName` | string | ✓ | Property name to match |
| `propertyValue` | any | ✓ | Exact value to search for |
| `nodeType` | string | | Filter by node type (optional) |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to search in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `limit` | integer | | Max results (default: `10`) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

Search hits that cannot be resolved to nodes (outdated index, node only exists in another dimension or workspace) are no longer dropped silently. The response then contains `unresolved` (count), `unresolvedIdentifiers` (node identifiers of those hits, max. 20) and a `hint`. If the Elasticsearch request itself fails (e.g. no index exists for the requested dimensions), `hint` explains that as well.

```json
{ "total": 477, "nodes": [], "unresolved": 10, "unresolvedIdentifiers": ["…"], "hint": "10 search hit(s) could not be resolved to nodes in workspace \"live\" with dimensions {\"language\":[\"de\"]}. The Elasticsearch index may be outdated …" }
```

---

### `get_children`
Returns direct child nodes of a given node.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Parent node UUID |
| `nodeTypeFilter` | string | | Node type filter, e.g. `Neos.Neos:Document` |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

---

### `create_node`
Creates a new node — appended as last child of a parent node, or directly before/after a sibling node.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `parentNodeIdentifier` | string | (✓) | Parent node UUID — required for position `into`; optional for `before`/`after` (if given, it must be the sibling's parent) |
| `nodeType` | string | ✓ | Node type name, e.g. `Neos.Neos:Document` |
| `properties` | object | | Key/value map of properties to set (may include [internal node flags](#internal-node-flags) like `_hidden`) |
| `nodeName` | string | | Node name (path segment). Default: random `node-…`. If invalid or already taken below the parent, another name is used and a [warning](#write-side-effects--warnings) is returned |
| `position` | string | | `into` (default): append as last child of `parentNodeIdentifier`. `before` / `after`: insert as sibling of `siblingNodeIdentifier` |
| `siblingNodeIdentifier` | string | | Sibling node UUID — required for `before` / `after` |
| `applyDefaults` | boolean | | Apply NodeType default values (default: `true`). `false`: the defaults Neos sets in `createNode()` are removed again from the new node **and its tethered child nodes**, so only explicitly passed properties are stored (useful for exact copies). Internal `_`-properties (e.g. `_hidden`) are not affected; document nodes still get a `uriPathSegment` |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions to create the node in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return (default: `identifier` only) |

Response: `{"created": {…}, "warnings": [...]}` (`warnings` only if something was adjusted).

---

### `batch_create_nodes`
Creates multiple nodes — optionally whole nested trees — in a single call. Every item is processed independently: a failing item gets an `error` in its result and does not abort the batch (its children are skipped and counted in `skippedChildren`).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodes` | array | ✓ | Items to create, in order (see below) |
| `workspaceName` | string | | Workspace (default: `live`) |
| `applyDefaults` | boolean | | Default for all items (default: `true`), see `create_node` |
| `dimensions` | object | | Content dimensions (see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields per created node (default: `identifier` only) |

Item fields: `nodeType` (required), `nodeName`, `properties`, `applyDefaults`, `children` and
- top-level items: `parentNodeIdentifier` / `position` / `siblingNodeIdentifier` (same as `create_node`)
- nested items (inside `children`): always appended in order into the created parent item, or into a descendant of it given by `parentPath` (relative path, e.g. `"main"` for a tethered content collection, `"column0"`)

```jsonc
{
  "nodes": [{
    "parentNodeIdentifier": "…", "nodeType": "KaufmannDigital.Nova.Core:Page.NovaPage", "nodeName": "about",
    "properties": {"title": "About"},
    "children": [{
      "parentPath": "main", "nodeType": "KaufmannDigital.Nova.NodeTypes.Section:Molecule.Section",
      "children": [{"nodeType": "KaufmannDigital.Nova.NodeTypes.Headline:Atom.Headline", "properties": {"headline": "Hello"}}]
    }]
  }]
}
```

Response: `{"createdCount": n, "errorCount": n, "results": [{"index": 0, "identifier": "…", "warnings": [...], "children": [...]}, {"index": 1, "error": "…"}]}` — results mirror the input structure.

---

### `copy_node`
Copies a node including its whole subtree (`Node::copyInto/copyBefore/copyAfter`). Node names, properties, internal flags (e.g. hidden) and order inside the subtree are kept; the copies get new identifiers. For document nodes Neos keeps the `uriPathSegment` unique among the new siblings.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | UUID of the node to copy |
| `targetNodeIdentifier` | string | ✓ | New parent (`into`) or sibling (`before` / `after`) |
| `position` | string | | `into` (default, appended as last child), `before`, `after` |
| `nodeName` | string | | Node name of the copy (default: the source node's name; if taken below the new parent a random name is used + warning) |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions (see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields of the copied root node (default: `identifier` only) |

Response: `{"copied": {…}, "warnings": [...]}` — warns if the node name or the `uriPathSegment` differ from the source/requested value.

---

### `move_node`
Moves a node including its subtree (`Node::moveInto/moveBefore/moveAfter`); also used to reorder siblings. Identifiers stay the same. When a document node is moved to a new parent, Neos keeps its `uriPathSegment` unique.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | UUID of the node to move |
| `targetNodeIdentifier` | string | ✓ | New parent (`into`) or sibling (`before` / `after`) |
| `position` | string | | `into` (default, appended as last child — also within the current parent), `before`, `after` |
| `nodeName` | string | | New node name (default: keep the current name; if taken below the new parent a random name is used + warning) |
| `workspaceName` | string | | Workspace (default: `live`) |
| `dimensions` | object | | Content dimensions (see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields of the moved node (default: `identifier` only) |

Response: `{"moved": {…}, "warnings": [...]}`.

---

### `delete_node`
Deletes a node by UUID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID to delete |
| `workspaceName` | string | | Workspace (default: `live`) |
| `publishAfterDelete` | boolean | | Publish delete from user workspace to base workspace (default: `false`) |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields to return for deleted node (default: `identifier` only) |

---

### `update_property`
Sets a single property on a node. The response always contains the updated property as it was persisted (the node is re-read after saving), so a value that did not stick is immediately visible.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeIdentifier` | string | ✓ | Node UUID |
| `propertyName` | string | ✓ | Property name, or an [internal node flag](#internal-node-flags) like `_hidden` |
| `propertyValue` | any | ✓ | New value (see [Reference properties](#reference-properties)) |
| `workspaceName` | string | ✓ | Workspace to write to |
| `dimensions` | object | | Content dimensions to resolve the node in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Additional fields to return (default: `identifier` + the updated property) |

Response: `{"updated": {…}, "warnings": [...]}` — `warnings` is only present if e.g. a referenced node does not exist in the target workspace/dimension. Invalid values (e.g. a malformed node identifier or flag value) return an `Error: …` message and leave the node unchanged.

Response: `{"updated": {…}, "warnings": [...]}` — `warnings` only if the stored value differs from the requested one (e.g. `uriPathSegment` `home` → `home-1`), see [Write side effects](#write-side-effects--warnings).

---

### `batch_update_property`
Sets properties on multiple nodes in a single call. Uses the same write path as `update_property`; the returned nodes are re-read after saving.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodes` | array | ✓ | List of `{nodeIdentifier, properties}` objects (`properties` may include [internal node flags](#internal-node-flags)) |
| `workspaceName` | string | ✓ | Workspace to write to |
| `dimensions` | object | | Content dimensions to resolve the nodes in, e.g. `{"language":["en"]}` (default: ContentRepository default — see [Content Dimensions](#content-dimensions)) |
| `responseProperties` | array | | Fields per node (default: `identifier` only) |

Response: `{"updatedCount": n, "updated": [...], "errors": [...], "warnings": [...]}`. A node with an invalid value is skipped entirely (none of its properties are written) and reported in `errors`; `warnings` (with `nodeIdentifier`) is only present when needed, see [Write side effects](#write-side-effects--warnings).

#### Reference properties
For properties of type `references` pass an array of node identifiers, e.g. `["a1b2…", "c3d4…"]`; `[]` or `null` clears the property. A JSON-encoded array string (`"[\"a1b2…\"]"`) or a single identifier string is accepted as well. For type `reference` pass a single identifier (a one-element array is accepted too); `null` clears it.

---

### `get_node_type`
Returns the effective (merged) configuration of a NodeType — use it to find out which properties a node *can* have (`get_node` only returns stored values).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `nodeType` | string | ✓ | Node type name |
| `includeInternalProperties` | boolean | | Include `_`-prefixed internal properties like `_hidden` (default: `false`) |
| `includeAllowedChildNodeTypes` | boolean | | Resolve constraints into concrete (non-abstract) NodeType lists: `allowedChildNodeTypes` and `childNodes.<name>.allowedChildNodeTypes` (default: `false`) |

Response fields: `name`, `label`, `abstract`, `final`, `superTypes` (direct), `allSuperTypes` (transitive), `aggregate`, `isDocument`, `isContent`, `properties` (per property: `type`, `defaultValue`, `label`, inspector `group` / `editor`, `validation` — only keys that are configured), `childNodes` (tethered child nodes: `type`, effective `constraints`), `constraints`. Labels are translated if they are i18n ids.

---

### `list_node_types`
Lists the available NodeTypes — for discovering a project without knowing its code. Compact by default; filters can be combined.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `superType` | string | | Only NodeTypes of this type (transitively), e.g. `Neos.Neos:Document`, `Neos.Neos:Content` |
| `search` | string | | Case-insensitive substring match on name and (translated) label |
| `namePrefix` | string | | Only NodeTypes whose name starts with this prefix, e.g. `KaufmannDigital.Nova.` |
| `includeAbstract` | boolean | | Include abstract NodeTypes / mixins (default: `false`) |
| `detailed` | boolean | | Full `get_node_type` structure per entry (default: `false`) |
| `allowedUnderNodeIdentifier` | string | | Only NodeTypes that may be created directly below this existing node (constraints incl. tethered-node constraints are resolved). The response then also contains `allowedUnder` with the node's `tetheredChildNodes` (e.g. `main`) — content usually has to be created inside those |
| `workspaceName` | string | | Workspace for `allowedUnderNodeIdentifier` (default: `live`) |
| `dimensions` | object | | Content dimensions for `allowedUnderNodeIdentifier` |

Response: `{"count": n, "nodeTypes": [{"name", "label", "abstract", "final", "superTypes"}, …]}` (+ `allowedUnder`).

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
- Meta fields (returned **top-level**): `"identifier"`, `"nodeType"`, `"label"`, `"name"`, `"path"`, `"workspace"`, `"hidden"`, `"hiddenInIndex"`, `"hiddenBeforeDateTime"`, `"hiddenAfterDateTime"`, `"creationDate"`, `"parentIdentifier"`, `"ancestors"` (the hidden flags can also be requested with their `_`-prefixed write names, e.g. `"_hidden"`)
  - `"parentIdentifier"`: UUID of the direct parent node (`null` for the root node) — use it e.g. as `parentNodeIdentifier` for `create_node` to create a sibling
  - `"ancestors"`: array of `{identifier, nodeType, name}` from the topmost ancestor below the root node (`/sites`) down to the direct parent — useful to find the containing document (e.g. the first `Neos.Neos:Document` from the end)
- Node properties (returned in a nested **`properties`** object): any property name defined on the node type, e.g. `"title"`, `"releaseDate"`, `"uriPathSegment"`. To request a node property whose name collides with a meta field, prefix it with `properties.` (e.g. `"properties.identifier"`).
- `["*"]`: all meta fields plus all node properties, **except `"ancestors"`** (it walks the whole parent chain per node, which is costly for long result lists). Use `["*", "ancestors"]` to get both.

Requested names that are neither a meta field nor a property declared on the node type (or stored on the node) are listed in an `unknownProperties` array instead of being returned as `null`.

**Examples:**
```json
// Search: request title and date for identification
"responseProperties": ["nodeType", "title", "releaseDate", "titel"]

// Response
{
  "identifier": "8d3c…",
  "nodeType": "Vendor:Document.BlogPost",
  "properties": { "title": "Hello world", "releaseDate": "2024-05-01T00:00:00+00:00" },
  "unknownProperties": ["titel"]
}

// Resolve the parent (e.g. to create a sibling) and the containing page
"responseProperties": ["parentIdentifier", "ancestors"]

// After write operations: omit responseProperties entirely — only identifier is needed
```

> **Breaking change:** Node properties used to be merged into the top-level object next to the meta fields, so a property named e.g. `identifier`, `name` or `path` overwrote the corresponding meta field. They are now always returned in the nested `properties` object.

**Property value formats:**

| Value | Serialized as |
|---|---|
| Scalars / `null` | as is |
| `DateTime` | ISO 8601 string |
| Image | `{"__type": "Image", "identifier": "…", "url": "…", "filename": "…"}` |
| Asset | `{"__type": "Asset", "identifier": "…", "url": "…", "filename": "…"}` |
| Node (`reference` / `references`) | `{"__type": "Node", "identifier": "…", "path": "…", "nodeType": "…"}` — the `identifier` can be written back directly |
| Arrays | each item serialized as above |
| Other objects | `{"__type": "<class>", "__string": "…"}` |

Serialization errors are reported per property instead of failing the whole request: a property that cannot be read is returned as `{"__type": "Error", "error": "…"}`; if only the URL of an image/asset cannot be generated, the value is returned with `"url": null` and an `"error"` field.

---

## Internal node flags

The write tools (`create_node`, `update_property`, `batch_update_property`) accept the following internal flags in place of a property name. They are mapped to the corresponding node setters instead of being stored as regular properties:

| Flag | Value | Effect |
|------|-------|--------|
| `_hidden` | boolean | Hide the node (`setHidden()`) |
| `_hiddenInIndex` | boolean | Hide the node in menus (`setHiddenInIndex()`) |
| `_hiddenBeforeDateTime` | ISO 8601 string or `null` | Hide before date/time (`setHiddenBeforeDateTime()`) |
| `_hiddenAfterDateTime` | ISO 8601 string or `null` | Hide after date/time (`setHiddenAfterDateTime()`) |

Any other `_`-prefixed name is rejected with an error. Invalid values (e.g. a non-boolean for `_hidden`) are rejected as well; with `create_node` and `update_property` nothing is written in that case, with `batch_update_property` the affected node is skipped and reported in `errors`.

```jsonc
// Hide a page in menus
{ "nodeIdentifier": "…", "propertyName": "_hiddenInIndex", "propertyValue": true, "workspaceName": "live" }
```

Read them back via the meta fields `hidden`, `hiddenInIndex`, `hiddenBeforeDateTime`, `hiddenAfterDateTime` in `responseProperties`.

---

## Write side effects / warnings

Neos adjusts some values on write — e.g. it makes the `uriPathSegment` of document nodes unique among its siblings (`home` → `home-1`), or a requested node name may already be taken. Write tools (`create_node`, `batch_create_nodes`, `update_property`, `batch_update_property`, `copy_node`, `move_node`) report this in a `warnings` array instead of silently returning success:

```json
"warnings": [
  {"field": "uriPathSegment", "requested": "home", "stored": "home-1", "message": "…"}
]
```

`field` is a property name or `nodeName`. Only scalar values are compared (objects like assets, dates or references are converted by Neos and not reported). The key is omitted when nothing was adjusted.

---

## Content Dimensions

The node-resolving tools (`get_node`, `get_children`, `create_node`, `batch_create_nodes`, `copy_node`, `move_node`, `delete_node`, `update_property`, `batch_update_property`, `list_node_types` with `allowedUnderNodeIdentifier`) and the Elasticsearch-based tools (`search_nodes`, `find_by_property`) build their `ContentContext` from the site's content dimension configuration.

- **No `dimensions` argument (default):** the tools do **not** set any dimensions, so the `ContentContextFactory` automatically applies the configured `Neos.ContentRepository.contentDimensions` defaults (e.g. `language` → its `default` preset). This means you get the site's primary dimension out of the box without any package configuration.
- **Explicit `dimensions` override:** pass an object mapping each dimension to its value(s), e.g. `{"language":["en"]}`, to resolve nodes in another dimension. `targetDimensions` are derived automatically from the first value of each dimension. For `search_nodes` / `find_by_property` the target dimensions also select the dimension-specific Elasticsearch index, so query and node resolution always use the same dimensions.
- The `dimensions` parameter description in `tools/list` is generated from the configured `Neos.ContentRepository.contentDimensions` (default values and available preset values), so agents see the real defaults of the installation.
- The override may be passed as a JSON object or a JSON-encoded string — both are accepted (some MCP clients serialize object arguments as strings).
- If a node has no variant in the requested dimension, the tool returns `Node not found` (no silent fallback to the default dimension).

```jsonc
// Resolve the English (en) variant of a node
{ "nodeIdentifier": "…", "dimensions": { "language": ["en"] }, "responseProperties": ["title"] }
```

> `publish_nodes` always operates on the ContentRepository default dimension and does not accept a `dimensions` argument.

---

## Architecture

```
McpController
  └── McpHandler          JSON-RPC dispatcher
        └── Tool/*        One class per operation (Flow Singletons)
              ├── NodeSerializer       Shared node serialization
              └── NodePropertyWriter   Shared write path (resolve, set, persist, re-read)
```

- **All tools** run without Flow authorization checks: `McpHandler` wraps every tool execution in `SecurityContext::withoutAuthorizationChecks()`, so read tools can access non-live workspaces as well
- A `workspaceName` that does not exist is rejected with an error listing the existing workspaces (Neos would otherwise silently create a new workspace for an unknown name)
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
