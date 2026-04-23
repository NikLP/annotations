# annotations_coverage

Annotation coverage tracking and reporting for the Annotations module suite.

Owns the `affects_coverage` behavior on annotation types and provides `CoverageService` as a stable public API for any module that needs to read, report on, or enforce annotation completeness.

Coverage enforcement is deliberately not built into this module because enforcement is site policy, not mechanism. Different consumers need different behaviour: a workflow module blocks a publish transition if coverage is incomplete; a CI integration exits non-zero if any target is empty; a content dashboard surfaces a score badge. All of them need the same underlying calculation, but none of them should share an implementation. `CoverageService` is the shared, stable calculation layer. Enforcement belongs in the module that owns the policy — and that module injects this service to get the data it needs.

---

## What it provides

### Coverage report

Navigate to **Admin → Reports → Annotation coverage** (`/admin/reports/annotation-coverage`).

The report shows every opted-in target with a status (Complete / Partial / Empty), an aggregate coverage score, and an expandable gap breakdown per target. Filter by entity type or status.

### CoverageService

Inject `annotations_coverage.coverage_service` to access coverage data programmatically.

```php
use Drupal\annotations_coverage\CoverageService;

// All targets.
$coverage = $coverageService->getCoverage();
// Returns: array<target_id, array{target, status, missing}>

// Single target.
$entry = $coverageService->getCoverageForTarget($target);

// Aggregate score.
$score = $coverageService->getScore($coverage);
// ['complete' => int, 'total' => int, 'percent' => int, ...]

// Check whether a type affects status.
$affects = $coverageService->affectsCoverage($annotationType);
```

### affects_coverage behavior

Each `AnnotationType` can declare whether a missing annotation of that type degrades coverage status. This behavior is stored as an `annotations_coverage` third-party setting on the type config entity, so it only exists when this module is installed.

The checkbox appears on the annotation type edit form (provided by `annotations_type_ui`) when `annotations_coverage` is installed. Default: `FALSE` (opt-in).

---

## Status definitions

| Status | Meaning |
| --- | --- |
| `complete` | All status-affecting types filled at target and field level |
| `partial` | Target-level primary type filled, but field-level or secondary type gaps remain |
| `empty` | Primary status-affecting type is blank at target level |

The score percentage is slot-based: `(filled high-priority slots / total high-priority slots) × 100`.

---

## Building on top of CoverageService

`CoverageService` is the extension point. No hooks, plugins, or gate interfaces are needed — inject the service and call it.

**Workflow transition enforcement:**

```php
$entry = $coverageService->getCoverageForTarget($target);
if ($entry['status'] !== 'complete') {
  // block transition, set form error, etc.
}
```

**CI / Drush check:**

```php
$coverage = $coverageService->getCoverage();
$incomplete = array_filter($coverage, fn($r) => $r['status'] !== 'complete');
// report or exit non-zero
```

Annotations ships no enforcement. Policy belongs in the module that owns it.

---

## Workflow integration

When `annotations_workflows` is installed, only published annotations count toward coverage. Draft or needs-review annotations appear as gaps. Without `annotations_workflows` the filter is a no-op and all non-empty annotations count.
