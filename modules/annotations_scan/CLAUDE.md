# CLAUDE.md — annotations_scan

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture. This is the **execution layer** — plugin definitions, `DiscoveryService`, and the `annotation_target` entity all live in `annotations`. `annotations_scan` is optional on sites where targets are pre-configured via recipe/profile and no on-demand scanning is needed.

## What it owns

- `ScanService` (`annotations_scan.scanner`) — iterates opted-in targets via `DiscoveryService`, calls each plugin's `discover()`, returns structured result; also owns snapshot persistence and diff logic:
  - `scan()` — runs discovery, returns `array<target_id, data>`
  - `saveSnapshot(array $result)` — persists result to `annotations_scan`, removing stale rows
  - `loadSnapshot()` — returns last saved snapshot keyed by target ID
  - `computeDiff(array $current, array $stored)` — returns `['added' => ..., 'removed' => ..., 'changed' => ...]`
  - `diffHasChanges(array $diff)` — returns `bool`
  - `getLastScanTimestamp()` — returns `?int` (unix timestamp of last save, or NULL)
- `ScanController` — admin page at `/admin/config/annotations/scanner`; shows last scan timestamp, snapshot target table, and "Run scan now" button; injects `ScanService` + `DateFormatterInterface`
- `AnnotationsScanHooks` (`src/Hook/`) — `hook_help`, `hook_cron`; `.module` is an empty stub
- `AnnotationsScanCommands` (`src/Drush/Commands/`) — `annotations:scan` (alias `ann:scan`); flags: `--fields`, `--format=json|yaml`, `--diff`, `--strict`
- `administer annotations scanner` permission
- Logger channel `annotations_scan`
- DB table: `annotations_scan` (`target_id` PK, `data` longblob JSON, `saved` unix timestamp)

## Edge annotation target discovery (deferred)

Edge annotations are stored as `annotation` entities with `target_id = {source}__{field}__{dest}` (e.g. `node__collection__field_products__node__product`). These edge target IDs are auto-derived by `ContextAssembler` at runtime from in-scope ER fields — no `annotation_target` config entity is created for them.

When an edge annotation UI is built, `annotations_scan` should gain an edge discovery step: walk in-scope ER fields on each target, compute the edge ID, and surface discoverable edges to the UI. Until then, edge annotations can only be created via Drush or direct DB insert.
