# CLAUDE.md — annotations_context

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

Assembles annotation data into structured context payloads: HTML preview, markdown download, REST JSON, and MCP endpoint. AI integration is handled by `annotations_ai_context`.

## Services

| Service ID | Class | Purpose |
| --- | --- | --- |
| `annotations_context.assembler` | `ContextAssembler` | Builds the PHP array payload |
| `annotations_context.renderer` | `ContextRenderer` | Renders payload to markdown |
| `annotations_context.html_renderer` | `ContextHtmlRenderer` | Renders payload to Drupal render arrays |

## Routes

| Route | Path | Permission |
| --- | --- | --- |
| `annotations_context.mcp` | `/api/annotations/mcp` | `view annotations context` OR `administer annotations` |
| `annotations_context.api` | `/api/annotations/{target_id}` | same |
| `annotations_context.preview` | `/admin/config/annotations/context` | same |
| `annotations_context.export` | `/admin/config/annotations/context/export` | same |

`view annotations context` is not `restrict access: true` — can be granted to non-admin roles.

## ContextAssembler options

```php
$payload = $assembler->assemble([
  'entity_type'           => 'node',
  'target_id'             => 'node__article',
  'types'                 => ['editorial'],
  'ref_depth'             => 1,               // 0–2
  'role'                  => 'editor',        // simulate role (preview page); takes precedence over account
  'account'               => $currentUser,    // filter by real user permissions
  'include_incoming_refs' => TRUE,            // flat, no recursion
]);
```

`role` takes precedence over `account`. Accounts with `administer annotations` bypass type filtering. Use `account` for real consumers (e.g. `annotations_ai_context`), `role` for preview simulation.

## Payload structure

```php
[
  'groups' => [
    'node' => [
      'entity_type' => 'node',
      'label'       => 'Content types',  // from DiscoveryService plugin
      'targets'     => [
        'node__article' => [
          'id'          => 'node__article',
          'label'       => 'Article',
          'entity_type' => 'node',
          'bundle'      => 'article',
          'annotations' => ['editorial' => ['label' => 'Editorial', 'value' => '...']],
          'fields'      => ['title' => ['label' => 'Title', 'annotations' => [...]]],
          'references'  => [...],  // only when ref_depth > 0
        ],
      ],
    ],
  ],
  'meta' => ['generated_at' => '...', 'ref_depth' => 0, 'target_count' => 12],
]
```

Targets with no matching annotations are omitted when type-filtering (`skip_empty` in `assembleGroups()`).

## HTML normalisation

All string values leaving the assembler — annotation `value`, configurable extra fields, and field `meta.description` — pass through `ContextAssembler::flattenHtml()`:

1. `<a href="url">text</a>` → `text (url)` (URL preserved)
2. Remaining tags stripped via `strip_tags()`
3. HTML entities decoded via `html_entity_decode()`
4. Whitespace collapsed

No-op when no `<` is present. Annotation storage is plain text, but values may contain markup if content was pasted from a rich-text source. Normalisation at assembly time means all consumers (preview, markdown, JSON API, MCP) receive clean text without each needing its own sanitisation pass.

## Rendering

**ContextRenderer (markdown):** H1 (entity type group) → H2 (target) → H3 (Fields/References) → H4 (field name). Annotation text as plain paragraphs.

**ContextHtmlRenderer (render arrays):** Values escaped via `Html::escape()` wrapped in `Markup::create()`. `details/summary` for collapsible target cards. "Overview" label suppressed when target has no fields or references.

Custom renderers just consume the payload array — no base class needed.

## Extending the assembler

**Alter hook (built):** `ContextAssembler::assemble()` invokes `hook_annotations_context_alter(&$payload, $options, &$cacheableMetadata)`. Callers producing cacheable output must call `$assembler->getLastCacheableMetadata()->applyTo($build)`.

**Tagged services (deferred):** `annotations.context_provider` tag pattern — same as `annotations.target` plugins. Providers implement `provideContext(array $options): array` + `getCacheableMetadata(array $options): CacheableMetadata`. Deferred until ordering guarantees are needed.

## MCP endpoint

`POST /api/annotations/mcp` — MCP Streamable HTTP (2025-03-26 spec). Resource URIs: `annotation://target/{target_id}`. Content returned as `text/plain` markdown (token-efficient for AI).

Query params: `?ref_depth=0|1|2`, `?include_field_meta=1`.

Supported methods: `initialize`, `notifications/*` (202 no-body), `resources/list`, `resources/read`, `ping`. Error codes: standard JSON-RPC 2.0 + MCP `-32002` for resource not found.

**Type filtering:** `resources/read` only returns types where `getThirdPartySetting('annotations_context', 'in_ai_context', FALSE)` is truthy. Set via annotation type edit form. Default FALSE — must opt in. Future AI consumers must read `annotations_context.in_ai_context`, not define their own key.

Auth: Drupal native permissions. Bearer token (for headless clients) requires `simple_oauth` or similar. No `role` simulation — preview-page concern only.

## REST JSON endpoint

`GET /api/annotations/{target_id}` — `CacheableJsonResponse`. Query params: `ref_depth`, `include_field_meta`. Returns full assembler payload or 404 JSON error. Cache tags: `annotation_list`, `annotation_target_list`, `annotation_type_list`; contexts: `user.permissions`, `url.query_args`, `languages:language_interface`.

## Preview page

- Toolbar: filter form (left) + Download .md button (right)
- Filters: role simulation, specific target, ref depth, include field metadata
- Collapsed "Raw markdown" `details` drawer at bottom
- Export: `text/markdown`, `Content-Disposition: attachment`, filename from active filters (e.g. `annotations-context-node-article.md`)
- `ContextFilterForm` uses `#method => 'get'` with `#token => FALSE` and an `#after_build` callback that strips `form_build_id`, `form_token`, and `form_id` — keeps the URL clean (only meaningful filter params appear in the query string)

## Deferred

- `display_mode` assembler option — filter fields to those rendered in a given `EntityViewDisplay`. Low priority; overlay must check `EntityViewDisplay::getComponents()` for its own purposes (see `annotations_overlay/CLAUDE.md`).
- `drush annotations:export` — trivial when ready: `ContextRenderer::render($assembler->assemble($options))` piped to stdout.

## Status

- [x] `ContextAssembler` — all options, entity reference traversal, cycle detection, skip_empty
- [x] `hook_annotations_context_alter()` — `CacheableMetadata` contract enforced
- [x] `ContextRenderer`, `ContextHtmlRenderer`
- [x] `ContextPreviewController` — preview + export; merges alter hook cache metadata
- [x] `ContextApiController` — JSON endpoint; `CacheableJsonResponse` with full cache tags/contexts
- [x] Routing, permissions, menu link
- [ ] Tagged service provider pattern (`annotations.context_provider`) — deferred
- [ ] `drush annotations:export` — parked
