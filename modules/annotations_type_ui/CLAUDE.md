# CLAUDE.md — annotations_type_ui

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Browser CRUD UI for managing `AnnotationType` config entities. **Site-building tool only.** Use it during initial project setup to define annotation types. It is not intended for ongoing use once editors are writing annotation content on production.

## What it owns

- `AnnotationsTypeUiHooks` — `hook_entity_type_alter` registers form handlers, list builder class, and link templates on `annotation_type`
- `AnnotationTypeListBuilder` — extends `DraggableListBuilder`; drag-to-reorder saves weight directly to config entities
- `AnnotationTypeForm` — add/edit: label, machine name, description, behavior fieldset (populated by submodules via form alter), `weight`
- `AnnotationTypeDeleteForm` — deletes all `annotation` rows for the type before removing the config entity
- 4 routes (collection / add / edit / delete for `annotation_type`)
- "Structure" menu entry under DOT admin

## Important: permissions cache rebuild

`AnnotationsPermissions::permissions()` generates `edit {type} annotations` and `view {type} annotations` dynamically via `permission_callbacks`. Drupal caches the permission list. When a new `AnnotationType` is created via this UI, its permissions **will not appear in People → Permissions until caches are rebuilt** (`ddev drush cr` or `/admin/config/development/performance`). The type itself is immediately usable in annotation forms — only permission assignment is blocked until the cache is cleared.

## Important: config-as-code

Types and sections created via this UI exist only in the active config store (DB) until exported with `drush cex`. Running `drush cim` against un-exported config **will delete UI-created entities**. Always run `drush cex` after structural changes made through this module and commit the resulting YML files.

## On deletion and annotation data

The delete form calls `AnnotationStorageService::deleteForType($type_id)` before removing the config entity — removes all `annotation` rows with that `type_id` across all targets. This is permanent. There is no undo.

## Current status

- [x] Full CRUD for `AnnotationType`: list, add, edit, delete (with DB cleanup)
- [x] `DraggableListBuilder` implementation with weight ordering
- [x] Routing, menu links, action links
- [x] `administer annotation types` permission — gates all 4 CRUD routes (collection / add / edit / delete)
