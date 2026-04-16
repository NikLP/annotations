# ADR 0006: Granular annotation permissions deferred to v2

**Status:** Accepted

## Context

Different roles in an agency workflow annotate different things: content editors write editorial overviews, developers write technical overviews, account managers write business rules. This suggested splitting `annotate dot targets` into three granular permissions: `annotate dot editorial`, `annotate dot technical`, `annotate dot business rules`.

The problem: the annotation entry point is the Targets page (`/admin/config/dot/targets`), which requires `administer dot targets`. An editor with only `annotate dot editorial` would hit access denied before reaching the Annotate dropbutton. Splitting permissions without a separate annotation-accessible entry point creates a broken access model.

Additionally, the Targets page currently serves double duty: scope management (admin concern) and the Annotate dropbutton (annotation concern). Splitting permissions before the UI separates these concerns degrades the primary user experience — an admin doing both jobs — without benefiting the secondary users (role-specific annotators) who still can't reach the form.

## Decision

Single permission `annotate dot targets` for v1. Granular permissions are explicitly deferred.

## Deferred sequence (v2)

The right order when this becomes a real need:

1. Build an annotation-only landing page (`/admin/config/dot/annotate`) — a simple list of opted-in targets with Annotate links, no scope management controls, accessible to annotation-permission holders
2. Add a menu link for it under DOT admin
3. Split `annotate dot targets` into `annotate dot editorial`, `annotate dot technical`, `annotate dot business rules`
4. Make the Targets page role-adaptive — hide scope checkboxes from users who only hold annotation permissions

## Consequences

- v1 permission model is coarse but functional and honest about what the UI supports
- No broken access states for partial-permission users
- The Targets page remains a good unified view for the primary user (developer/admin doing both jobs)
