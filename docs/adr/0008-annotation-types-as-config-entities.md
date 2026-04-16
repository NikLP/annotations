# ADR 0008: Annotation types as config entities (`DotAnnotationType`)

**Status:** Accepted

## Context

The three annotation fields (`editorial_overview`, `technical_overview`, `business_rules`) are hardcoded into the `dot_target` entity schema and throughout the annotation form and report service. This creates several problems:

- Renaming a type (e.g. "technical" → "developer notes") requires code changes and a schema migration
- Adding a custom type (e.g. "accessibility") requires code changes
- The permission split (one permission per annotation type) is harder to implement cleanly when types are hardcoded
- The report service and AI context assembler both hardcode which types affect status and which are included in AI context — this logic belongs with the type definition, not scattered across modules
- `dot_target` is a config entity and cannot be made fieldable, so the only path to extensibility is a separate type registry

A PHP plugin system was considered. It was rejected because it still requires code deployment to rename or remove a type — a site admin cannot change type configuration without a code release.

## Decision

Introduce `DotAnnotationType` as a config entity in the `dot` module.

Each type defines:
- `id` — machine name
- `label` — human-readable label
- `description` — what the type is for
- `permission` — the Drupal permission required to edit annotations of this type
- `affects_status` — boolean; whether a missing value degrades coverage status
- `include_in_ai_context` — boolean; whether this type is included in AI context export
- `context_weight` — integer; ordering in AI context payload

The three default types (`editorial`, `technical`, `rules`) ship as `config/install/dot.annotation_type.*.yml`. Sites can rename, remove, or add types via config management (UI or `drush cim`) with no code changes.

`dot_target` annotation storage moves from hardcoded top-level fields to an `annotations` map keyed by annotation type ID, at both the target level and the field level.

## Consequences

- Annotation types are as configurable as content types or vocabularies — no code deployment required
- `affects_status` and `include_in_ai_context` move from hardcoded logic in `ReportService`/`dot_ai_context` to the type definition
- The permission split (annotate dot editorial / annotate dot technical / etc.) falls out naturally — each type declares its own permission
- `dot_target` schema migration required from hardcoded fields to `annotations` map
- `dot_annotation` form, `ReportService`, and `dot_ai_context` payload builder all need to load annotation types dynamically rather than iterating hardcoded keys
