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

Sites that create & manage annotation types exclusively via config management do not need this module at all.

---

## What it does

Adds an **Annotation types** entry under Structure with CRUD for `AnnotationType` config entities (list, add, edit, delete). Rows are draggable — weight controls the order types appear in annotation forms and context output across the site (lower weight = shown first).

The add/edit form exposes the core config fields (`label`, `description`, `weight`) plus any behaviors injected by installed submodules via `hook_form_annotation_type_form_alter`.

---

## Permissions cache rebuild

When a new `AnnotationType` is created via this UI, its dynamically-generated permissions (`edit {type} annotations`, `consume {type} annotations`) **will not appear in People → Permissions** until caches are rebuilt:

```bash
ddev drush cr
```

The type itself works immediately in annotation forms. Only permission assignment is deferred.

---

## Config management

`AnnotationType` entities created here live only in the active config store until exported with `drush cex`. Running `drush cim` against un-exported types **will delete them**.

---

## Deletion

Deleting a type is permanent and removes all annotation rows with the corresponding type_id. The confirmation dialog shows how many annotations will be removed before you proceed. There is no undo.
