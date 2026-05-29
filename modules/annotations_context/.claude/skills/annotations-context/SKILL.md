---
name: annotations-context
description: Use the Annotations Context MCP endpoint to read structured annotation data about a Drupal site's content model — editorial guidance, field rules, governance notes — and apply it when advising on content changes or schema decisions.
---

# Annotations Context

The `annotations_context` module exposes structured annotation data via an MCP endpoint (`POST /api/annotations/mcp`) and a JSON API (`GET /api/annotations/{target_id}`). Annotations describe purpose, governance, integration dependencies, and field rules for content types. They are the canonical AI context for a site's content model — written by the editorial or development team, not inferred from the schema.

## When This Skill Activates

- The `annotations` MCP server is configured in `.claude/settings.local.json`
- You need to understand what a content type or field is for before editing it
- You're about to recommend adding, modifying, or removing a field
- You're reviewing annotation coverage on a content type
- You're writing new annotations and need to understand the data model

---

## Step 1: Get the connection details

Read `.claude/settings.local.json` to get the endpoint URL and Bearer token:

```json
{
  "mcpServers": {
    "annotations": {
      "type": "http",
      "url": "https://<site>/api/annotations/mcp",
      "headers": { "Authorization": "Bearer <key>" }
    }
  }
}
```

---

## Step 2: Initialize and list resources

The MCP server's resources may not auto-load into the Claude Code session. Use curl directly.

```bash
# Initialize (required first call)
curl -s -X POST <url> \
  -H "Authorization: Bearer <key>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"claude-code","version":"1.0"}}}'

# List available targets
curl -s -X POST <url> \
  -H "Authorization: Bearer <key>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"resources/list","params":{}}'
```

Resource URIs follow the pattern `annotation://target/{target_id}` where `target_id` is `{entity_type}__{bundle}` (e.g. `node__article`, `media__image`).

**If `resources/list` returns an empty array:** no annotation types have `in_ai_context` enabled. Go to Admin → Annotations → Types and enable it on the relevant types.

---

## Step 3: Read a target's context

```bash
curl -s -X POST <url> \
  -H "Authorization: Bearer <key>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"annotation://target/node__article"}}'
```

Returns markdown text. **Empty `text` = annotations have not been written for this target yet.** Do not treat this as "no rules apply" — flag it as undocumented.

Append query parameters to the URI string for richer context:

| Parameter | Values | Effect |
|---|---|---|
| `?ref_depth=1` | `1` or `2` | Follow entity-reference fields into referenced targets |
| `?inc_meta=1` | `1` | Add Drupal field type, cardinality, and help text |
| `?inc_refs=1` | `1` | Add `incoming_refs` to each target (reverse ER sources) |

Example: `annotation://target/node__article?ref_depth=1&inc_meta=1`

Read all relevant targets in parallel — `resources/read` calls are independent.

---

## Step 4: Apply the context

See @references/interpreting.md for how to read annotation text and identify coverage gaps.
See @references/schema-changes.md for the pre-flight checklist before recommending field or schema changes.

---

## Drush alternative

```bash
ddev drush ann:ctx                            # all targets
ddev drush ann:ctx --target=node__article    # one target
ddev drush ann:ctx --inc-meta                # include field type/cardinality
ddev drush ann:ctx --ref-depth=1             # follow ER fields one hop
```

Same assembled context as markdown. Useful for quick inspection without curl.

---

## Available Topics

- @references/interpreting.md — Reading annotation content and identifying coverage gaps
- @references/schema-changes.md — Pre-flight checklist before recommending field or schema changes
