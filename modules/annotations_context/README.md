# Annotations Context

Submodule of [Annotations](../../README.md). Assembles annotation data into a structured PHP array payload and ships two renderers (markdown, HTML render arrays) plus an admin preview UI.

The payload is the stable API surface: format-agnostic, permission-aware, and extensible via alter hook. `annotations_ai_context` consumes it to build LLM context; the preview page and markdown export are human-readable views of the same data.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_context
```

---

## What it provides

- **Context preview** at `/admin/config/annotations/context` — live HTML preview of assembled context, filterable by role, target, reference depth, and field metadata.
- **Role simulation** — preview context as any Drupal role sees it (respects `consume {type} annotations` permissions).
- **Markdown export** at `/admin/config/annotations/context/export` — downloads context as a `.md` file.
- **JSON API endpoint** at `/api/annotations/{target_id}` — returns the assembled payload for a single target as JSON, for headless consumers (Canvas, React, Mercury).

---

## Permissions

| Permission | Notes |
| --- | --- |
| `view annotations context` | Access the preview page and markdown export. Not `restrict access: true` — can be granted to non-admin roles (e.g. project managers reviewing documentation). |

`administer annotations` also grants access (OR logic in routing).

---

## Developer API

### ContextAssembler (`annotations_context.assembler`)

The central service. Builds a structured PHP array from annotation data. All other features in this module and its consumers derive from this payload.

```php
use Drupal\annotations_context\ContextAssembler;

$payload = $assembler->assemble();                                 // all targets, all types
$payload = $assembler->assemble(['entity_type' => 'node']);        // one entity type
$payload = $assembler->assemble(['target_id' => 'node__article']); // one target
$payload = $assembler->assemble(['types' => ['editorial']]);       // explicit type filter
$payload = $assembler->assemble(['ref_depth' => 1]);               // follow ER fields one hop
$payload = $assembler->assemble(['account' => $currentUser]);      // filter by user permissions
$payload = $assembler->assemble(['role' => 'editor']);             // simulate a role
$payload = $assembler->assemble(['include_incoming_refs' => TRUE]); // add reverse ER sources
```

All options are optional and combine freely.

#### Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `entity_type` | `string\|null` | `null` | Limit to targets of this entity type. |
| `target_id` | `string\|null` | `null` | Limit to a single target by machine name (e.g. `node__article`). |
| `types` | `string[]\|null` | `null` (all) | Explicit list of annotation type IDs to include. |
| `ref_depth` | `int` | `0` | Entity-reference traversal depth. `0` = no traversal; `1`–`2` follows linked targets. |
| `role` | `string\|null` | `null` | Simulate context as this Drupal role — only types that role can `consume` are included. Takes precedence over `account`. |
| `account` | `AccountInterface\|null` | `null` | Filter to types the given account can view via its combined role permissions. Accounts with `administer annotations` bypass filtering. |
| `include_field_meta` | `bool` | `false` | Add `type`, `cardinality`, and `description` to each field entry. Useful for AI context; noisy for human review. |
| `include_incoming_refs` | `bool` | `false` | Add an `incoming_refs` key to each target listing annotation targets that reference it via entity-reference fields. Flat only — no recursive reverse traversal. |

#### Role and account filtering

Use `role` to simulate what a role sees without impersonating a user — useful for previews and testing:

```php
$payload = $assembler->assemble(['role' => 'content_editor']);
```

Use `account` for real current-user context in live features. Accounts with `administer annotations` bypass all type filtering:

```php
$payload = $assembler->assemble(['account' => $this->currentUser]);
```

`role` takes precedence when both are supplied.

#### Payload structure

```php
[
  'groups' => [
    'node' => [
      'entity_type' => 'node',
      'label'       => 'Content types',
      'targets'     => [
        'node__article' => [
          'id'          => 'node__article',
          'label'       => 'Article',
          'entity_type' => 'node',
          'bundle'      => 'article',
          'annotations' => [
            'editorial' => ['label' => 'Editorial', 'value' => '...'],
            'rules'     => ['label' => 'Rules',     'value' => '...'],
          ],
          'fields' => [
            'body' => [
              'label'       => 'Body',
              'annotations' => ['editorial' => ['label' => 'Editorial', 'value' => '...']],
              // 'meta' key present when include_field_meta = TRUE:
              'meta' => ['type' => 'text_long', 'cardinality' => 'single value', 'description' => '...'],
            ],
          ],
          'references'    => [...], // only present when ref_depth > 0
          'incoming_refs' => [      // only present when include_incoming_refs = TRUE
            'media__image' => [
              'label'      => 'Image',
              'via_fields' => ['field_featured_image'],
            ],
          ],
        ],
      ],
    ],
  ],
  'meta' => [
    'generated_at'        => '2026-04-20T12:00:00+00:00',
    'ref_depth'           => 0,
    'include_incoming_refs' => FALSE,
    'target_count'        => 12,
  ],
]
```

Only non-empty annotation values are included. Targets with no matching annotations are omitted when type-filtering is active.

#### Cache metadata from alter implementations

If your code produces a cacheable page from an assembled payload, merge alter-contributed cache requirements:

```php
$payload = $assembler->assemble($options);
$assembler->getLastCacheableMetadata()->applyTo($build);
```

`ContextPreviewController` does this automatically.

---

### ContextRenderer (`annotations_context.renderer`)

Renders the payload to a UTF-8 markdown string. Stateless — no Drupal services involved. Safe for file download; values are not HTML-escaped (markdown is plain text).

```php
$markdown = $renderer->render($payload);
```

---

### ContextHtmlRenderer (`annotations_context.html_renderer`)

Renders the payload to a Drupal render array. All annotation values are escaped via `Html::escape()`. Uses `details/summary` collapsible cards.

```php
$build = $htmlRenderer->render($payload);
// Return directly from a controller.
```

---

### Writing a custom renderer

A renderer just consumes the plain PHP array — no base class required.

```php
class MyJsonRenderer {
  public function render(array $payload): string {
    $output = [];
    foreach ($payload['groups'] as $group) {
      foreach ($group['targets'] as $target) {
        $output[] = [
          'id'          => $target['id'],
          'annotations' => $target['annotations'],
          'fields'      => $target['fields'],
        ];
      }
    }
    return json_encode($output, JSON_PRETTY_PRINT);
  }
}
```

**Security:** Always escape annotation values when producing HTML. Values are stored raw — safe in admin-only contexts, must be escaped for any end-user-facing output.

---

## Entity reference traversal

Set `ref_depth` to follow entity reference fields into referenced targets:

- `0` (default) — no traversal; only the directly annotated target
- `1` — one hop (e.g. Article → referenced Media)
- `2` — two hops (recommended maximum; depth 3+ rarely adds useful signal and can produce very large payloads)

Each referenced target is assembled in full and nested under `references` → field name → target ID. Cycle detection prevents the same target appearing twice in a payload.

### Incoming references

Set `include_incoming_refs => TRUE` to surface **reverse** relationships — which annotation targets reference a given target, rather than which targets it references. Useful for leaf entities: when assembling context for `media__image`, you can see that `node__article` and `node__landing_page` both reference it.

```php
$payload = $assembler->assemble([
  'target_id'            => 'media__image',
  'include_incoming_refs' => TRUE,
]);
```

Each target entry gains an `incoming_refs` key keyed by source target ID:

```php
'incoming_refs' => [
  'node__article' => [
    'label'      => 'Article',
    'via_fields' => ['field_featured_image'],
  ],
  'node__landing_page' => [
    'label'      => 'Landing page',
    'via_fields' => ['field_hero_media', 'field_gallery'],
  ],
],
```

`via_fields` is always an array — a source target with two ER fields targeting the same bundle will list both. Only ER fields in the source target's annotation scope (`getFields()`) are considered, matching the forward traversal behaviour. Reverse traversal is flat — incoming sources are not themselves expanded.

---

## Extending the payload

`ContextAssembler::assemble()` invokes `hook_annotations_context_alter()` at the end of every assembly call. Use it to append, remove, or reshape payload sections.

```php
use Drupal\Core\Cache\CacheableMetadata;

