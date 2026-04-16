# Annotations Type UI

Submodule of [Annotations](../../README.md). Browser CRUD UI for managing `AnnotationType` config entities.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_type_ui
```

---

## Intended use

**Site-building tool only.** Use this module during initial project setup to define annotation types without editing YAML directly. Disable or uninstall it when site building is complete — it is not intended for production use while editors are writing annotation content.

Sites that manage annotation types exclusively via `drush cim`/`drush cex` do not need this module at all.

---

## What it does

Adds an **Annotation types** entry under Structure with CRUD for `AnnotationType` config entities (list, add, edit, delete). Draggable rows control `weight` ordering.

The add/edit form exposes the core config fields (`label`, `description`, `weight`) plus any flags injected by installed submodules via `hook_form_annotation_type_form_alter`.

---

## Important: permissions cache rebuild

When a new `AnnotationType` is created via this UI, its dynamically-generated permissions (`edit {type} annotations`, `consume {type} annotations`) **will not appear in People → Permissions** until caches are rebuilt:

```bash
ddev drush cr
```

The type itself works immediately in annotation forms. Only permission assignment is deferred.

---

## Important: config-as-code

Types created via this UI exist only in the active database config store until exported:

```bash
ddev drush cex
git add config/sync/annotations.annotation_type.*.yml
git commit -m "Add annotation types"
```

Running `drush cim` against un-exported config **will delete UI-created entities**.

---

## On deletion

Deleting an annotation type via this UI is permanent and removes all associated annotation text from the database:

- Deleting an `AnnotationType` removes all `annotation` rows with that `type_id` across all targets.

There is no undo. Export a database backup before deleting types that have annotation content.
