# ADR 0011 — Rename module suite namespace from `dot` to `annotations`

## Status

Accepted — implementation pending

## Context

The `dot` namespace was chosen early as a short, convenient machine name. As the suite has grown into a full Drupal subsystem (10 modules, entity framework, plugin system, coverage tracking, AI integration, overlays), the name no longer communicates what the project does.

A rename to `annotations` better reflects the project's scope and is the correct time to do this — the project is currently dev-only with no production deployments or data to migrate.

## Decision

Rename all module machine names, PHP namespaces, service IDs, routes, config objects, entity types, and associated identifiers from the `dot` prefix to `annotations`.

### Module machine name mapping

| Old | New |
|---|---|
| `dot` | `annotations` |
| `dot_annotation` | `annotations_ui` |
| `dot_type_ui` | `annotations_type_ui` |
| `dot_scan` | `annotations_scan` |
| `dot_coverage` | `annotations_coverage` |
| `dot_context` | `annotations_context` |
| `dot_overlay` | `annotations_overlay` |
| `dot_ai_context` | `annotations_ai_context` |
| `dot_workflows` | `annotations_workflows` |
| `dot_demo` | `annotations_demo` |
| `dot_delta` | `annotations_delta` |

### Entity type machine name mapping

Entity type machine names are defined independently in the root module's entity classes — they are NOT derived from the submodule name `annotations_ui`. The correct mapping is:

| Old | New | Tables |
|---|---|---|
| `dot_annotation` (content entity) | `annotation` | `annotation`, `annotation_field_data`, `annotation_revision`, `annotation_field_revision` |
| `dot_target` (config entity) | `annotation_target` | config storage only |
| `dot_annotation_type` (config entity) | `annotation_type` | config storage only |

### Config object mapping

| Old | New |
|---|---|
| `dot.settings` | `annotations.settings` |
| `dot.entity_types` | `annotations.target_types` |
| `dot.target.*` | `annotations.target.*` |
| `dot.annotation_type.*` | `annotations.annotation_type.*` |

### PHP namespace mapping

| Old | New |
|---|---|
| `Drupal\dot` | `Drupal\annotations` |
| `Drupal\dot_annotation` | `Drupal\annotations_ui` |
| `Drupal\dot_type_ui` | `Drupal\annotations_type_ui` |
| `Drupal\dot_scan` | `Drupal\annotations_scan` |
| `Drupal\dot_coverage` | `Drupal\annotations_coverage` |
| `Drupal\dot_context` | `Drupal\annotations_context` |
| `Drupal\dot_overlay` | `Drupal\annotations_overlay` |
| `Drupal\dot_ai_context` | `Drupal\annotations_ai_context` |
| `Drupal\dot_workflows` | `Drupal\annotations_workflows` |
| `Drupal\dot_demo` | `Drupal\annotations_demo` |

## Implementation approach

### Branch strategy

Single branch: `dot-to-annotations`

The branch will be non-installable until all modules are renamed. This is acceptable — dev-only project, reinstall via `drush pmu` / `drush en` handles schema.

### Running order (dependency-first)

1. Root `annotations` module (most referenced; all submodules depend on it)
2. `annotations_ui` (annotation editing; depended on by coverage and others)
3. Independent leaves: `annotations_scan`, `annotations_context`, `annotations_type_ui`, `annotations_overlay`
4. Dependents: `annotations_coverage`, `annotations_ai_context`, `annotations_workflows`
5. `annotations_demo` (depends on everything)

Tasks per session:
- Rename module directory
- Rename all `*.info.yml`, `*.routing.yml`, `*.services.yml`, `*.permissions.yml`, `*.libraries.yml`, `*.links.*.yml` files
- Update PHP namespace declarations and `use` imports
- Update service IDs, service references (`@dot.*` → `@annotations.*`)
- Update route names
- Update config YAML keys, `id:` fields, `dependencies:` blocks
- Update schema YAML keys
- Rename config install files (e.g. `dot.target.node__article.yml` → `annotations.target.node__article.yml`)
- Rename twig templates (e.g. `dot-status-icon.html.twig` → `annotations-status-icon.html.twig`) and update theme hook registrations
- Update library names in `*.libraries.yml` and all `#attached` references
- Update CLAUDE.md files and README files in the module

### Per-session scope (approximate)

| Session | Work |
|---|---|
| 1 | Root `annotations` module |
| 2 | `annotations_ui` + `annotations_type_ui` |
| 3 | `annotations_scan` + `annotations_context` + `annotations_overlay` |
| 4 | `annotations_coverage` + `annotations_ai_context` + `annotations_workflows` + `annotations_demo` |

### Post-rename verification

```bash
ddev drush pmu annotations annotations_ui annotations_type_ui annotations_scan \
  annotations_coverage annotations_context annotations_overlay \
  annotations_ai_context annotations_workflows annotations_demo
ddev drush en annotations annotations_ui annotations_type_ui annotations_scan \
  annotations_coverage annotations_context annotations_overlay \
  annotations_ai_context annotations_workflows annotations_demo
ddev drush cr
```

Check for any remaining `dot` references (excluding git history and this ADR):

```bash
grep -r '\bdot\b' web/modules/custom/annotations/ \
  --include="*.php" --include="*.yml" --include="*.twig" \
  --include="*.js" --include="*.css" \
  -l
```

## Consequences

- All 66 PHP files, 62 YAML files, 8 twig templates, and 3 CSS/JS assets require changes.
- Approximately 100+ individual identifier occurrences across code, config, schema, and storage layers.
- Database tables for the `annotation` content entity will be recreated on reinstall with the new names — no data migration needed (dev-only).
- Ignore the config/sync folder, assume the rename will renew all config & data
- CLAUDE.md files across all modules must be updated to reflect the new names.
- The `docs/adr/` directory itself moves with the root module rename.
