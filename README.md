# Annotations

Annotations reads a Drupal site's structure — content types, fields, taxonomies, user roles, anything that's an entity — and maps that to a structured annotation system.

Annotations can be consumed in several ways:

AI:

- scoped input for an AI agent - returns component-specific human-curated context
- MCP-compliant JSON endpoint for pulling context info into other 'applications' (WIP!)

User onboarding / Editing:

- contextual help overlays while creating/editing entities - help onboard new users with complex forms
- same for when viewing content - help text on learning materials or new user orientation
- annotation insertion into entity select screens - additional help text on e.g. /node/add to assist with selection

Product documentation:

- export your annotations and ship them with your product! - export annotations in default content, only core module plus a consumer reqd.
- human- or machine-readable documentation export - provides drush commands to get (optionally scoped) annotations on the CLI

Annotations entities support:

- multiple, fieldable annotation types
- revisions (`diff` support provided)
- content moderation
- workflows (via supplied integration module)
- translation (via language switcher)
- dynamic permissions system for edit/consume annotations

Annotation type entities support:

- 'behaviors' driven by third party settings for interfacing with other modules. (see: Extending annotation types with custom behaviors)

Annotation target entities:

- can be created on any entity target that exposes a plugin via the extensible system provided. (see: Adding a custom Target plugin)
- target plugins already provided: [generic], paragraphs, media, node, view, user, taxonomy, etc.

---

## Requirements

- Drupal 11
- PHP 8.3+

No contributed module dependencies for any of the `annotations` modules
provided here.

`annotations_workflows` requires the core `content_moderation` module, which optionally
requires the contributed `diff` module.

---

## Installation

Install annotations base module; enable submodules as needed.

---

## The module suite

*All* modules should be considered a work in progress at this time! That said, the core suite has undergone the most scrutiny, that is: `[core], _ui, _type_ui, _workflows`.

The following modules are currently not well developed: annotations_scan, annotations_ai_context; other features are not necessarily stable.

| Module | Who it is for | Purpose |
| --- | --- | --- |
| `annotations` | All | Core — entities, plugin system, scope UI. Always required. |
| `annotations_type_ui` | Agency / dev only | Browser CRUD for annotation types. Site-building tool only — use during initial setup, not for ongoing production use. |
| `annotations_ui` | Agency + editors | Annotation editing UI with revision history and moderation controls. The primary authoring interface. |
| `annotations_coverage` | Agency / dev | Annotation coverage tracking and report. Owns the `affects_coverage` behavior on types and exposes `CoverageService` as a stable public API for enforcement or CI use. |
| `annotations_context` | Agency / dev | Assembles annotations into a structured payload. Provides an admin preview, markdown export, JSON API endpoint, and the shared payload consumed by `annotations_ai_context`. |
| `annotations_workflows` | Agency / dev | Ships the default three-state editorial workflow (`draft → needs_review → published`) for annotation entities. Optional — any `content_moderation` workflow can be attached manually instead. |
| `annotations_overlay` | Editors / end users | In-context help overlays: field-level and bundle-level "?" triggers on entity edit forms, opt-in view-page overlays (via Manage Display), bundle chooser page descriptions, and paragraph subform support. |
| `annotations_scan` | Agency / dev | Crawls opted-in targets on demand and via cron. Provides a manual trigger UI and `drush annotations:scan` command. Snapshot storage and `--diff`/`--strict` flags are parked pending `annotations_delta`. |
| `annotations_ai_context` | Editors / end users | AI chat widget scoped to the current page's annotation context. Depends on the `ai` module suite. |
| `annotations_demo` | Dev / evaluation | Ships the default editorial/technical/rules annotation types, form displays, a sample target, and starter content. Install for dev or evaluation; omit for blank-slate production. |

---

## Core concepts

### annotation_target

One `annotation_target` config entity per annotatable unit — one per content type, taxonomy vocabulary, user role, etc. Defines *scope* (which entity types and which fields are included). Deployed by config management.

### annotation

Stores all annotation text as content entity rows. Edited via `annotations_ui`.

### Annotation types (AnnotationType)

