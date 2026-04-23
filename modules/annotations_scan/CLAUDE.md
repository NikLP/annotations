# CLAUDE.md — annotations_scan

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture. This is the **execution layer** — plugin definitions, `DiscoveryService`, and the `annotation_target` entity all live in `annotations`. `annotations_scan` is optional on sites where targets are pre-configured via recipe/profile and no on-demand scanning is needed.

## What it owns

- `ScanService` (`annotations_scan.scanner`) — iterates opted-in targets via `DiscoveryService`, calls each plugin's `discover()`, returns structured result
- `ScanController` — admin page at `/admin/config/annotations/scanner`; overview + "Run scan now" button
- `AnnotationsScanHooks` (`src/Hook/`) — `hook_help`, `hook_cron`; `.module` is an empty stub
- `AnnotationsScanCommands` (`src/Drush/Commands/`) — `annotations:scan` (alias `ann:scan`); flags: `--fields`, `--format=json|yaml`
- `administer annotations scanner` permission
- Logger channel `annotations_scan`

