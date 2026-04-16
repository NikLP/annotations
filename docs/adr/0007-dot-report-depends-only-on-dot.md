# ADR 0007: `dot_report` depends only on `dot`

**Status:** Accepted

## Context

The coverage report reads annotation completeness from `dot_target` config entities. An early assumption was that it should depend on `dot_annotation` (the annotation UI module) and possibly `dot_scan` (the scan execution module). This was questioned — does the report actually need either?

`dot_annotation` provides the UI for writing annotation data, but annotation data lives in `dot_target` (owned by `dot`). A site could have fully annotated `dot_target` config entities shipped via a recipe/profile without `dot_annotation` ever being installed.

`dot_scan` discovers site structure, but the report only cares about what's already been annotated — it reads `dot_target` entities, not scanner output. Combining them was briefly considered (they both deal with targets) but rejected: scanner reads Drupal structure, report reads annotation completeness. Different concerns, different triggers, wrong dependency direction (report would depend on scanner, not the other way round).

## Decision

`dot_report` depends only on `dot`. It reads `dot_target` config entities directly. It works regardless of whether `dot_annotation` or `dot_scan` are installed. When `dot_annotation` is installed, the report surfaces Annotate links in its operations column — detected via `moduleHandler()->moduleExists('dot_annotation')` at runtime.

## Consequences

- Report is available on sites using pre-authored config without the annotation UI
- No artificial coupling between reporting and scan execution
- The Annotate link in the report table is a progressive enhancement, not a hard dependency
