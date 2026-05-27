# Annotations

Annotations reads a Drupal site's structure — content types, fields, taxonomies, user roles, anything that's an entity — and maps that to a structured annotation system.

## Annotations can be consumed in several ways

User onboarding / Editing:

- contextual help overlays while creating/editing entities - help onboard new users with complex forms
- same for when viewing content - help text on learning materials or new user orientation
- annotation insertion into entity select screens - additional help text on e.g. /node/add to assist with selection

AI:

- scoped input for an AI agent - returns component-specific human-curated context
- MCP-compliant JSON endpoint for pulling context info into other 'applications' (WIP!)

Product documentation:

- export your annotations and ship them with your module/product! - exported as default content, only core module plus a consumer reqd.
- human- or machine-readable documentation export - provides drush commands to get scoped annotations on the CLI

## Annotation entities support

- multiple, fieldable annotation types
- revisions (`diff` support provided)
- content moderation
- workflows (via supplied integration module)
- translation (via language switcher)
- dynamic permissions system for edit/consume annotations

## Annotation type entities support

- 'behaviors' driven by third party settings for interfacing with other modules. (see [DEVELOPING.md](DEVELOPING.md))

## Annotation target entities

- can be created on any entity target that exposes a plugin via the extensible system provided. (see [DEVELOPING.md](DEVELOPING.md))
- target plugins already provided: [generic], paragraphs, media, node, view, user, taxonomy, etc. Additional modules provide interoperability with webform, profile.

---

## Requirements

- Drupal 11
- PHP 8.3+

The core `annotations` module suite has no contributed module dependencies.
Submodules may require contributed modules — see each module's README for details.

`annotations_workflows` requires the core `content_moderation` module, which optionally requires the contributed `diff` module.
`annotations_webform` requires the contributed `webform` module.
`annotations_profile` requires the contributed `profile` module.

Annotations is optimised slightly for the Gin theme, given that the expectation is this will be the core admin theme from Drupal 12 moving forwards.

---

## Installation

Install annotations base module; enable submodules as needed.

---

## The module suite

*All* modules should be considered a work in progress at this time! That said, the core suite has undergone the most scrutiny, that is: `[core]`, `_ui`,`_type_ui`.

