# Annotations UI

Submodule of [Annotations](../../README.md). Provides the annotation editing UI for writing overview text and per-field annotations on each opted-in `annotation_target`.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_ui
ddev drush cr
```

This module is optional. Sites where annotation content ships via `default_content` in a recipe can leave `annotations_ui` disabled, or enable it only when editing is needed.

---

## What it does

- **Target annotation form** at `/admin/content/annotations/{target}/annotate` — one accordion section per `AnnotationType`, one textarea per included field. Only types the current user has permission to write are shown.
- **Annotation landing page** at `/admin/content/annotations` — lists all opted-in targets grouped by entity type, with Annotate links.
- Adds an **Annotate** dropbutton to rows on the Targets overview page.

All annotation text is saved to `annotation` content entity rows (never to config) and survives `drush cim`.

---

## Multilingual / translation

`annotation` has `translatable = TRUE` and uses the full four-table schema regardless of whether `content_translation` is installed. To enable annotation translation on a multilingual site, go to `/admin/config/regional/content-language` and enable translations for **Annotation**. No extra module required.

---

## Permissions

| Permission | Notes |
| --- | --- |
| `edit any annotation` | Supersedes per-type edit permissions; gates the annotation forms. `restrict access: true`. |
| `access annotation overview` | Grants read access to `/admin/content/annotations` without granting write access. |
| `edit {type} annotations` | Per annotation type (e.g. `edit editorial annotations`). Generated dynamically from installed `AnnotationType` entities. |
| `consume {type} annotations` | Controls which annotation types appear in context output for a given role. Used by `ContextAssembler` when `role` option is passed. |
| `view annotation revisions` | View the revision history and individual revision pages. Does not grant revert or delete. |

Dynamic permissions require a cache rebuild after new annotation types are created.

### Typical role setup

| Role | Permissions |
| --- | --- |
| Drupal admin | `administer annotations` covers everything |
| Content editor | `access annotation overview` + `edit editorial annotations` |
| Developer | `access annotation overview` + `edit technical annotations` |
| Project manager | `access annotation overview` only (read-only listing) |

---

## Per-type permission filtering

The annotation form renders only the types the current user has `edit {type} annotations` permission for. Types the user cannot write are hidden from the form. Users can only edit the slice of annotation data they are permitted to touch.

---

## Role simulation

`ContextAssembler` accepts a `role` option. When set, only annotation types that role has `consume {type} annotations` permission for appear in the payload. The context preview page uses this for "View as role" simulation.