Define type of annotation - editorial guidance, tech notes, etc. Types are config entities — add, rename, or remove via config management or the `annotations_type_ui` browser UI.

### Overview annotation

Every annotation target has one implicit bundle-level slot: the **overview**. This is an annotation about the target as a whole — what the content type, role, or entity is for — rather than any specific field. In storage it is an `annotation` entity with `field_name` set to the empty string. It surfaces as the first row (labeled "Overview") in the add-new table in `annotations_ui`, as a bundle-level trigger at the top of entity forms and view pages in `annotations_overlay`, and as the opening description in context output from `annotations_context`. See the API example in the Developer API section for how to read it: `$all['']['editorial']`.

---

## Scope management

Navigate to **Admin → Config → Annotations → Targets** (`/admin/config/annotations/targets`).

Each Drupal entity type appears as an accordion section. Select (check) a row to bring a bundle into scope — this creates an `annotation_target` config entity with all available fields pre-included. Use **Configure** to adjust which fields are included.

---

## Developer API

### AnnotationStorageService (`annotations.annotation_storage`)

Central service for all annotation CRUD. Inject `annotations.annotation_storage`.

```php
use Drupal\annotations\AnnotationStorageService;

// Load all annotations for a target.
// Returns: array<field_name, array<type_id, value>>
// Bundle-level annotations use '' as field_name.
$all = $annotationStorage->getForTarget('node__article');

// Bundle-level overview annotation.
$editorial = $all['']['editorial'] ?? '';

// Field-level annotation.
$body_technical = $all['body']['technical'] ?? '';

// Save site-wide annotations.
$annotationStorage->saveSiteAnnotations(['site_purpose' => '...']);

// Check whether a target has any annotation data.
$hasData = $annotationStorage->hasAnnotationData('node__article');

// Delete all annotation data for a target.
$annotationStorage->deleteForTarget('node__article');
```

### DiscoveryService (`annotations.discovery`)

Returns all registered Target plugins, including auto-discovered generic plugins for unclaimed fieldable entity types. Inject `annotations.discovery`.

```php
$plugins = $discoveryService->getPlugins();
// Returns: array<entity_type_id, TargetInterface>

foreach ($plugins as $entity_type_id => $plugin) {
  if (!$plugin->isAvailable()) continue;
  $label  = $plugin->getLabel();   // e.g. "Content types"
  $bundles = $plugin->getBundles(); // array<bundle_key, label>
  $hasFields = $plugin->hasFields();
}
```

### Extending annotation types with custom behaviors (ThirdPartySettingsInterface)

`AnnotationType` implements `ThirdPartySettingsInterface`, for contrib modules to attach their own properties to an annotation type.

A module that wants to add a behavior to annotation types (e.g. "expose this type in the front-end AI bot") does three things:

**1. Declare a schema for the settings:**

```yaml
# config/schema/mymodule.schema.yml
annotations.annotation_type.*.third_party.mymodule:
  type: mapping
  label: 'My module annotation type settings'
  mapping:
    in_frontend_bot:
      type: boolean
      label: 'Show in front-end bot'
```

**2. Inject a form element via `hook_form_alter`:**

```php
function mymodule_form_annotation_type_edit_form_alter(array &$form, FormStateInterface $form_state): void {
  $type = $form_state->getFormObject()->getEntity();
  $form['in_frontend_bot'] = [
    '#type' => 'checkbox',
    '#title' => t('Show in front-end bot'),
    '#default_value' => $type->getThirdPartySetting('mymodule', 'in_frontend_bot', FALSE),
  ];
  $form['#entity_builders'][] = 'mymodule_form_annotation_type_edit_form_builder';
}

function mymodule_form_annotation_type_edit_form_builder(string $entity_type, AnnotationType $type, array &$form, FormStateInterface $form_state): void {
  $type->setThirdPartySetting('mymodule', 'in_frontend_bot', (bool) $form_state->getValue('in_frontend_bot'));
}
```

**3. Read the setting wherever needed:**

```php
$show = $annotationType->getThirdPartySetting('mymodule', 'in_frontend_bot', FALSE);
```

