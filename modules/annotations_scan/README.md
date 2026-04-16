# Annotations Scan

Submodule of [Annotations](../../README.md). Crawls opted-in `annotation_target` entities and produces a structured snapshot of the Drupal site's content architecture.

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

`annotations_scan` is the execution layer for discovery. The Target plugin system and `DiscoveryService` live in `annotations`; `annotations_scan` is a thin runner that:

1. Loads all opted-in `annotation_target` config entities.
2. Calls each target's plugin `discover()` method.
3. Returns a structured result (snapshot storage is parked — see below).

It also provides:

- A manual scan trigger at **Admin → Config → Annotations → Scanner** (`/admin/config/annotations/scanner`).
- Cron integration — runs a scan on each cron run.

---

## Permissions

| Permission | Notes |
| --- | --- |
| `administer annotations scanner` | Run scans and view scan results. `restrict access: true`. |

---

## Developer API

### ScanService (`annotations_scan.scanner`)

```php
$results = $scanService->scan();
// Returns a structured array of discovered target data.
// Shape depends on the Target plugins in use.
```

---

## Parked features

These features are deferred until `annotations_delta` (change detection) needs them:

- **Snapshot storage** — a database table storing the last scan result per target, enabling diffing.
- **Drush commands:**
  - `drush annotations:scan` — run a full scan per current scope.
  - `drush annotations:scan --diff` — scan and output delta against the last stored snapshot.
  - `drush annotations:scan --strict` — exit non-zero if annotation-relevant changes are detected (pre-commit hook use).
