# ADR 0002: Fields map as inclusion list, not exclusion list

**Status:** Accepted

## Context

For fieldable targets (content types, taxonomy vocabularies, media types, etc.), DOT needs to know which fields are in scope for scanning and annotation. The question was whether to store an inclusion list (opt-in) or an exclusion list (opt-out with a blocklist).

Opt-out is simpler for the user at first: everything is in by default, you remove what you don't want. But it creates a problem: new fields added to a content type would silently appear in scope without the site owner knowing. It also requires storing the exclusion list persistently, which means schema changes ripple into the config entity.

## Decision

The `fields` map on `dot_target` is an **inclusion list**. A field is in scope if and only if its machine name appears as a key in the `fields` map. Absence means excluded. On first opt-in, all editorial fields are pre-populated (all included by default), so the user experience starts at opt-out; subsequent field changes require explicit re-inclusion.

## Consequences

- New fields added to a content type after initial opt-in do not automatically appear in scope — the scanner detects them as new and surfaces them for review
- No separate `excluded_fields` array; the schema stays simple
- The `isFieldIncluded(string $field_name): bool` method on `DotTargetInterface` is a simple `array_key_exists` check
- Annotation data for a field is stored inline in its `fields` map entry; removing a field from scope removes its annotation data
