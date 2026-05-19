# CLAUDE.md — annotations_demo_umami recipe

Demo recipe layered on the Umami demo install profile. See [../../CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this recipe does

Creates a Cookbook content type (which entity-references Recipe nodes), wires four annotation targets (two node bundles, two taxonomy bundles), and imports ~50 starter annotations.

Targets:

| ID | Entity type | Bundle | Fields |
| --- | --- | --- | --- |
| `node__cookbook` | node | cookbook | 3 fields |
| `node__recipe` | node | recipe | 11 fields |
| `taxonomy_term__recipe_category` | taxonomy_term | recipe_category | 2 fields |
| `taxonomy_term__tags` | taxonomy_term | tags | 2 fields |
| `block_content__banner_block` | block_content | banner_block | 5 fields |
| `block_content__basic` | block_content | basic | 2 fields |
| `block_content__disclaimer_block` | block_content | disclaimer_block | 3 fields |
| `block_content__footer_promo_block` | block_content | footer_promo_block | 5 fields |

## Recipe structure

```text
annotations_demo_umami/
  recipe.yml                                     ← recipes dependency, strict field storages, enableTargetType action
  config/
    node.type.cookbook.yml
    field.storage.node.field_cookbook_description.yml    ← strict
    field.storage.node.field_cookbook_recipes.yml        ← strict
    field.field.node.cookbook.field_cookbook_description.yml
    field.field.node.cookbook.field_cookbook_recipes.yml
    core.entity_form_display.node.cookbook.default.yml
    core.entity_view_display.node.cookbook.default.yml
    annotations.target.node__cookbook.yml
    annotations.target.node__recipe.yml
    annotations.target.taxonomy_term__recipe_category.yml
    annotations.target.taxonomy_term__tags.yml
  content/
    annotation/*.yml                             ← 30 annotation entities
```

## Strict field storages

`field.storage.node.field_cookbook_description` and `field.storage.node.field_cookbook_recipes` are marked `strict:` in `recipe.yml`. The recipe will fail if either already exists on the site.

## Config actions

`enableTargetType: [node, taxonomy_term]` registers both entity types in `annotations.target_types` without overwriting whatever is already in the list.

## Umami bundle assumptions

`node__recipe`, `taxonomy_term__recipe_category`, and `taxonomy_term__tags` bundles are assumed to exist (shipped by the Umami profile). Annotation targets for these are imported as plain config YAML and will create orphaned targets if applied to a non-Umami site.
