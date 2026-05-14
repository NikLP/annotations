# Interpreting Annotations

Annotations are written deliberately by the editorial or development team. Do not infer rules from the field schema alone — always read the annotation context first.

## Resource structure

The MCP endpoint returns markdown structured as:

```
# Content types

## {Bundle label}

{Bundle-level text — purpose, governance, external integrations, operational rules}

### Fields

#### {Field label}

{Field-level text — what to put here, format rules, character limits, system relationships}
```

Bundle-level text covers why the type exists, how it fits into the site's broader systems, and any hard rules about creation or deletion. Field-level text covers what goes in the field, format constraints, and relationships to external systems.

---

## Reading signals

**Empty resource text.** The target is in scope but no annotations have been written (or no annotation types have `in_ai_context` enabled). Do not treat this as "no rules apply." Flag it as undocumented and confirm with the team before advising.

**External system / sync language.** Phrases like "source of record", "nightly sync", "ERP", "overwritten on next sync", or "integration" signal that fields may be externally managed. A CMS-only edit may be silently lost on the next sync run. See @schema-changes.md for how to handle this.

**Approval / workflow language.** "Requires a ticket", "sign-off required", "do not update directly", "commercial team", "brand team" mean the field has a governed change process. Respect this in recommendations — do not suggest an ad-hoc edit.

**Hard rules.** Annotations containing "do not", "never", "must not", or "do not delete" are constraints, not suggestions. Honour them without exception.

**Status gate language.** If an annotation says a field must be populated before a status transition (e.g. "fill in before setting status to Available"), a new field with similar characteristics may need the same gate. Check whether that configuration exists or needs to be added.

**Coverage gaps.** A field that appears in the Drupal field list but has no annotation entry is undocumented from the AI's perspective. Use `?include_field_meta=1` to get Drupal's own field type and help text as a fallback signal, and flag the gap.

---

## Checking coverage via the JSON API

The JSON API returns the full structured payload — useful for inspecting which fields have annotations and which don't:

```bash
curl -s "https://<site>/api/annotations/node__article?include_field_meta=1" \
  -H "Authorization: Bearer <key>"
```

Fields with no `annotations` entries in the response payload are coverage gaps. The `meta.target_count` value shows how many targets were returned — if lower than expected, check `in_ai_context` settings on annotation types.
