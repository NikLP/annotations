# Annotations Demo Recipe

Sets up a self-contained demo environment for the Annotations suite. Creates two sample content types with realistic field structures, wires them up as annotation targets, and populates them with starter annotations and sample nodes.

## What you get

**Content types**

| Type | Fields |
|---|---|
| Product | Title, Description, SKU, Price, Country of origin, Lead time, Certifications, Status |
| Collection | Title, Products (entity ref), Season, Active |

**Annotation types:** Editorial, Technical, Rules

**Annotation targets:** `node__product` (8 fields), `node__collection` (4 fields)

**Sample content:** 5 product nodes, 3 collection nodes

**Starter annotations:** 21 — covering both bundle-level and field-level slots across both targets, demonstrating all three annotation types

## Requirements

- Drupal 11 / PHP 8.3+
- A reasonably fresh install — the recipe creates field storages with generic names (`field_description`, `field_price`, etc.) and will fail if any already exist on the site

## Installation

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo
drush cr
```

Or if the module is installed via Composer as `drupal/annotations`:

```bash
drush recipe web/modules/contrib/annotations/recipes/annotations_demo
drush cr
```

## Where to look

After applying the recipe:

- **Content types:** Admin → Structure → Content types
- **Annotation targets:** Admin → Config → Annotations → Targets
- **Sample content:** Admin → Content (filter by Product or Collection)
- **Annotations UI:** open any Product or Collection node for editing — field-level `?` triggers appear on each annotated field (requires `annotations_overlay`)

## Teardown

Recipes are one-way. To start fresh, reinstall Drupal or manually delete the content types, fields, and annotation config created by the recipe.

## See also

- [Root module README](../../README.md) — full suite overview, permissions, API
- [annotations_demo_types recipe](../annotations_demo_types/) — shared annotation types dependency
- [annotations_demo_lgd recipe](../annotations_demo_lgd/) — LocalGov Drupal bolt-on
- [annotations_demo_umami recipe](../annotations_demo_umami/) — Umami demo profile bolt-on
- [annotations_demo_webform recipe](../annotations_demo_webform/) — onboarding webform bolt-on
- [annotations_demo module](../../modules/annotations_demo/) — equivalent `hook_install` approach, useful as a reference for programmatic setup
