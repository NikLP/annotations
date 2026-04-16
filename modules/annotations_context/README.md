# Annotations Context

Submodule of [Annotations](../../README.md). Assembles annotation data into structured context payloads — human-readable documentation (HTML preview, markdown download) and a PHP array payload that AI consumers can reuse. No AI dependency.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_context
```

---

## What it does

- **Context preview** at `/admin/config/annotations/context` — live HTML preview of assembled context, filterable by annotation type, entity type, and reference depth.
- **Role simulation** on the preview page — preview context as a specific Drupal role sees it.
- **Markdown export** at `/admin/config/annotations/context/export` — downloads context as a `.md` file, filename derived from active filters.

---

## Permissions

| Permission | Notes |
| --- | --- |
| `view annotations context` | Access preview and export. Not `restrict access: true` — can be granted to non-admin roles. |

`administer annotations` also grants access (OR logic in routing).

---

## Developer API

### ContextAssembler (`annotations_context.assembler`)

Builds the context payload from annotation data. Inject `annotations_context.assembler`.

```php
$payload = $assembler->assemble([
  // All options are optional.
  'entity_type'  => 'node',           // limit to one entity type
  'target_id'    => 'node__article',  // limit to a single target
  'types'        => ['editorial'],    // explicit type IDs to include
  'ref_depth'    => 1,                // entity reference traversal depth (0–2)
  'role'         => 'editor',         // filter types to those this role can view
]);
```

The `role` option filters annotation types to those the given Drupal role has `consume {type} annotations` permission for. Use this when assembling context for role-scoped delivery (e.g. in `dot_ai_context`).

### Payload structure

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
          ],
          'fields' => [
            'body' => [
              'label'       => 'Body',
              'annotations' => ['editorial' => ['label' => 'Editorial', 'value' => '...']],
            ],
          ],
          'references' => [...], // only present when ref_depth > 0
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

Only non-empty annotation values are included. Targets with no matching annotations are omitted when type-filtering.

### ContextRenderer (`annotations_context.renderer`)

Renders the payload to markdown. Stateless — no constructor injection needed.

```php
$markdown = $renderer->render($payload);
```

### ContextHtmlRenderer (`annotations_context.html_renderer`)

Renders the payload to Drupal render arrays. All annotation values are XSS-escaped.

```php
$build = $htmlRenderer->render($payload);
// Returns a render array suitable for returning from a controller.
```

### Writing a custom renderer

A renderer just consumes the plain PHP array from `assemble()`:

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
    return json_encode($output);
  }
}
```

**Security:** Always escape annotation values when rendering HTML. Values are stored raw — safe in admin-only contexts, but must be escaped for any end-user-facing output.

---

## Entity reference traversal

Set `ref_depth` to follow entity reference fields into referenced targets:

- `0` (default) — no traversal; only the directly annotated target
- `1` — one hop (e.g. Article → referenced Media)
- `2` — two hops (recommended maximum to avoid payload bloat)

Cycle detection prevents infinite loops. Field definitions are cached per-request.
