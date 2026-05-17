# CLAUDE.md — annotations_demo_types recipe

Shared base recipe for all Annotations demo recipes. See [../../CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this recipe does

Installs `annotations`, `annotations_ui`, and `annotations_type_ui`, then ships three standard annotation type config entities used across all other `annotations_demo_*` recipes:

| ID | Label | Notes |
|---|---|---|
| `editorial` | Editorial | For non-technical editors; weight 0 |
| `technical` | Technical | Developer-facing notes |
| `rules` | Rules | Business rules and validation policies |

## Recipe structure

```text
annotations_demo_types/
  recipe.yml                                    ← install list only (no config actions, no content)
  config/
    annotations.annotation_type.editorial.yml
    annotations.annotation_type.technical.yml
    annotations.annotation_type.rules.yml
```

## Dependency role

This recipe is declared as a `recipes:` dependency by every other `annotations_demo_*` recipe. Drupal applies dependencies before the declaring recipe, so types are always present when demo content is imported.

Apply it standalone only if you want the three annotation types without any demo content or targets.

## No strict config

Unlike `annotations_demo` and `annotations_demo_umami`, this recipe ships no field storages and no content types — nothing that would conflict with an existing site. It is safe to apply to any Drupal 11 install running the Annotations suite.
