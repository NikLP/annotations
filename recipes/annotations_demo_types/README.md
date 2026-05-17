# Annotations Demo Types Recipe

Shared base recipe for all Annotations demo recipes. Installs the core Annotations modules and creates three standard annotation types used across every other `annotations_demo_*` recipe.

## What you get

**Annotation types**

| ID | Label | Description |
|---|---|---|
| `editorial` | Editorial | What this target is and how editors use it. Written for non-technical users. |
| `technical` | Technical | Developer-facing: schema notes, API behaviour, integration details. |
| `rules` | Rules | Field-level business rules, validation policies, and data quality standards. |

## Requirements

- Drupal 11 / PHP 8.3+
- Annotations module suite (`drupal/annotations`)

## Usage

This recipe is intended to be declared as a `recipes:` dependency in other demo recipes, not applied standalone. All `annotations_demo_*` recipes declare it as a dependency and will apply it automatically.

To apply it directly:

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_types
drush cr
```

## See also

- [annotations_demo recipe](../annotations_demo/) — standalone Product & Collection demo
- [annotations_demo_lgd recipe](../annotations_demo_lgd/) — LocalGov Drupal bolt-on
- [annotations_demo_umami recipe](../annotations_demo_umami/) — Umami demo profile bolt-on
- [annotations_demo_webform recipe](../annotations_demo_webform/) — onboarding webform demo