| Module | Who it is for | Status | Purpose |
| --- | --- | --- | --- |
| `annotations` | All | Stable | Core — entities, plugin system, scope UI. Always required. |
| `annotations_type_ui` | Agency / setup only | Stable | Browser-based CRUD for annotation types. Site-building tool only — use during initial setup; not for general production use. |
| `annotations_ui` | Agency + editors | Stable | Annotation editing UI with revision history and moderation controls. The primary authoring interface. |
| `annotations_context` | Agency / dev | Stable | Assembles annotations into a structured payload. Provides an admin preview, markdown export, Drush export (`drush ann:ex`, provided by `annotations_export`), JSON API endpoint, and the shared payload consumed by `annotations_ai_context`. |
| `annotations_overlay` | Editors / end users | Largely stable | In-context help overlays: field-level and bundle-level "?" triggers on entity edit forms, opt-in view-page overlays (via Manage Display), bundle chooser page descriptions, and paragraph subform support. |
| `annotations_explorer` | Agency + editors | Stable | Read-only two-panel browser at (by default) `/annotations/explorer`. Consume-permission filtered; targets with no visible content are hidden from the nav. |
| `annotations_export` | Agency / dev | Stable | **Drush-only** export to markdown or Obsidian vault. No web UI; delegates to `annotations_context` for assembly. |
| `annotations_ai_context` | Agency / dev | WIP | Bridges `annotations_context` into [AI Context](https://www.drupal.org/project/ai_context) (CCC) by injecting assembled annotations documentation into AI agent system prompts. Opt annotation types in via their edit form. Currently requires patched version of the AI Context. |
| `annotations_workflows` | Agency / dev | Stable | Ships the default three-state editorial workflow (`draft → needs_review → published`) for annotation entities. NB - any `content_moderation` workflow can be attached manually instead. |
| `annotations_webform` | Dev / site builders | Stable | Webform compatibility for the `overlay` module. Requires the contributed `webform` module. |
| `annotations_profile` | Dev / site builders | Stable | Profile compatibility for the `overlay` module, in user account edit and registration forms. Requires the contributed `profile` module. |
| `annotations_scan` | Agency / dev | Stable | Crawls targets on demand and via cron. Provides a manual trigger UI, `drush ann:scan` with `--diff` and `--strict` flags for change detection, and snapshot storage for diffs. |
| `annotations_coverage` | Agency / dev | Stable | Annotation coverage tracking and report. Owns the `affects_coverage` behavior on types and exposes `CoverageService` as a stable public API for enforcement or CI use. |

---

## Core concepts

### Annotation targets

One annotation target per annotatable unit — one per content type, taxonomy vocabulary, user role, etc. Defines which entity types and which fields are in scope for annotation.

### Annotation content

Annotation content is stored per target and per field. Each annotation belongs to a type. Annotations are created and edited via `annotations_ui`.

### Annotation types

Define the category of annotation — editorial guidance, tech notes, compliance rules, etc. Add, rename, or remove types via the `annotations_type_ui` browser interface.

### Overview annotation

Every annotation target has one implicit bundle-level slot: the **overview**. This is an annotation about the target as a whole - what the content type, role, or entity is for - rather than any specific field. It surfaces as the first row in the add-new table in `annotations_ui`, as a bundle-level trigger at the top of entity forms and view pages in `annotations_overlay`, and as the opening description in context output from `annotations_context`.

### Role-based type layering

Because every annotation type generates a `consume {type} annotations` permission, you can author once and serve role-appropriate context automatically. Assign `consume` permissions to different roles to control which types surface in overlays, context payloads, and AI prompts for each audience.

**Example:** With three types — `editorial`, `technical`, and `rules`:

| Role | Permissions granted | Sees in overlays / context |
| --- | --- | --- |
| Editor | `consume editorial annotations` | Tone and style guidance |
| Developer | `consume technical annotations` | Field config and schema notes |
| Authenticated user | `consume rules annotations` | Mandatory compliance notes |
| Administrator | (all permissions implicitly) | Everything |

Annotations are authored against the same targets and fields regardless of type - the role determines which types are visible, not where the annotation lives. Types stack freely: grant multiple consume permissions to a role to combine audiences. The `annotations_demo_types` recipe ships `editorial`, `technical`, and `rules` types as a starting point.

---

## Scope management

Entity types that you wish to target can be added and removed via Manage target types at `/admin/config/annotations/targets`.

Individual fields can be selected as targets via Manage targets at `/admin/config/annotations/targets`.

Each Drupal entity type appears as an accordion section. Select (check) a row to bring a bundle into scope — this creates an `annotation_target` config entity with all available fields pre-included. Use **Configure** to adjust which fields are included.

---

## Permissions

The permission model maps to a non-standard CRUD because annotations are editorial content, not access-controlled content:

- **edit = create + update.** There is no separate "create" permission — if you can edit a type you can also create it. "View" in the Drupal sense is moot: anyone who can reach the annotation UI can read annotation values; the access question is whether you can write them.
- **delete = delete.** Kept separate from edit so destructive operations are restricted independently.
- **consume = "read" for end-users.** Controls which annotation types appear in context output, overlays, and AI payloads for a given role. Entirely separate from the editorial write layer.

| Permission | Defined in | Notes |
| --- | --- | --- |
| `administer annotation types` | `annotations_type_ui` | CRUD access for annotation type config entities. Site-building tool; `restrict access: true`. |
| `administer annotations` | `annotations` | Full admin. `restrict access: true`. |
| `administer annotation targets` | `annotations` | Manage opted-in targets and field scope. `restrict access: true`. |
| `consume {type} annotations` | `annotations` | Per annotation type; controls which types appear in context output and overlays for a given role. |
| `edit {type} annotations` | `annotations_ui` | Per annotation type; write and create access. Generated dynamically. |
| `delete {type} annotations` | `annotations_ui` | Per annotation type; delete access. Generated dynamically. |
| `edit any annotation` | `annotations_ui` | Supersedes all per-type edit permissions. `restrict access: true`. |
| `delete any annotation` | `annotations_ui` | Supersedes all per-type delete permissions. Gates bulk-delete routes. `restrict access: true`. |
| `access annotation collection` | `annotations_ui` | Read access to `/admin/content/annotations` and the add-type picker. |
| `view annotation revisions` | `annotations_ui` | View revision history and individual revision pages. Does not grant revert or delete. |

Dynamic permissions (`edit {type} annotations`, `delete {type} annotations`, `consume {type} annotations`) require a cache rebuild after new annotation types are created.

Each submodule documents its own permissions — see the relevant module's README.
