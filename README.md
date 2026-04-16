# Annotations

Annotations reads a Drupal site's structure — content types, fields, taxonomies, user roles, anything that's an entity — and exposes that information as/to a structured annotation system. The annotations can be consumed in several ways:

- in-context help overlays while editing
- human- or machine-readable documentation export
- input for an AI chat scoped to a specific site's configuration

---

## Requirements

- Drupal 11
- PHP 8.3+

No contributed module dependencies for any of the `annotations` modules.
Annotations_workflows requires core content_moderation module, which optionally
requires the contributed `diff` module.

---

## Installation

Install dot base module. Enable submodules as needed

---

## The module suite

| Module | Who it is for | Purpose |
| --- | --- | --- |
| `annotations` | All | Core - entities, plugin system, scope UI. Always required. |
| `annotations_type_ui` | Agency / dev only | UI for creating and managing annotation types. Site-building only — never touched by editors. |
| `annotations_ui` | Agency + editors | Annotation editing UI. Used pre-launch to populate context and post-launch by editors maintaining it. |
| `annotations_coverage` | Agency / dev | Annotation coverage tracking and report. Owns the `affects_coverage` behaviour and exposes `CoverageService` as a public API for enforcement or CI use. |
| `annotations_context` | Agency / dev | Assembles annotations into a renderable documentation payload. Preview and export tool; the same payload assembly is consumed by `annotations_overlay` and `annotations_ai_context`. |
| `annotations_overlay` | Editors / end users | In-context help overlays on entity edit forms. Shows field-level and bundle-level annotations as triggered panels. |
| `annotations_ai_context` | Editors / end users | AI chat widget scoped to the current page's annotation context. Optional — sites can get full value from Annotations without it. |
| `annotations_scan` | (Incomplete) Agency / dev | Crawls site structure on demand. Mostly a setup and CI tool. |

---

## Core concepts

### annotation_target

One `annotation_target` config entity per annotatable unit — one per content type, taxonomy vocabulary, user role, etc. Defines *scope* (which entity types and which fields are included). Deployed by config management.

### annotation (Annotation)

Stores all annotation text as content entity rows. Never touched by config sync - annotation content written on production survives deploys. Edited via `annotations_ui`.

### Annotation types (AnnotationType)

Define type of annotation. Three ship by default: `editorial`, `technical`, `rules`. Types are config entities — add, rename, or remove via config management or the `annotations_type_ui` browser UI.

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

### Extending annotation types with custom flags (ThirdPartySettingsInterface)

`AnnotationType` implements `ThirdPartySettingsInterface`, which is the standard Drupal mechanism for contrib modules to attach their own properties to a config entity without modifying its schema.

A module that wants to add a flag to annotation types (e.g. "expose this type in the front-end AI bot") does three things:

**1. Declare a schema for the settings:**

```yaml
# config/schema/mymodule.schema.yml
dot.annotation_type.*.third_party.mymodule:
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

This pattern is appropriate when the contributing module owns the full lifecycle of the flag: it writes it, reads it, and acts on it. DOT's own services have no knowledge of third-party settings added by other modules.

#### When a plugin system would be warranted

If DOT core services needed to enumerate flags across all installed modules — for example, if `ContextAssembler` needed to ask every installed module "does this annotation type affect your output?" — a plugin layer would be the right approach. The shape would be:

- DOT defines an `AnnotationTypeFlag` plugin type (annotated class, manager service)
- Each plugin declares: ID, label, default value, and a form element definition
- `AnnotationType` stores flag values in a `flags: {}` map in its schema
- `annotations_type_ui` iterates discovered plugins to render the type edit form automatically
- DOT services call `$flagManager->getDefinitions()` to iterate all registered flags

The values would still be stored on the config entity (in the flags map rather than third-party settings), and `drush cex` would capture them as normal. The cost is that DOT must build and maintain the plugin manager, and all flags — first-party and contrib — would be expressed as plugins.

Until DOT core services need that enumeration, the `ThirdPartySettingsInterface` approach above is sufficient and idiomatic.

---

### Adding a custom Target plugin

Tag a service with `dot.target` and implement `TargetInterface` (extend `TargetBase`):

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
| `edit {type} annotations` | `annotations` | Per annotation type; generated dynamically. |
| `consume {type} annotations` | `annotations` | Per annotation type; controls context output visibility per role. |
| `edit any annotation` | `annotations_ui` | Supersedes per-type edit. `restrict access: true`. |
| `access annotation overview` | `annotations_ui` | Read-only access to annotation listing. |
| `view annotations overlay` | `annotations_overlay` | See annotation help overlays on entity edit forms and the toolbar trigger. Grant to all editor roles. |
| `view annotations context` | `annotations_context` | Access context preview and export. |
| `administer annotations scanner` | `annotations_scan` | Run scans, view scan results. `restrict access: true`. |

Dynamic permissions (`edit {type} annotations`, `consume {type} annotations`) require a cache rebuild after new annotation types are created.

---

## Config management

Scope config (`annotation_target`, `annotation_type`) is managed via standard config sync.
Annotation text lives in the database and is never touched by config management tools.

---

## Notes

Views listed use a `text` variable instead of `value` because that was a reserved name.
admin/structure/views/view/annotations_target
admin/structure/views/view/annotations
