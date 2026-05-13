# CLAUDE.md — annotations_demo recipe

Standalone demo recipe for the Annotations suite. See [../../CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this recipe does

Creates two demo content types (Product and Collection) with custom fields, display configuration, annotation targets covering all fields, five product nodes, three collection nodes, and 21 starter annotations. Annotation types (editorial, technical, rules) come from the `annotations_demo_types` recipe declared as a dependency.

Designed as a drop-in evaluation environment — apply to a fresh Drupal install to see the full annotations suite in action without any pre-existing content types.

## Recipe structure

```text
annotations_demo/
  recipe.yml                        ← recipes dependency, install list, field storage strict list, enableTargetType action
  config/
    node.type.{product,collection}.yml
    field.storage.node.field_*.yml  ← 10 field storages, all marked strict in recipe.yml
    field.field.node.*.yml          ← field instances for both bundles
    core.entity_{form,view}_display.node.*.default.yml
    annotations.target.node__{product,collection}.yml
  content/
    annotation/*.yml                ← 21 annotation entities (15 on product, 6 on collection)
    node/*.yml                      ← 5 product nodes + 3 collection nodes
```

Annotation type configs live in `../annotations_demo_types/config/` and are applied before this recipe via the `recipes:` dependency.

## Config ordering

Drupal applies a recipe in this order: `install:` → `config/` files → `config: actions:`. The annotation targets are shipped as config YAML (not created via `enableTargetField`), so they exist with their full fields list before any action runs. The only config action needed is `enableTargetType: node`, because `annotations.target_types` is pre-existing module config that cannot safely be replaced wholesale.

## Field storages are strict

All 10 field storages are marked `strict:` in `recipe.yml`. This means the recipe will fail if any of them already exist on the site (e.g. `field_description`, `field_price`). These are generic names — the recipe is intended for fresh dev/demo installs, not production sites with existing content.

## Node content and cross-references

Collection nodes reference product nodes via `field_products`. The `content:export --with-dependencies` command resolves these as UUID-based `entity:` references, so the content files are portable. Stable UUIDs are baked into the exported YAML — re-applying the recipe to the same site will attempt to re-import the same UUIDs, which Drupal handles by skipping already-existing entities.

## Generating/refreshing the recipe

To regenerate the content files after changes to the `annotations_demo` module's hook_install data:

```bash
# Enable the module on a clean install to populate the DB
ddev drush en annotations_demo -y

# Export config (cherry-pick the relevant files into config/)
ddev drush cex --destination=/tmp/demo-config -y

# Export annotation and node content
ddev exec "cd /var/www/html/web && php core/scripts/drupal content:export annotation --with-dependencies --dir=/tmp/annotation-content"
ddev exec "cd /var/www/html/web && php core/scripts/drupal content:export node --bundle=product --bundle=collection --with-dependencies --dir=/tmp/node-content"
```

Then copy the output files into `config/` and `content/` as appropriate.

## Config action plugins

Both plugins live in the root `annotations` module at `src/Plugin/ConfigAction/`.

**`enableTargetType`** — appends to `annotations.target_types.enabled_target_types`; used by this recipe to register `node` without overwriting any entity types already in the list.

**`enableTargetField`** — appends fields to an existing `annotation_target` entity; creates the target from bundle label if absent. Not used by this recipe (targets are shipped as config YAML with fields already listed), but is the right tool for bolt-on recipes that target existing content types.
