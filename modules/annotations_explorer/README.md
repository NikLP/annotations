# Annotations Explorer

Part of the [Annotations](../../README.md) module suite.

Provides a read-only viewer for browsing annotations at `/annotations/explorer`. Two-panel layout: target navigation on the left, annotation content on the right. Clicking a target loads its annotations via AJAX.

Access requires at least one `consume {type} annotations` permission — no admin role needed. Annotation types are filtered by the current user's consume permissions. Targets with no visible content are hidden from the nav entirely.

When `annotations_ui` is installed, an "Edit annotations" button appears inline with the target heading (where permitted).

## Requirements

- `annotations` (core module)
- `annotations_ui` (optional — enables "Edit" button where applicable)

## Installation

```bash
ddev drush en annotations_explorer
```
