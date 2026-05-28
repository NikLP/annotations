# CLAUDE.md — annotations_audit

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Combines site structure scanning and annotation coverage reporting into a single audit module.

**Audit scan** crawls opted-in `annotation_target` entities on cron (and on demand) to produce a structured snapshot of the Drupal content architecture. It detects structural drift and stores a pending diff for review.

**Audit coverage** computes how completely each target has been annotated, driving the coverage report at `/admin/config/annotations/audit/coverage`. `CoverageService` is a stable public API for other modules to build on (workflow conditions, CI checks, export enrichment).

## What it owns

### Scan

- `ScanService` (`annotations_audit.scan_service`) — iterates opted-in targets via `AnnotationDiscoveryService`, calls each plugin's `discover()`, returns structured result; owns snapshot persistence and diff logic:
  - `scan()` — runs discovery, returns `array<target_id, data>`
  - `saveSnapshot(array $result)` — persists to `annotations_audit`, removes stale rows
  - `loadSnapshot()` — returns last saved snapshot keyed by target ID
  - `computeDiff(array $current, array $stored)` — returns `['added' => ..., 'removed' => ..., 'changed' => ...]`
  - `diffHasChanges(array $diff)` — returns `bool`
  - `getLastScanTimestamp()` — returns `?int`
  - `storePendingDiff()` / `getPendingDiff()` / `clearPendingDiff()` — state-backed pending diff display
- `ScanController` — audit scan page at `/admin/config/annotations/audit/scan`; shows last scan timestamp, snapshot table, and "Run scan now" button
- `ScanRunForm` — CSRF-protected one-button form to trigger a manual scan
- `AnnotationsAuditCommands` — `annotations:scan` (alias `ann:scan`); flags: `--fields`, `--format=json|yaml`, `--diff`, `--strict`
- DB table: `annotations_audit` (`target_id` PK, `data` longblob JSON, `saved` unix timestamp)
- State key: `annotations_audit.pending_diff`

### Coverage

- `CoverageService` (`annotations_audit.coverage_service`) — public API for coverage data; see below
- `affects_coverage` third-party setting on `AnnotationType` (key: `annotations_audit`) — whether a missing value of that type degrades coverage status. Default FALSE (opt-in).
- `CoverageController` — coverage report page at `/admin/config/annotations/audit/coverage`
- `CoverageFilterForm` — GET-based entity type + status filter form

### Shared

- `AnnotationsAuditHooks` — `hook_help`, `hook_theme`, `hook_cron`, `hook_form_annotation_type_form_alter`
- Templates: `annotations-coverage-gap-section.html.twig`, `annotations-coverage-gap-details.html.twig`
- Permissions: `view annotation audit coverage`, `administer annotations audit scan`

## CoverageService — public API

Inject `annotations_audit.coverage_service`.

```php
$coverage = $coverageService->getCoverage();
// Returns: array<string, array{
//   target: AnnotationTargetInterface,
//   status: 'complete'|'partial'|'empty',
//   missing: array<string, string[]>  // [type_id => ['overview', 'field_name', ...]]
// }>

$entry = $coverageService->getCoverageForTarget($target);

$score = $coverageService->getScore($coverage);
// Returns: array{
//   complete: int, total: int, percent: int,
//   filled_tracked: int, total_tracked: int,
//   filled_optional: int, total_optional: int,
// }

$affects = $coverageService->affectsCoverage($annotationType);
```

## affects_coverage — third-party setting

Stored as an `annotations_audit` third-party setting on `AnnotationType`. Default FALSE — opt-in only.

`annotations_type_ui` shows the checkbox on the annotation type edit form when `annotations_audit` is installed (injected via `hook_form_annotation_type_form_alter`).

## Scan snapshot structure

Each entry in the scan result is keyed by `{entity_type}__{bundle}`:

```php
[
  'entity_type' => 'node',
  'label'       => 'Article',
  'bundle'      => 'article',
  'fields'      => [
    'field_body' => ['label' => 'Body', 'type' => 'text_long', 'required' => false, 'cardinality' => 1, 'description' => ''],
  ],
]
```

## Coverage status definitions

| Status | Meaning |
| --- | --- |
| `complete` | All status-affecting annotation types filled at target level and for all included fields |
| `partial` | Target-level primary type filled, but gaps remain at field or secondary type level |
| `empty` | Primary status-affecting type is blank at target level |

## Workflow integration

`getCoverage()` and `getCoverageForTarget()` pass `published = TRUE` to `AnnotationStorageService::getForTarget()`. When `annotations_workflows` is installed, only published annotations count as filled. When not installed the filter is a no-op.

## Performance note

`getCoverage()` executes one DB query per target. At 100+ targets this becomes significant on every page load. The intended fix at scale is cron-driven snapshot storage. Deferred until target counts warrant it.
