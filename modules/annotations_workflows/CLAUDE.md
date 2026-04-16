# CLAUDE.md — annotations_workflows

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Ships the default annotations content moderation workflow as installable config. Nothing else.

## What it owns

- `config/install/workflows.workflow.annotations.yml` — the default workflow, enforced by this module so it cannot be accidentally removed via config sync.

## How bundles are attached — automatic, not hardcoded

The workflow YAML ships with `entity_types: {}` — no bundles attached. Annotation types are user-defined and cannot be hardcoded at install time.

Bundle attachment is handled automatically in two places:

- **`annotations_workflows_install()`** (procedural, in `.module`) — runs after the workflow config is imported; iterates all existing `annotation_type` entities and attaches each one. Covers the case where `annotations_workflows` is enabled after types already exist.
- **`AnnotationsWorkflowHooks::entityInsert()`** — fires on every new `annotation_type` save and attaches the workflow immediately.
- **`AnnotationsWorkflowHooks::entityDelete()`** — fires on `annotation_type` deletion and detaches the workflow to avoid orphaned bundle references.

`hook_install` must remain procedural — Drupal's `HookCollectorPass` explicitly forbids it as an OOP attribute hook.

## Default workflow: Annotations

States (all attached to the `annotation` entity type):

| State | Published | Default revision |
| --- | --- | --- |
| `draft` | No | No |
| `needs_review` | No | No |
| `published` | Yes | Yes |

Transitions and who should hold them:

| Transition | From | To | Intended for |
| --- | --- | --- | --- |
| `create_new_draft` | any | draft | all annotators |
| `submit_for_review` | draft | needs_review | all annotators |
| `publish` | draft, needs_review, published | published | annotation reviewers |
| `reject` | needs_review | draft | annotation reviewers |

Default moderation state on entity creation: `published`.

New annotations must be immediately visible in coverage reports and context output — a `draft` default would require republishing every annotation after workflow is enabled, breaking setup and testing. The review cycle is opt-in: editors move work to `draft` or `needs_review` when they want revision control.

## Content moderation permissions

`content_moderation` auto-generates `use {workflow_id} transition {transition_id}` permissions for every transition. With this workflow installed you get:

- `use annotations transition create_new_draft`
- `use annotations transition submit_for_review`
- `use annotations transition publish`
- `use annotations transition reject`

Assign `submit_for_review` to annotator roles. Assign `publish` and `reject` to reviewer roles. No Annotations code is involved here — standard Drupal permission assignment.

## How transitions are applied — via AnnotationEditForm

`annotation` entities are `EditorialContentEntityBase` with `translatable = TRUE` and proper `data_table`/`revision_data_table`. When a workflow is attached, `content_moderation` injects the `content_moderation_control` pseudo-field widget into `AnnotationEditForm` automatically. Saving the form applies both the value change and the state change in a single entity save — one revision per submit. No custom form altering is needed or present in this module.

## What this module does NOT do

- Does not alter any form.
- Does not define custom transition logic.
- Does not ship permissions configuration — assign `use annotations transition *` permissions via the Drupal roles UI or config.
- Does not prevent admins from attaching a different workflow to `annotation` via `/admin/config/workflow/workflows`. The shipped workflow is a default; sites can replace it.

## Draft over live — how it works

`annotation` is backed by four database tables:

| Table | Purpose |
| --- | --- |
| `annotation` | Base: entity ID, UUID, revision pointer |
| `annotation_field_data` | Current default-revision field values per language |
| `annotation_revision` | Revision metadata (author, date, log, default flag) |
| `annotation_field_revision` | All historical field values per revision per language |

When a draft revision is saved, `content_moderation` sets `isDefaultRevision = false`. Drupal's `SqlContentEntityStorage` only writes to `annotation_field_data` (the live table) when saving a default revision. Draft saves write only to `annotation_field_revision`. `getForTarget($id, TRUE)` reads from `annotation_field_data` — consumer contexts always see the last published value. `getLatestForTarget($id)` queries `allRevisions()` — editors see the current draft.

This is confirmed working by smoke tests on the 2.x branch.

## Current status

- [x] Default workflow config ships in `config/install`
- [x] Module description is accurate
- [x] No broken form altering code
- [ ] Role permission assignments (assign via UI / config after install)
