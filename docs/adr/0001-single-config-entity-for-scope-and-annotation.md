# ADR 0001: Single config entity for scope and annotation

**Status:** Accepted

## Context

Early design had two separate concepts: a "scan scope" (which targets to crawl) and an "annotation" (what to write about each target). The question was whether these should be separate config entities, or unified.

Arguments for separation: cleaner separation of concerns; `dot` module wouldn't need to know about annotation fields; `dot_scan` could be used without `dot_annotation` installed.

Arguments for unification: in practice, "things we scan" and "things we annotate" are always the same set; two entities means two config files, two storage layers, sync complexity; the presence of a `dot_target` entity is itself the opt-in signal.

## Decision

One config entity — `dot_target` in the `dot` module — owns both scope and annotation data. The entity ID (`{entity_type}__{bundle}`) is the opt-in signal. Annotation fields (`editorial_overview`, `technical_overview`, `business_rules`) live directly on it. The `fields` map doubles as the field inclusion list and the per-field annotation container.

## Consequences

- Simpler data model: one config file per opted-in target, e.g. `dot.target.node__article.yml`
- `dot_annotation` does not define its own entity — it only provides the UI to write into `dot_target`
- `dot_scan` can be disabled on sites that ship pre-authored config (recipe/profile deployments); the annotation data travels with the config YML regardless
- Deleting a target (deselecting it in the UI) deletes its annotation data — gated by a confirmation form when annotation data exists
