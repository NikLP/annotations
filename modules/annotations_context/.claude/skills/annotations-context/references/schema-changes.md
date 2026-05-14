# Pre-flight for Schema Changes

Before recommending adding, modifying, or removing a field on an annotated content type, work through this checklist. Always read the annotation context first (see the main skill) — the answers to most of these questions come from the annotations themselves.

---

## 1. External system ownership (most critical)

If the bundle annotation mentions a sync, ERP, or external system as source of record:

- **Ask:** is the proposed field ERP-managed or CMS-native?
- ERP-managed fields need a matching ERP mapping, or they will be silently overwritten on the next sync.
- CMS-native fields on ERP-synced types survive only if the migration explicitly skips unmapped fields.
- When in doubt, confirm with the team that owns the integration before advising.

## 2. Who approves changes?

- Does modifying this field require a ticket, sign-off, or workflow gate?
- Or is it free for editors to set?
- Should it be read-only in the CMS (like a field sourced from an external system)?

## 3. API and integration surface

- Is this content type read by a headless consumer, carousel API, or third-party integration?
- Does the new field need to appear in that API response? If yes, that is a separate development task beyond the field definition.
- For types where the annotation says changes "go live on the next cache clear", confirm with the dev team before proceeding.

## 4. Status gates and display conditions

- Does the new field need to be populated before a status transition is allowed?
- Should it affect a display fallback rule (like a label field falling back to the node title)?
- If yes, these are configuration tasks separate from creating the field.

## 5. Field type

Confirm the correct Drupal field type. Getting the schema wrong early is cheap on a dev-only project with no update hooks; getting it wrong once real content exists is not.

- Text (long) vs String: does it need a hard character limit or unlimited length?
- Reference vs plain text: does the value come from a fixed vocabulary or another entity?
- Single vs multi-value: can this field hold multiple values?
- Boolean vs list: is it a toggle or a fixed set of options?

## 6. Annotation coverage for the new field

Once the field is added, write an annotation for it. An undocumented field degrades coverage scores and is invisible to future AI context consumers. Apply the same signal patterns that would help a future agent interpret it correctly: purpose, format rules, governance, and any external system relationships.
