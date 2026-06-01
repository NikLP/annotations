# Annotations Audit

Submodule of the [Annotations](../../README.md) suite.

Combines site structure scanning and annotation coverage reporting. Crawls opted-in annotation targets on cron to snapshot the Drupal content architecture, then reports how completely each target has been annotated.

## Pages

| Path | Permission | Description |
| --- | --- | --- |
| `/admin/config/annotations/audit/coverage` | `view annotation audit coverage` | Coverage report with score, status per target, and gap details |
| `/admin/config/annotations/audit/scan` | `administer annotations audit scan` | Last waypoint timestamp, accumulated structural changes, scope drift (out-of-scope fields), snapshot grouped by entity type, "Check for changes" and "Save waypoint" buttons |

## Drush

```bash
# Run a scan, print summary, save snapshot
ddev drush annotations:scan

# Include field names in output
ddev drush ann:scan --fields

# Compare against last snapshot
ddev drush ann:scan --diff

# Exit non-zero if structural changes detected (pre-commit hook use)
ddev drush ann:scan --check

# Output as JSON or YAML
ddev drush ann:scan --format=json
```

## Coverage service

`CoverageService` (`annotations_audit.coverage_service`) is the public API for coverage data. Any module that needs to act on coverage — workflow conditions, CI checks, export enrichment — injects this service directly.

```php
$entry = $this->coverageService->getCoverageForTarget($target);
if ($entry['status'] !== 'complete') {
  // block the transition
}
```

## affects_coverage

Whether a given annotation type degrades coverage status is controlled by the `affects_coverage` flag on each annotation type. Configure it via the annotation type edit form (visible when this module is installed). Default is FALSE — opt-in only.