The value is stored on the config entity alongside its first-party properties and is exported with `drush cex`. From the editor's perspective the checkbox appears as part of the type edit form with no visible indication it comes from a different module.

This pattern is appropriate when the contributing module owns the full lifecycle of the behavior: it writes it, reads it, and acts on it. Annotations' own services have no knowledge of third-party settings added by other modules.

---

### Adding a custom Target plugin

Tag a service with `annotations.target` and implement `TargetInterface` (extend `TargetBase`):

```yaml
# mymodule.services.yml
services:
  mymodule.target.custom:
    class: Drupal\mymodule\Plugin\Target\CustomTarget
    arguments:
      - '@entity_type.manager'
    tags:
      - { name: annotations.target }
```

```php
use Drupal\annotations\Plugin\Target\TargetBase;

class CustomTarget extends TargetBase {
  public function getEntityTypeId(): string { return 'my_entity'; }
  public function getLabel(): string { return 'My entities'; }
  public function isAvailable(): bool { return $this->entityTypeManager->hasDefinition('my_entity'); }
  public function getBundles(): array { /* return [key => label] */ }
  public function hasFields(): bool { return true; }
  public function discover(string $bundle): array { /* return structured snapshot */ }
}
```

No changes to `annotations` module are needed. The plugin is picked up automatically.

---

## Shipping default annotations

Use the `drupal` script to export `annotation` entities as files in a recipe's `content/` directory. This is the mechanism for shipping a starter annotation set with a profile or recipe.

```bash
ddev php web/core/scripts/drupal content:export annotation --dir=recipes/myrecipe/content
```

---

## Permissions

| Permission | Defined in | Notes |
| --- | --- | --- |
| `administer annotations` | `annotations` | Full admin. `restrict access: true`. |
| `edit {type} annotations` | `annotations` | Per annotation type; generated dynamically from installed types. |
| `consume {type} annotations` | `annotations` | Per annotation type; controls which types appear in context output and overlays for a given role. |
| `administer annotation types` | `annotations_type_ui` | CRUD access for annotation type config entities. Site-building tool; `restrict access: true`. |
| `edit any annotation` | `annotations_ui` | Supersedes all per-type edit permissions. `restrict access: true`. |
| `access annotation overview` | `annotations_ui` | Read-only access to the annotation listing at `/admin/content/annotations`. |
| `view annotation revisions` | `annotations_ui` | View revision history and individual revision pages. Does not grant revert or delete. |
| `view annotations overlay` | `annotations_overlay` | See field-level "?" triggers, dialog panels, and bundle chooser descriptions. Grant to editor roles. |
| `view annotations context` | `annotations_context` | Access the context preview, markdown export, and JSON API endpoint. Not `restrict access` — can be granted to non-admin roles. |
| `administer annotations scanner` | `annotations_scan` | Run scans and view scan results. `restrict access: true`. |

Dynamic permissions (`edit {type} annotations`, `consume {type} annotations`) require a cache rebuild after new annotation types are created.

### Workflow transition permissions (annotations_workflows)

When `annotations_workflows` is installed, `content_moderation` automatically generates one permission per transition:

| Permission | Intended for |
| --- | --- |
| `use annotations transition create_new_draft` | Annotator roles |
| `use annotations transition submit_for_review` | Annotator roles |
| `use annotations transition publish` | Reviewer roles |
| `use annotations transition reject` | Reviewer roles |

These are standard Drupal `content_moderation` permissions and are assigned via the Drupal roles UI or config. The Drupal administrator role bypasses all moderation checks implicitly.

If you use a custom workflow instead of the one shipped by `annotations_workflows`, `content_moderation` generates equivalent permissions for your workflow's transitions.

---

## Config management

Scope config (`annotation_target`, `annotation_type`) is managed via standard config sync.
Annotation text lives in the database and is never touched by config management tools.

---

## Notes

Views listed use a `text` variable instead of `value` in the filter criteria because that was a reserved name: `admin/structure/views/view/annotations_target`, `admin/structure/views/view/annotations`
