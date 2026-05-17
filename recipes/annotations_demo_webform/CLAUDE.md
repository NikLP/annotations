# CLAUDE.md — annotations_demo_webform recipe

Bolt-on demo recipe for webform annotation overlays. See [../../CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this recipe does

Creates a "New Starter Onboarding" webform, wires it as an annotation target (both the webform itself and its submission), and imports 7 starter annotations.

Targets:

| ID | Entity type | Bundle | Fields |
|---|---|---|---|
| `webform__annotations_demo_onboarding` | webform | annotations_demo_onboarding | none (bundle-level only) |
| `webform_submission__annotations_demo_onboarding` | webform_submission | annotations_demo_onboarding | full_name, work_email, department, manager_name, start_date |

## Recipe structure

```text
annotations_demo_webform/
  recipe.yml                                      ← recipes dependency, install list, enableTargetType action
  config/
    webform.webform.annotations_demo_onboarding.yml
    annotations.target.webform__annotations_demo_onboarding.yml
    annotations.target.webform_submission__annotations_demo_onboarding.yml
  content/
    annotation/*.yml                              ← 7 annotation entities
```

## Webform target has no fields

`webform__annotations_demo_onboarding` has `fields: []` — it accepts only a bundle-level annotation. Individual form elements are annotated on the `webform_submission__annotations_demo_onboarding` target, which maps fields to webform element keys.

## Config actions

`enableTargetType: [webform, webform_submission]` registers both entity types in `annotations.target_types` without overwriting existing config.

## Module dependencies

`annotations_webform` (provides target plugins for webform entity types), `annotations_overlay` (field-level `?` trigger UI), and `webform` are all in the `install:` list.
