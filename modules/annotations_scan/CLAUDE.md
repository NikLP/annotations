# CLAUDE.md — annotations_scan

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture. This is the **execution layer** — plugin definitions, `DiscoveryService`, and the `annotation_target` entity all live in `annotations`. `annotations_scan` is optional on sites where targets are pre-configured via recipe/profile and no on-demand scanning is needed.

## What it owns

- `ScanService` (`annotations_scan.scanner`) — iterates opted-in targets via `DiscoveryService`, calls each plugin's `discover()`, attaches outbound edges via `EdgeEnumerator`, returns structured result; also owns snapshot persistence and diff logic:
  - `scan()` — runs discovery + edge enumeration, returns `array<target_id, data>`; each entry may contain an `edges` key (see snapshot structure below)
  - `saveSnapshot(array $result)` — persists result to `annotations_scan`, removing stale rows
  - `loadSnapshot()` — returns last saved snapshot keyed by target ID
  - `computeDiff(array $current, array $stored)` — returns `['added' => ..., 'removed' => ..., 'changed' => ...]`; `changed` entries include `edges_added` and `edges_removed` alongside `fields_*`
  - `diffHasChanges(array $diff)` — returns `bool`
  - `getLastScanTimestamp()` — returns `?int` (unix timestamp of last save, or NULL)
- `ScanController` — admin page at `/admin/config/annotations/scanner`; shows last scan timestamp, snapshot target table (Target, Label, Fields, Edges), and "Run scan now" button; injects `ScanService` + `DateFormatterInterface`
- `AnnotationsScanHooks` (`src/Hook/`) — `hook_help`, `hook_cron`; `.module` is an empty stub
- `AnnotationsScanCommands` (`src/Drush/Commands/`) — `annotations:scan` (alias `ann:scan`); flags: `--fields`, `--format=json|yaml`, `--diff`, `--strict`
- `administer annotations scanner` permission
- Logger channel `annotations_scan`
- DB table: `annotations_scan` (`target_id` PK, `data` longblob JSON, `saved` unix timestamp)

## Snapshot structure

Each entry in the scan result (and stored snapshot) is keyed by `{entity_type}__{bundle}` and has the shape:

```php
[
  'entity_type' => 'node',
  'label'       => 'Article',
  'fields'      => [
    'field_body' => ['label' => 'Body', 'type' => 'text_long', ...],
    // ...
  ],
  'edges' => [
    // Keyed by edge ID: {source}__{field}__{dest}
    'node__article__field_products__node__product' => [
      'edge_id'     => 'node__article__field_products__node__product',
      'field_name'  => 'field_products',
      'field_label' => 'Products',
      'dest_id'     => 'node__product',
      'dest_label'  => 'Product',
    ],
  ],
]
```

`edges` is always present after a scan but may be empty (`[]`) for targets with no in-scope ER fields pointing to other registered targets. Edge IDs follow `EdgeEnumerator`'s convention (`{source}__{field}__{dest}`). Non-fieldable targets (roles, views, menus) always have an empty `edges` map.

Edge discovery uses `EdgeEnumerator` (`annotations.edge_enumerator`) from the root module — no dependency on `annotations_context`.
