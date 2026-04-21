# CLAUDE.md — annotations_context

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Assembles annotation data into structured context payloads. Produces human-readable documentation (HTML preview + markdown download), a JSON API endpoint for headless consumers, and a PHP array payload that `annotations_ai_context` can consume without depending on this module. No AI dependency — AI integration is handled by a separate consumer.

## What it owns

### Services

| Service ID | Class | Purpose |
| --- | --- | --- |
| `annotations_context.assembler` | `ContextAssembler` | Builds the PHP array payload from annotation data |
| `annotations_context.renderer` | `ContextRenderer` | Renders payload to markdown (stateless, no DI) |
| `annotations_context.html_renderer` | `ContextHtmlRenderer` | Renders payload to Drupal render arrays |

### Routes and access

| Route | Path | Permission |
| --- | --- | --- |
| `annotations_context.api` | `/api/annotations/{target_id}` | `view annotations context` OR `administer annotations` |
| `annotations_context.preview` | `/admin/config/annotations/context` | same |
| `annotations_context.export` | `/admin/config/annotations/context/export` | same |

`view annotations context` is not `restrict access: true` — it can be granted to non-admin roles (e.g. project managers reviewing documentation).

## ContextAssembler options

```php
$payload = $assembler->assemble([
  'entity_type'  => 'node',          // limit to one entity type
  'target_id'    => 'node__article', // limit to a single target
  'types'        => ['editorial'],   // explicit type IDs to include
  'ref_depth'    => 1,               // entity reference traversal depth (0–2)
  'role'         => 'editor',        // simulate context as this Drupal role (preview page)
  'account'      => $currentUser,    // filter by actual user's combined permissions
]);
```

**`role`** — simulate context as a specific Drupal role. Takes precedence over `account`. Powers the "View as role" simulation on the preview page.

