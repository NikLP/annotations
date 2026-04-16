# CLAUDE.md — annotations_scan

Submodule of DOT. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture. This is the **execution layer** — plugin definitions, `DiscoveryService`, and the `annotation_target` entity all live in `dot`. `annotations_scan` is optional on sites where targets are pre-configured via recipe/profile and no on-demand scanning is needed.

## What it owns

- `ScanService` (`annotations_scan.scanner`) — iterates opted-in targets via `DiscoveryService`, calls each plugin's `discover()`, returns structured result
- `ScanController` — admin page at `/admin/config/annotations/scanner`; overview + "Run scan now" button
- `AnnotationsScanHooks` (`src/Hook/`) — `hook_help`, `hook_cron`; `.module` is an empty stub
- `administer annotations scanner` permission
- Logger channel `annotations_scan`

## Parked work

These are deferred until `dot_delta` needs them:

- **Snapshot storage** — `hook_schema` for a snapshot table; stores the last scan result per target
- **Drush commands:**
  - `dot:scan` — run a full scan per current scope
  - `dot:scan --diff` — scan and output delta against the last stored snapshot
  - `dot:scan --strict` — scan, diff, exit non-zero if annotation-relevant changes detected (pre-commit hook use)

## Current status

- [x] Module scaffold (info, module, permissions, routing, menu links)
- [x] `ScanController` — overview page + manual scan trigger
- [x] `ScanService` — thin executor; loads scopes, delegates plugin list to `DiscoveryService`
- [x] `AnnotationsScanHooks` class — `hook_help`, `hook_cron`
- [ ] Snapshot storage — `hook_schema` for snapshot table (parked)
- [ ] Drush commands (parked)
