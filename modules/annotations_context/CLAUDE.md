# CLAUDE.md — annotations_context

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Assembles annotation data into structured context payloads. Produces human-readable documentation (HTML preview + markdown download) and a PHP array payload that `dot_ai_context` can consume without depending on this module. No AI dependency — AI integration is handled by a separate consumer.

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
| `annotations_context.preview` | `/admin/config/annotations/context` | `view annotations context` OR `administer annotations` |
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

**`account`** — filter to types the given `AccountInterface` can view, using its combined permissions across all roles. Accounts with `administer annotations` bypass filtering. Use this for real current-user context in `dot_ai_context`; use `role` for simulation previews.

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

## Extending the assembler output (contrib extension points)

`ContextAssembler` currently has no external extension points — it assembles exactly what DOT knows about. Three patterns are available when a contrib module needs to inject additional content into the payload, in order of complexity:

### Alter hook (simplest)

DOT adds one `\Drupal::moduleHandler()->alter('annotations_context', $payload, $options)` call at the end of `ContextAssembler::assemble()`. Contrib modules implement `hook_annotations_context_alter()` to append, remove, or reshape sections. No infrastructure beyond that single call.

Appropriate when: a contrib module needs to add a flat section to the payload (e.g. a bot module appending a `bot_config` key) and ordering between contributors does not matter.

### Tagged services (preferred for structured contribution)

The same pattern used by `dot.target` plugins. DOT defines a `annotations.context_provider` service tag. `ContextAssembler` collects all tagged services at construction and calls a defined method on each (e.g. `provideContext(array $options): array`) when building the payload, merging the results in.

```yaml
# mymodule.services.yml
services:
  mymodule.annotations_context_provider:
    class: Drupal\mymodule\BotContextProvider
    tags:
      - { name: annotations.context_provider, priority: 10 }
```

```php
class BotContextProvider implements DotContextProviderInterface {
  public function provideContext(array $options): array {
    return ['bot' => ['frontend_enabled' => TRUE, ...]];
  }
}
```

Priority controls merge order. DOT iterates providers in priority order and merges each result into the payload before returning it. Providers declare an interface; DOT defines that interface.

This is the preferred approach for contrib modules that add structured payload sections, since it is discoverable (DOT can list registered providers), ordered, and consistent with the existing `dot.target` tagged service pattern.

### Event/subscriber

DOT dispatches a `DotContextBuildEvent` carrying the payload and options. Modules subscribe and mutate the event object. Functionally equivalent to the alter hook but typed. Worth considering if the codebase moves toward event-driven patterns more broadly; no advantage over tagged services for this specific use case.

### Caching contract for extension point providers

`ContextPreviewController` declares `#cache` tags and contexts covering DOT's own entities. If a contrib module contributes data to the payload via an alter hook or tagged provider, the controller's cache will not know to invalidate when that module's data changes.

**Required contract:** any provider contributing to the assembled payload must also contribute `CacheableMetadata`. For tagged providers, the interface should define a method alongside `provideContext()`:

```php
public function getCacheableMetadata(array $options): CacheableMetadata;
```

The assembler merges each provider's metadata before returning the payload. The controller then only needs to declare its own entity tags — provider tags are folded in automatically.

For the alter hook pattern, `hook_annotations_context_alter()` implementations should attach their cache requirements to a `CacheableMetadata` object passed by reference alongside `$payload`.

This contract does not exist yet (neither extension point is built). Document it here so it is designed in from the start rather than retrofitted.

### Neither pattern requires the `AnnotationTypeFlag` plugin system

These extension points are about assembler *output*. The flags-on-annotation-types plugin discussion in the root README is about assembler *input* (which types to include). They are independent concerns and can be built at different times.

---

## Preview page

- Toolbar: filter form (left) + Download .md button (right)
- Filters: role simulation, specific target, ref depth, include site-wide, include field metadata
- Collapsed "Raw markdown" `details` drawer at bottom
- Export: `text/markdown` response, `Content-Disposition: attachment`, filename derived from active filters (e.g. `annotations-context-node-article.md`)

## View modes and annotation render context

Currently `ContextAssembler` assembles annotations for a `annotation_target` without any concept of which display mode or rendering context the entity will appear in. This is fine for documentation exports and AI context — you want the full picture regardless. But it becomes relevant in two future scenarios:

**1. View-page overlay (dot_overlay):** The overlay hook fires in a specific display mode (e.g. `full`). A field may be in DOT scope but not rendered in that display mode. The overlay must check `EntityViewDisplay::getComponents()` for the active display mode before injecting a trigger — see `dot_overlay/CLAUDE.md` for implementation detail. This is an overlay concern, not an assembler concern.

**2. AI context scoped to a display mode:** When a user is editing a node that renders in `full` mode on the public site, the AI assistant's context is most useful if it reflects what fields the user actually sees published. The assembler currently includes all fields in the `annotation_target` fields map regardless of display mode. A future `display_mode` option on `ContextAssembler::assemble()` could filter fields to only those rendered in a given display mode. Not urgent — the current "all fields in scope" approach is a reasonable default — but worth noting as the system matures and per-context AI guidance becomes more precise.

## Deferred

- `drush dot:export` — parked; trivially small when ready: call `ContextRenderer::render($assembler->assemble($options))` and pipe to stdout. Same filter options as the UI.
- `display_mode` assembler option — filter payload fields to those rendered in a given `EntityViewDisplay`; low priority, document intent above.

## Current status

- [x] `ContextAssembler` — all options, entity reference traversal, cycle detection, skip_empty
- [x] `ContextRenderer` — markdown output
- [x] `ContextHtmlRenderer` — render array output with XSS-safe value escaping
- [x] `ContextPreviewController` — preview page, export download
- [x] Routing, permissions, menu link
- [ ] `drush dot:export` (parked)
