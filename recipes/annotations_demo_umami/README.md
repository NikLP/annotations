# Annotations Demo (Umami) Recipe

Demo recipe for sites running Drupal's [Umami demo install profile](https://www.drupal.org/project/demo_umami). Creates a Cookbook content type that entity-references Recipe nodes, wires annotation targets onto four bundles, and imports 30 starter annotations.

## What you get

**New content type**

| Type | Fields |
|---|---|
| Cookbook | Title, Description (text), Recipes (entity ref to Recipe nodes) |

**Annotation targets**

| Target | Bundle | Fields |
|---|---|---|
| `node__cookbook` | Cookbook | title, field_cookbook_description, field_cookbook_recipes |
| `node__recipe` | Recipe | title, field_summary, field_difficulty, field_cooking_time, field_preparation_time, field_number_of_servings, field_ingredients, field_recipe_instruction, field_recipe_category, field_tags, field_media_image |
| `taxonomy_term__recipe_category` | Recipe category | name, description |
| `taxonomy_term__tags` | Tags | name, description |

**Annotation types:** Editorial, Technical, Rules (from `annotations_demo_types` dependency)

**Starter annotations:** 30 — covering all four targets

## Requirements

- Drupal 11 / PHP 8.3+
- [demo_umami install profile](https://www.drupal.org/project/demo_umami) — `node__recipe`, `taxonomy_term__recipe_category`, and `taxonomy_term__tags` bundles must already exist
- Field storages `field_cookbook_recipes` and `field_cookbook_description` must not already exist on the site — the recipe will fail if they do

## Installation

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_umami
drush cr
```

## Where to look

After applying the recipe:

- **Content types:** Admin → Structure → Content types → Cookbook
- **Annotation targets:** Admin → Config → Annotations → Targets
- **Annotations UI:** open any Recipe or Cookbook node for editing — field-level `?` triggers appear on each annotated field (`annotations_overlay` is installed automatically by this recipe)

## Teardown

Recipes are one-way. To remove, delete the Cookbook content type, the four annotation targets, and the imported annotation entities.

## See also

- [Root module README](../../README.md) — full suite overview
- [annotations_demo_types recipe](../annotations_demo_types/) — shared annotation types dependency
- [annotations_demo recipe](../annotations_demo/) — equivalent standalone demo for fresh installs
- [annotations_demo_lgd recipe](../annotations_demo_lgd/) — LocalGov Drupal bolt-on
- [annotations_demo_webform recipe](../annotations_demo_webform/) — onboarding webform bolt-on