**`account`** — filter to types the given `AccountInterface` can view, using its combined permissions across all roles. Accounts with `administer annotations` bypass filtering. Use this for real current-user context in `annotations_ai_context`; use `role` for simulation previews.

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
          'fields'      => [
            'title' => [
              'label'       => 'Title',
              'annotations' => ['editorial' => ['label' => 'Editorial', 'value' => '...']],
            ],
          ],
          'references'  => [...],  // only present when ref_depth > 0
        ],
      ],
    ],
  ],
  'meta' => [
    'generated_at' => '2026-03-17T12:00:00+00:00',
    'ref_depth'    => 0,
    'target_count' => 12,
  ],
]
```

Only non-empty annotation values are included. Targets with no matching annotations are omitted when type-filtering (`skip_empty` behaviour in `assembleGroups()`). Group labels come from `DiscoveryService::getPlugins()` — consistent with the Targets page.

## Rendering

### ContextRenderer (markdown)

Outputs raw markdown — safe for file download. Heading hierarchy: H1 (entity type group) → H2 (target) → H3 (Fields/References) → H4 (field name). Annotation text as plain paragraphs.

### ContextHtmlRenderer (render arrays)

All annotation values are escaped via `Html::escape()` wrapped in `Markup::create()`. Uses `details/summary` for collapsible target cards. `h3` for Overview/Fields/References section labels. "Overview" label suppressed when the target has no fields or references.

### Writing a custom renderer

A renderer receives the plain PHP array from `ContextAssembler::assemble()` and converts it to any output format. No base class required — just consume the array structure documented above.

```php
class MyRenderer {
  public function render(array $payload): string {
    $output = '';
    foreach ($payload['groups'] as $group) {
      foreach ($group['targets'] as $target) {
        // $target['annotations'], $target['fields'], $target['references']
      }
    }
    return $output;
  }
}
```

**Security:** Always escape annotation values when producing HTML. They are stored raw (admin-input context is safe; end-user-facing output is not).

## Extending the assembler output

### Alter hook (built)

`ContextAssembler::assemble()` invokes `hook_annotations_context_alter()` at the end of every assembly. Implementations receive `$payload` by reference, `$options` as read-only context, and `$cacheableMetadata` by reference for contributing cache requirements.

```php
function mymodule_annotations_context_alter(array &$payload, array $options, CacheableMetadata &$cacheableMetadata): void {
  $payload['my_section'] = ['key' => 'value'];
  $cacheableMetadata->addCacheTags(['mymodule_data_list']);
}
```

`ContextPreviewController` automatically merges the assembled `CacheableMetadata` into its page build via `$assembler->getLastCacheableMetadata()->applyTo($build)`. Any caller producing cacheable output from the payload must do the same.

Appropriate when: a submodule or contrib module needs to add a flat section (e.g. `annotations_ai_context` appending model routing hints) and ordering between contributors does not matter.

### Tagged services (deferred)

The same pattern used by `annotations.target` plugins. Define an `annotations.context_provider` service tag; `ContextAssembler` collects tagged services at construction and calls `provideContext(array $options): array` on each, merging results in priority order. Providers also declare `getCacheableMetadata(array $options): CacheableMetadata` so the assembler can fold their cache requirements in automatically.

Preferred over the alter hook for contrib modules adding structured payload sections — discoverable, ordered, and consistent with the plugin pattern. Deferred until there is a concrete use case requiring ordering guarantees.

### Neither pattern requires the `AnnotationTypeFlag` plugin system

These extension points are about assembler *output*. Flags-on-annotation-types discussion in the root README is about assembler *input* (which types to include). Independent concerns, can be built at different times.

---

## JSON API endpoint

`GET /api/annotations/{target_id}` — returns the assembled payload for a single target as `CacheableJsonResponse`. Intended for headless consumers (Canvas, React, Mercury) that need annotation data without an AI dependency.

**Query parameters** (all optional, same semantics as preview page):
- `ref_depth=0|1|2` — entity reference traversal depth (default 0)
- `include_field_meta=1` — include field type/cardinality/description

**Responses:**
- 200: full assembler payload `{"groups": {...}, "meta": {...}}`
- 404: `{"error": "Annotation target not found."}` — cached against `annotation_target_list`

**Access:** filters types by the caller's actual permissions via `account => $this->currentUser()`. Does not support `role` simulation — that is a preview-page concern. The `administer annotations` bypass applies as usual.

**Caching:** `CacheableJsonResponse` with tags `annotation_list`, `annotation_target_list`, `annotation_type_list` and contexts `user.permissions`, `url.query_args`, `languages:language_interface` (+ `languages:content` on multilingual sites). Alter hook metadata is merged in. Does not ship a `?format=flat` option — raw assembler structure is the contract; consumers flatten if needed.

## Preview page

- Toolbar: filter form (left) + Download .md button (right)
- Filters: role simulation, specific target, ref depth, include field metadata
- Collapsed "Raw markdown" `details` drawer at bottom
- Export: `text/markdown` response, `Content-Disposition: attachment`, filename derived from active filters (e.g. `annotations-context-node-article.md`)

## View modes and annotation render context

Currently `ContextAssembler` assembles annotations for a `annotation_target` without any concept of which display mode or rendering context the entity will appear in. This is fine for documentation exports and AI context — you want the full picture regardless. But it becomes relevant in two future scenarios:

**1. View-page overlay (annotations_overlay):** The overlay hook fires in a specific display mode (e.g. `full`). A field may be in annotations scope but not rendered in that display mode. The overlay must check `EntityViewDisplay::getComponents()` for the active display mode before injecting a trigger — see `annotations_overlay/CLAUDE.md` for implementation detail. This is an overlay concern, not an assembler concern.

**2. AI context scoped to a display mode:** When a user is editing a node that renders in `full` mode on the public site, the AI assistant's context is most useful if it reflects what fields the user actually sees published. The assembler currently includes all fields in the `annotation_target` fields map regardless of display mode. A future `display_mode` option on `ContextAssembler::assemble()` could filter fields to only those rendered in a given display mode. Not urgent — the current "all fields in scope" approach is a reasonable default — but worth noting as the system matures and per-context AI guidance becomes more precise.

## Deferred

- `drush dot:export` — parked; trivially small when ready: call `ContextRenderer::render($assembler->assemble($options))` and pipe to stdout. Same filter options as the UI.
- `display_mode` assembler option — filter payload fields to those rendered in a given `EntityViewDisplay`; low priority, document intent above.

## Current status

- [x] `ContextAssembler` — all options, entity reference traversal, cycle detection, skip_empty
- [x] `hook_annotations_context_alter()` — invoked at end of assemble(); `CacheableMetadata` contract enforced
- [x] `ContextRenderer` — markdown output
- [x] `ContextHtmlRenderer` — render array output with XSS-safe value escaping
- [x] `ContextPreviewController` — preview page, export download; merges alter hook cache metadata
- [x] `ContextApiController` — JSON endpoint at `/api/annotations/{target_id}`; `CacheableJsonResponse` with full cache tag/context set
- [x] Routing, permissions, menu link
- [ ] Tagged service provider pattern (`annotations.context_provider`) — deferred; alter hook covers current needs

