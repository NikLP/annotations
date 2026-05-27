# Annotations — Developer Reference

Developer-focused reference. For module overview, entity capabilities, and the permission model see [README.md](README.md).

---

## Config management

Scope config (`annotation_target`, `annotation_type`) is managed via standard config sync.
Annotation text lives in the database and is never touched by config management tools.

---

## Drush commands

### Inspection (root `annotations` module)

Commands for querying the live state of targets, types, and annotation content. No submodules required.

```bash
# List all annotation_target config entities (field count + live annotation count)
drush ann:targets
drush ann:targets node            # filter by entity type
drush ann:targets --format=json

# List all annotation_type config entities (sorted by weight)
drush ann:types
drush ann:types --format=json

# Show stored annotation content
drush ann:show                              # all annotations (default limit 50)
drush ann:show node__article               # single target
drush ann:show --entity-type=node          # all node targets
drush ann:show node__article --type=editorial
drush ann:show node__article --field=body  # specific field
drush ann:show node__article --field=      # bundle-level only
drush ann:show --limit=200 --format=json

# Coverage stats: annotation counts per target broken down by type
drush ann:stats
drush ann:stats --entity-type=node
drush ann:stats --format=yaml
```

Use `drush list --filter=annotations` to see all registered annotations commands. Use `drush help ann:show` for full option docs.

### Scan (`annotations_scan`)

See [modules/annotations_scan/README.md](modules/annotations_scan/README.md) for `ann:scan` (`--diff`, `--strict`, `--fields`).

### Export (`annotations_export`)

See [modules/annotations_export/README.md](modules/annotations_export/README.md) for `ann:ex` (markdown and Obsidian vault export).

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
  $label   = $plugin->getLabel();   // e.g. "Content types"
  $bundles = $plugin->getBundles(); // array<bundle_key, label>
  $hasFields = $plugin->hasFields();
}
```

### Extending annotation types with custom behaviors

`AnnotationType` implements `ThirdPartySettingsInterface`. See [annotations_type_ui/README.md](modules/annotations_type_ui/README.md) for the full pattern.

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

## Recipe authoring

The root module ships two config action plugins for wiring up annotation scope in a recipe without overwriting pre-existing config.

### `enableTargetType`

Appends one or more entity types to `annotations.target_types`. Idempotent — types already in the list are skipped.

```yaml
config:
  actions:
    annotations.target_types:
      enableTargetType: node
      # or a list:
      # enableTargetType:
      #   - node
      #   - taxonomy_term
```

### `enableTargetField`

Appends fields to an `annotation_target` entity's fields list. Idempotent — fields already registered are skipped. If the target does not yet exist it is created automatically, with its label sourced from Drupal's bundle info (e.g. `node.type.article` → "Article").

```yaml
config:
  actions:
    annotations.target.node__article:
      enableTargetField:
        - title
        - body
        - field_tags
```

### Bolt-on recipe pattern (targeting existing content types)

```yaml
install:
  - annotations
  - annotations_ui
  - annotations_type_ui

config:
  actions:
    annotations.target_types:
      enableTargetType: node
    annotations.target.node__article:
      enableTargetField:
        - title
        - body
        - field_tags
```

### New content type recipe pattern

When a recipe creates its own content types, ship `annotations.target.{entity_type}__{bundle}.yml` directly in the recipe's `config/` directory alongside the node type, field, and display configs. Config import runs before config actions, so the target entity is already in place by the time any action fires. `enableTargetField` is not needed — the target YAML already carries the full fields list.

See [recipes/annotations_demo/](recipes/annotations_demo/) for a worked example.

---

## Notes

Views listed use a `text` variable instead of `value` in the filter criteria because that was a reserved name: `admin/structure/views/view/annotations_target`, `admin/structure/views/view/annotations`
