# Annotations Scan

Submodule of [Annotations](../../README.md). Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture. Stores snapshots to a database table so subsequent runs can diff for structural changes.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_scan
```

This module is optional. Sites where targets are pre-configured via a recipe or profile and no on-demand scanning is needed can leave `annotations_scan` disabled.

---

## What it does

`annotations_scan` is the execution layer for discovery. The Target plugin system and `DiscoveryService` live in `annotations`; `annotations_scan`:

1. Loads all opted-in, enabled `annotation_target` config entities.
2. Calls each target's plugin `discover()` method.
3. Returns a structured result keyed by `{entity_type}__{bundle}`.
4. Saves the result as a snapshot to the `annotations_scan_snapshot` database table.

It also provides:

- A manual scan trigger at **Admin → Config → Annotations → Scanner** (`/admin/config/annotations/scanner`). Each manual run saves a new snapshot.
- Cron integration — runs a full scan and saves a snapshot on each cron run.
- Drush commands for CI and pre-commit hook use (see below).

---

## Permissions

| Permission | Notes |
| --- | --- |
| `administer annotations scanner` | Run scans and view scan results. `restrict access: true`. |

---

## Drush commands

### `annotations:scan` (alias `ann:scan`)

Runs a full scan against all opted-in targets, saves the result as the current snapshot, and prints a summary.

```bash
drush annotations:scan
drush ann:scan --fields          # Show field names instead of field count
drush ann:scan --format=json     # Output the full scan result as JSON
drush ann:scan --format=yaml     # Output the full scan result as YAML
```

### `ann:scan --diff`

Runs a scan, shows a table of structural changes since the last stored snapshot, then saves the new snapshot.

```bash
drush ann:scan --diff
drush ann:scan --diff --format=json    # Machine-readable delta
```

### `ann:scan --strict`

Like `--diff`, but exits non-zero if any annotation-relevant structural changes are detected. Does **not** save the snapshot — intended for use in pre-commit hooks or CI gates where you want to block on unreviewed changes.

```bash
drush ann:scan --strict
```

**Pre-commit workflow:** run `ann:scan` to accept the current state, then add `ann:scan --strict` to your pre-commit hook. Commits will be blocked if the site structure has changed since the last accepted scan.

---

## Developer API

### ScanService (`annotations_scan.scanner`)

```php
$scanner = \Drupal::service('annotations_scan.scanner');

// Run a scan (does not persist anything).
$result = $scanner->scan();

// Persist the scan result as the current snapshot.
$scanner->saveSnapshot($result);

// Load the last saved snapshot.
$stored = $scanner->loadSnapshot();

// Compute a structural diff between two scan results.
$diff = $scanner->computeDiff($result, $stored);
// $diff = [
//   'added'   => [ target_id => data, ... ],
//   'removed' => [ target_id => data, ... ],
//   'changed' => [
//     target_id => [
//       'fields_added'   => ['field_name', ...],
//       'fields_removed' => ['field_name', ...],
//       'fields_changed' => ['field_name', ...],  // any property change
//     ],
//     ...
//   ],
// ]

// Check whether a diff contains any changes.
$has_changes = $scanner->diffHasChanges($diff);
```

### Snapshot table

`annotations_scan_snapshot` — one row per `{entity_type}__{bundle}` target:

| Column | Type | Notes |
| --- | --- | --- |
| `target_id` | `varchar(255)` PK | `{entity_type}__{bundle}` |
| `data` | `longblob` | JSON-encoded scan result for this target |
| `saved` | `int unsigned` | Unix timestamp of last save |

---

## What's still missing

- **`annotations_delta` module** — not started. The snapshot layer here is the foundation for `annotations_delta`, which is intended to expose change-detection output as a higher-level reporting surface (dashboard, notification triggers, etc.).
