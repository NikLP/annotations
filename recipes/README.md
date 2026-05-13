# Annotations Recipes

Drupal recipes for the Annotations suite. Each recipe is self-contained and can be applied with `drush recipe <path>`.

## Available recipes

### [annotations_demo_types](annotations_demo_types/)

The three standard annotation types: Editorial, Technical, and Business rules. This is the shared base — other demo recipes declare it as a dependency so it is applied automatically. You rarely need to apply it directly.

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_types
```

### [annotations_demo](annotations_demo/)

Creates Product and Collection content types from scratch, wires them up as annotation targets, and populates them with 21 starter annotations and sample nodes. Depends on `annotations_demo_types`. Use on a fresh dev or evaluation install.

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo
```

### [annotations_demo_lgd](annotations_demo_lgd/)

Bolt-on annotations for a LocalGov Drupal site. Targets existing LGD content types (Event, Subsite page) and paragraph types (Banner primary, Accordion) — does not create any content types. Requires a working LGD install.

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_lgd
```

### [annotations_demo_webform](annotations_demo_webform/)

Bolt-on annotations for a standalone Webform. Creates the New Starter Onboarding form, registers `webform` and `webform_submission` as annotation target types, and imports 7 starter annotations with per-field overlay triggers. Depends on `annotations_demo_types`.

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_webform
```

## Recipe authoring

See the [root README](../README.md#recipe-authoring) for documentation on the `enableTargetType` and `enableTargetField` config action plugins, which are the building blocks for any recipe that wires up annotation scope on existing content types.
