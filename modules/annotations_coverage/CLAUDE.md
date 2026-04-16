# CLAUDE.md — annotations_coverage

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Owns the concept of annotation coverage: which annotation types matter for completeness, how status is computed per target, and what the aggregate score is across the site. Also ships the coverage report UI at `/admin/reports/annotation-coverage`.

Replaces the former `dot_report` module. The split is conceptual: coverage is a behaviour, not just a display. `CoverageService` is a stable public API — any module that needs to act on coverage data (enforce it, export it, gate a workflow transition on it) injects this service directly.

## What it owns

- `CoverageService` (`annotations_coverage.coverage_service`) — public API for coverage data; see below
- `affects_coverage` third-party setting on `AnnotationType` — whether a missing value of that type degrades coverage status. Default FALSE (opt-in).
- `AnnotationsCoverageHooks` — `hook_theme` (gap section template) + `hook_form_annotation_type_form_alter` (injects the affects_coverage checkbox into the type edit form)
- `CoverageController` — coverage report page at `/admin/reports/annotation-coverage`
- `CoverageFilterForm` — GET-based entity type + status filter form
- Template: `annotations-coverage-gap-section.html.twig`

## CoverageService — public API

Inject `annotations_coverage.coverage_service`.

```php
// Coverage across all targets, keyed by target ID.
$coverage = $coverageService->getCoverage();
// Returns: array<string, array{
//   target: AnnotationTargetInterface,
//   status: 'complete'|'partial'|'empty',
//   missing: array<string, string[]>  // [type_id => ['overview', 'field_name', ...]]
// }>

// Coverage for a single target.
$entry = $coverageService->getCoverageForTarget($target);

// Aggregate score.
$score = $coverageService->getScore($coverage);
// Returns: array{
//   complete: int, total: int, percent: int,
//   filled_tracked: int, total_tracked: int,   // affects_coverage = TRUE types
//   filled_optional: int, total_optional: int, // affects_coverage = FALSE types
// }

// Check whether a given annotation type affects coverage status.
$affects = $coverageService->affectsCoverage($annotationType);
```

## Status definitions

| Status | Meaning |
| --- | --- |
| `complete` | All status-affecting annotation types filled at target level and for all included fields |
| `partial` | Target-level primary type filled, but gaps remain at field or secondary type level |
| `empty` | Primary status-affecting type is blank at target level |

The "primary" type is the status-affecting type with the lowest weight. Only types where `affects_coverage = TRUE` (the `annotations_coverage` third-party setting) degrade status. Types with `affects_coverage = FALSE` appear in the `missing` array but never change the `status` value.

## affects_coverage — third-party setting

`affects_coverage` is stored as an `annotations_coverage` third-party setting on `AnnotationType`, not as a first-party entity property. This means:

- `annotations_coverage` can be uninstalled without leaving orphaned schema on the annotation type entity
- The flag only exists when coverage tracking is in use
- The default is `FALSE` — opt-in only; types must be explicitly enabled to affect coverage status
- `annotations_type_ui` shows the checkbox on the annotation type edit form only when `annotations_coverage` is installed (injected via `hook_form_alter`)

## Using CoverageService from other modules

Any module can inject `annotations_coverage.coverage_service` and call it directly. There is no plugin system, gate interface, or hook to implement — the service is the extension point.

Example: a workflow transition condition plugin that blocks transition if coverage is not complete:

```php
$entry = $this->coverageService->getCoverageForTarget($target);
if ($entry['status'] !== 'complete') {
  // block the transition
}
```

Example: a Drush command that exits non-zero if any target is empty:

```php
$coverage = $this->coverageService->getCoverage();
$empty = array_filter($coverage, fn($r) => $r['status'] === 'empty');
if (!empty($empty)) {
  throw new \RuntimeException('Coverage incomplete.');
}
```

Annotations does not ship any enforcement implementations. The service API is stable and intentionally minimal so that enforcement logic lives in the module that owns the policy.

## Workflow integration (annotations_workflows)

`getCoverage()` and `getCoverageForTarget()` pass `published = TRUE` to `AnnotationStorageService::getForTarget()`. When `annotations_workflows` is installed, only published annotations count as filled — draft and needs-review annotations appear as gaps. When `annotations_workflows` is not installed the filter is a no-op and all non-empty annotations count.

## Current status

- [x] `CoverageService` — tiered severity, status rollup, score calculation
- [x] `affects_coverage` third-party setting + form injection
- [x] `CoverageController` — score banner, filter form, expandable gap rows
- [x] `CoverageFilterForm`
- [x] Workflow-aware coverage (published-only when annotations_workflows installed)
- [ ] Cron-driven result caching (deferred until target counts become large)
- [ ] `CoverageController::buildGapCell()` uses `#prefix`/`#suffix` raw HTML for the `<details><summary>` wrapper — should be a theme function + Twig template for consistency with `annotations-coverage-gap-section.html.twig`

## Performance concern — N+1 query problem

`getCoverage()` executes **1 DB query per target** via `AnnotationStorageService::getForTarget()`. At 10 targets this is imperceptible. At 100+ targets it becomes a significant page load problem on every visit to `/admin/reports/annotation-coverage`. `getScore()` also calls `loadAnnotationTypes()` independently, adding a second annotation-type query on top of the one already issued by `getCoverage()`.

The correct fix at scale is a cron-driven snapshot: compute `getCoverage()` on cron, write the result to a dedicated table or `cache.default` with a long TTL, and display the cached snapshot with a "last calculated" timestamp. The page would then be a single cache read.

This is deliberately deferred until target counts are large enough to justify the complexity. Until then, render array caching on `CoverageController` (tags + contexts + max-age) is sufficient to avoid repeated full calculations within normal browse patterns.
