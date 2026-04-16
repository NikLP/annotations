# ADR 0009: Workflow deferred — two distinct workflow concerns identified

**Status:** Accepted

## Context

Annotation workflow was listed in the module plan as a straightforward feature: add states (draft → needs review → published) to annotations. On closer examination, two distinct workflow needs emerged that should not be conflated:

**Type 1 — Annotation quality workflow:** Tracks whether an annotation is authoritative. States: `empty → draft → needs review → published`. Governs annotation quality independently of content publishing. An annotation in "draft" state might be perfectly useful; "published" means it has been reviewed and is considered canonical.

**Type 2 — Content gate workflow:** Blocks content from publishing until annotation coverage for that content type meets a defined threshold. Integrates with Drupal core Content Moderation. A different concern: it uses annotation coverage as a publishing precondition, not as a quality signal about the annotation itself.

Building both together — or picking the wrong one first — risks coupling concerns that belong in separate modules and separate decisions. Type 2 also requires agreement on what "sufficient coverage" means per content type, which is a product decision not yet made.

## Decision

Workflow is deferred entirely from v1. Neither type is built yet.

When this becomes a real need, the sequence is:

1. Decide which type is the immediate priority based on a concrete use case
2. Build type 1 (annotation quality workflow) first if in doubt — it is self-contained and useful independently of content publishing or AI features
3. Build type 2 (content gate) separately, after defining what "sufficient coverage" means per target and how it integrates with existing Drupal workflow transitions

## Consequences

- No workflow states in v1 — annotations are either filled or not, as reported by `dot_report`
- The two workflow types remain separable if eventually implemented in separate modules (`dot_workflow_annotation`, `dot_workflow_gate` or similar)
- `dot_ai_draft` (already parked) continues to depend on `dot_workflow` being built first — this dependency chain remains on hold
