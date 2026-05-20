# Annotations Webform Demo Recipe

Bolt-on demo recipe that adds annotation overlays to a standalone onboarding webform. Creates a "New Starter Onboarding" webform, wires it as an annotation target, and imports 7 starter annotations covering the form and its submission fields.

## What you get

**New webform**

| ID | Title | Elements |
|---|---|---|
| `annotations_demo_onboarding` | New Starter Onboarding | full_name, work_email, department, manager_name, start_date |

**Annotation targets**

| Target | Entity type | Scope |
|---|---|---|
| `webform__annotations_demo_onboarding` | webform | Bundle-level only (form-wide annotation) |
| `webform_submission__annotations_demo_onboarding` | webform_submission | 5 submission fields |

**Annotation types:** Editorial, Technical, Rules (from `annotations_demo_types` dependency)

**Starter annotations:** 7

## Requirements

- Drupal 11 / PHP 8.3+
- `webform` module
- `annotations_webform` sub-module (provides target plugins for webform entity types)

## Installation

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_webform
drush cr
```

## Where to look

After applying the recipe:

- **Webform:** Admin → Structure → Webforms → New Starter Onboarding
- **Annotation targets:** Admin → Config → Annotations → Targets
- **Annotations UI:** open a webform submission for editing — field-level `?` triggers appear on each annotated element (`annotations_overlay` is installed automatically by this recipe)

## Teardown

Recipes are one-way. Delete the webform, the two annotation targets, and the imported annotation entities to remove the demo data.

## See also

- [Root module README](../../README.md) — full suite overview
- [annotations_demo_types recipe](../annotations_demo_types/) — shared annotation types dependency
- [annotations_demo recipe](../annotations_demo/) — standalone Product & Collection demo
- [annotations_demo_lgd recipe](../annotations_demo_lgd/) — LocalGov Drupal bolt-on
- [annotations_demo_umami recipe](../annotations_demo_umami/) — Umami demo profile bolt-on
- [`annotations_webform` sub-module](../../modules/annotations_webform/) — target plugins for webform and webform_submission entity types
