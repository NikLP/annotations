# Annotations UI

Submodule of [Annotations](../../README.md). Provides the annotation editing UI for writing and managing `annotation` content entities across all opted-in `annotation_target` targets.

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

### Landing page — `/admin/content/annotations`

Lists all opted-in `annotation_target` entities grouped by entity type. Each target row has a dropbutton with three operations:

- **Add new annotations** — shows missing annotation slots
- **Edit existing annotations** — embeds the `annotations_target` view for that target
- **Delete annotations** — bulk-delete all annotations for the target

### Per-target collection page — `/admin/content/annotations/{target}`

Embeds the `annotations_target` view, listing all existing `annotation` entities for the target with Edit and Delete operation links per row.

### Add-new page — `/admin/content/annotations/{target}/add`

Shows a table of annotation slots that are not yet filled. Each row is a field (or the target overview) with Add buttons for each missing annotation type. When all slots are filled, a link to the edit view is shown instead.

The first row is always **Overview** — the bundle-level slot that covers the target as a whole rather than any specific field (e.g. what the content type is for, when to use it). In storage this is an `annotation` entity with `field_name = ''`. The row URL uses `_overview` as the field name parameter; the controller converts it to the empty-string sentinel when creating the entity.

For fieldable targets, an optional collapsible "Target details" panel lists all annotatable fields with their scope status (can be toggled via `annotations_ui.settings`).

### Annotation edit form — `/admin/content/annotations/value/{annotation}/edit`

Full `ContentEntityForm` for a single `annotation` entity. Because `annotation` is a fieldable, revisionable, translatable entity (extending `EditorialContentEntityBase`):

- Revision log message and "Create new revision" checkbox appear in the Gin sidebar (checkbox defaults to checked)
- When `annotations_workflows` is installed, the `content_moderation_control` widget is injected automatically and controls revision creation
- Language tabs appear automatically when content translation is enabled for annotations

---

## Fieldable annotation entities

`annotation` is a fully fieldable Drupal content entity — it can have additional fields added via the field UI. The standard annotation fields (`target_id`, `field_name`, `type_id`, `value`, `uid`, `changed`) are base fields, but site-builders can attach arbitrary field config to any annotation type bundle.

---

## Revision history

Full revision history is available at `/admin/content/annotations/value/{annotation}/revisions`. Stock Drupal controllers handle revision view, revert, and delete — registered automatically by `RevisionHtmlRouteProvider`.

When the `diff` module is present, a "Compare with previous" operation link is added to each row in the revision history table.

---

## Multilingual / translation

`annotation` is defined as `translatable = TRUE` in its entity annotation. This means the four-table schema (`annotation`, `annotation_field_data`, `annotation_revision`, `annotation_field_revision`) is always created on install — the schema is fixed by the entity definition, not by whether the `content_translation` module is installed. `AnnotationStorageService` is language-aware: all read methods accept an optional `$langcode` parameter, falling back to the current content language, then to the default translation.

To enable annotation translation on a multilingual site, go to `/admin/config/regional/content-language` and enable translations for **Annotation**. No extra module required beyond `content_translation`.

---

## Permissions

| Permission | Notes |
| --- | --- |
| `edit any annotation` | Supersedes per-type edit permissions; gates the annotation forms. `restrict access: true`. |
| `access annotation overview` | Grants read access to `/admin/content/annotations` without granting write access. |
| `edit {type} annotations` | Per annotation type (e.g. `edit editorial annotations`). Generated dynamically from installed `AnnotationType` entities. |
| `consume {type} annotations` | Controls which annotation types appear in context output. Used by `ContextAssembler` in `annotations_context`. |
| `view annotation revisions` | View the revision history and individual revision pages. Does not grant revert or delete. |

Dynamic permissions require a cache rebuild after new annotation types are created.

The annotation add/create/edit forms filter the available annotation types to those the current user has `edit {type} annotations` permission for — users can only create and edit their permitted slice of annotation data.

### Typical role setup

| Role | Permissions |
| --- | --- |
| Drupal admin | `administer annotations` covers everything |
| Content editor | `access annotation overview` + `edit editorial annotations` |
| Developer | `access annotation overview` + `edit technical annotations` |
| Project manager | `access annotation overview` only (read-only listing) |
