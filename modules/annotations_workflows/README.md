# annotations_workflows

Submodule of [Annotations](../../README.md). Ships the default annotations content moderation workflow. Installing this module gives every `annotation` entity a three-state editorial lifecycle out of the box.

---

## Requirements

- `annotations` (core Annotations module)
- `annotations_ui`
- `drupal:content_moderation`
- `drupal:workflows`

---

## Installation

```bash
ddev drush en annotations_workflows
ddev drush cr
```

The workflow attaches itself to all existing annotation types automatically on install, and tracks any types added or removed afterwards. No manual configuration required.

---

## Bundle attachment

Annotation types are user-defined, so they cannot be hardcoded into the workflow YAML. Instead:

- **On install** — `hook_install` iterates all existing `annotation_type` entities and attaches each to the workflow.
- **On type create** — `hook_entity_insert` attaches the workflow to the new type immediately.
- **On type delete** — `hook_entity_delete` detaches the workflow to avoid orphaned bundle references.

---

## What it does

Installs a single piece of config: `workflows.workflow.annotations`. This workflow is enforced by the module — it cannot be accidentally removed via `drush cim`. All behaviour comes from Drupal core's `content_moderation` module; this module contains no custom code.

---

## Default workflow: Annotations

### States

| State | Published | Default revision |
| --- | --- | --- |
| `draft` | No | No |
| `needs_review` | No | No |
| `published` | Yes | Yes |

### Transitions

| Transition | From | To |
| --- | --- | --- |
| Save as Draft | any | draft |
| Submit for Review | draft | needs_review |
| Publish | draft, needs_review, published | published |
| Reject to Draft | needs_review | draft |

Default moderation state on entity creation: `published`.

New annotations land as published immediately so they are visible in coverage reports and context output from the first save. The review cycle applies to subsequent edits — editors move work back to `draft` or `needs_review` when revision control is needed.

---

## Permissions

`content_moderation` auto-generates one permission per transition:

| Permission |
| --- |
| `use annotations transition create_new_draft` |
| `use annotations transition submit_for_review` |
| `use annotations transition publish` |
| `use annotations transition reject` |

### Typical role setup

| Role | Permissions |
| --- | --- |
| Annotator | `create_new_draft`, `submit_for_review` |
| Reviewer | `publish`, `reject` |
| Drupal admin | all (bypasses checks) |

Assign via the standard Drupal roles UI or config.

---

## This module is optional once config is exported

`annotations_workflows` ships a sensible default and handles automatic bundle attachment. It is not permanently required. Once the workflow is configured to your satisfaction and exported with `drush cex`, the workflow config lives in `config/sync` and is managed by config sync independently of this module.

At that point you can uninstall `annotations_workflows` — the workflow itself will persist. The only thing you lose is automatic attachment of newly created annotation types to the workflow; you would need to do that manually via `/admin/config/workflow/workflows`.

**This is the intended path for recipes and preconfigured demo content:** install `annotations_workflows`, configure types and annotations, export config, include the exported `workflows.workflow.annotations.yml` in the recipe alongside the annotation type and target config. The module does not need to ship with the recipe.

## Using a different workflow

Leave `annotations_workflows` disabled and attach any workflow to `annotation` via `/admin/config/workflow/workflows`.

---

## How moderation integrates with the annotation edit form

When this module is enabled, `content_moderation` automatically injects the moderation control widget into `AnnotationEditForm`. One form save = one revision = value change and state change together. No custom form code is needed or present in this module.

Draft saves write only to `annotation_field_revision` (not the live `annotation_field_data` table). Published saves become the default revision and update the live table. Coverage reports read only published (default) revisions; the annotation edit form shows the latest revision regardless of state.
