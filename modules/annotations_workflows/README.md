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

## This module ships no custom code

`annotations_workflows` is entirely configuration plus two hook methods for bundle attachment. There is no custom workflow logic, no form altering, and no transition handling — all workflow behaviour comes from Drupal core's `content_moderation` module.

What this module actually does:

1. **Ships a workflow YAML** at `config/install/workflows.workflow.annotations.yml`, enforced so it cannot be accidentally dropped by `drush cim`.
2. **Attaches annotation types automatically** via `hook_install` (existing types) and `hook_entity_insert` / `hook_entity_delete` (future types). This is the only runtime behaviour the module adds.

Both are convenience scaffolding. Neither is difficult to replicate manually.

---

## DIY without this module

You do not need `annotations_workflows` to have content moderation on annotations. To set it up yourself:

1. Enable `content_moderation` and `workflows` directly.
2. Go to `/admin/config/workflow/workflows` and create a new workflow.
3. Set the entity type to **Annotation** and select the bundles (annotation types) you want to moderate.
4. Configure states and transitions to match your editorial process.
5. Run `drush cex` to export the workflow config into your `config/sync` directory.

From that point forward, config sync manages the workflow. No module required to keep it alive.

The only thing you are skipping is automatic bundle attachment: when you create a new annotation type in future, you will need to visit `/admin/config/workflow/workflows` and add the new bundle manually. That is a one-time admin action per new type.

### Skipping the module entirely in a recipe or distribution

If you are building a recipe or preconfigured install profile, you do not need to ship `annotations_workflows` at all. Instead, include the exported `workflows.workflow.annotations.yml` directly in your recipe's `config/` directory alongside your annotation type and target config. The workflow installs from config; bundle attachment is handled at recipe import time by the workflow YAML itself (the `entity_types` key lists which bundles are attached).

The module exists to make setup easier in interactive installs. If you are scripting or automating the install, bypass it entirely.

---

## This module is optional once config is exported

If you did use `annotations_workflows` to get up and running, you can uninstall it once you have exported config. The workflow itself persists in `config/sync` and continues to apply. You only lose the automatic bundle attachment hooks — new annotation types will not be attached to the workflow automatically and will need to be added manually via `/admin/config/workflow/workflows`.

**Recommended path for recipes:** install `annotations_workflows`, create annotation types, run `drush cex`, include the resulting `workflows.workflow.annotations.yml` in the recipe. Drop the module from the recipe's dependencies — it is not needed at runtime.

---

## How moderation integrates with the annotation edit form

When this module is enabled, `content_moderation` automatically injects the moderation control widget into `AnnotationEditForm`. One form save = one revision = value change and state change together. No custom form code is needed or present in this module.

Draft saves write only to `annotation_field_revision` (not the live `annotation_field_data` table). Published saves become the default revision and update the live table. Coverage reports read only published (default) revisions; the annotation edit form shows the latest revision regardless of state.