function mymodule_annotations_context_alter(array &$payload, array $options, CacheableMetadata &$cacheableMetadata): void {
  // Append a section. Any top-level key not named 'groups' or 'meta' is yours.
  $payload['my_section'] = [
    'setting_a' => 'value',
    'setting_b' => TRUE,
  ];

  // Contribute cache requirements so callers invalidate correctly.
  $cacheableMetadata->addCacheTags(['mymodule_data_list']);
  $cacheableMetadata->addCacheContexts(['user.roles']);
}
```

The `$options` argument is the same array passed to `assemble()` — use it to conditionally modify the payload based on filters the caller applied (e.g. only inject extra data when a specific `entity_type` is requested).

Callers that produce cacheable output must merge the metadata:

```php
$payload = $assembler->assemble($options);
$assembler->getLastCacheableMetadata()->applyTo($build);
```

---

## JSON API endpoint

`GET /api/annotations/{target_id}` returns the assembled context payload as JSON. Designed for headless consumers that need annotation data without pulling in the AI module.

```code
GET /api/annotations/node__article
GET /api/annotations/node__article?ref_depth=1
GET /api/annotations/node__article?include_field_meta=1
GET /api/annotations/media__image?include_incoming_refs=1
```

**Query parameters** (all optional):

| Parameter | Values | Default | Description |
| --- | --- | --- | --- |
| `ref_depth` | `0`, `1`, `2` | `0` | Entity reference traversal depth. |
| `include_field_meta` | `1` | off | Include field type, cardinality, and description. |
| `include_incoming_refs` | `1` | off | Add `incoming_refs` to each target — reverse ER sources. |

**Responses:**

- **200** — full assembler payload (`groups` + `meta`), same structure as `ContextAssembler::assemble()`.
- **404** — `{"error": "Annotation target not found."}` when no `annotation_target` config entity exists for the given ID.

**Access:** requires `view annotations context` or `administer annotations`. Type visibility is filtered by the authenticated user's `consume {type} annotations` permissions — accounts with `administer annotations` see all types. Does not support `role` simulation; that is a preview-page concern.

**Caching:** returns a `CacheableJsonResponse` tagged with `annotation_list`, `annotation_target_list`, and `annotation_type_list`. Cache contexts: `user.permissions`, `url.query_args`, `languages:language_interface` (plus `languages:content` on multilingual sites). Any `hook_annotations_context_alter()` implementations that contribute cache metadata are folded in automatically.

**Response shape:**

```json
{
  "groups": {
    "node": {
      "entity_type": "node",
      "label": "Content types",
      "targets": {
        "node__article": {
          "id": "node__article",
          "label": "Article",
          "entity_type": "node",
          "bundle": "article",
          "annotations": {
            "editorial": { "label": "Editorial", "value": "..." }
          },
          "fields": {
            "body": {
              "label": "Body",
              "annotations": {
                "editorial": { "label": "Editorial", "value": "..." }
              }
            }
          }
        }
      }
    }
  },
  "meta": {
    "generated_at": "2026-04-21T12:00:00+01:00",
    "ref_depth": 0,
    "include_incoming_refs": false,
    "target_count": 1
  }
}
```

The response is the raw assembler structure. Consumers that need a flatter shape (`{field_name: {type_label: value}}`) should flatten client-side — no `?format=flat` option is provided.
