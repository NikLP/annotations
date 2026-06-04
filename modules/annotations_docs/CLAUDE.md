# CLAUDE.md — annotations_docs

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

Generates AI-authored documentation for annotation targets and stores it as `annotations_document` nodes in a two-panel explorer UI at `/annotations/documents`.

## What it does

1. Assembles context for an `annotation_target` via `annotations_context.assembler` (all annotation types, with field metadata)
2. Sends it to the configured AI chat provider (via `ai.provider`) with a configurable system prompt (stored as an `ai_prompt` config entity)
3. Stores the response as an `annotations_document` node (draft by default)
4. Displays all targets and their documents in a two-panel browser

## Routes

| Route | Path | Permission |
| --- | --- | --- |
| `annotations_docs.page` | `/annotations/documents` | `access annotation documents` or `administer annotation documents` (custom access) |
| `annotations_docs.target` | `/annotations/documents/{annotation_target}` | `access annotation documents` or `administer annotation documents` (custom access, AJAX panel) |
| `annotations_docs.generate` | `/annotations/documents/generate/{annotation_target}` | `generate annotation documents` |

## Permissions

| Permission | Purpose |
| --- | --- |
| `access annotation documents` | View the browser UI and `annotations_document` nodes directly |
| `generate annotation documents` | Trigger AI generation/regeneration |
| `administer annotation documents` | Full administration; implies `access annotation documents` (view) but **not** `generate annotation documents` — generation must always be explicitly granted because it potentially triggers paid AI API calls |

Node view access for `annotations_document` nodes is granted via `hook_node_access` when the account has `access annotation documents` or `administer annotation documents`, regardless of publish status.

## Key classes

| Class | Purpose |
| --- | --- |
| `DocumentGeneratorService` | Assembles context, calls AI (decodes HTML entities, converts markdown to HTML), saves node, writes KV timestamp |
| `DocumentsController` | Two-panel page and AJAX target panel |
| `GenerateDocumentForm` | Confirmation form for generate/regenerate; AJAX submit with throbber + `RedirectCommand`; non-JS `submitForm` fallback |

## Content type: `annotations_document`

| Field | Machine name | Type | Notes |
| --- | --- | --- | --- |
| Title | `title` | node base field | Auto-set to the target label (no suffix) |
| Body | `annotations_doc_body` | `text_long` | AI-generated HTML (markdown→HTML via CommonMark); stored as `full_html` |
| Target | `annotations_doc_target` | `string` | `annotation_target` machine name; one doc per target enforced at service level |

Both `uid` and `annotations_doc_target` are hidden from the node edit form — they are set programmatically and must not be user-editable.

`annotations_document` is intentionally excluded from the annotation targets list. `NodeTarget::getBundles()` in the root module filters out any node type whose machine name starts with `annotations_` — annotating internally generated content would be circular, and the `annotations_` prefix is the established convention for module-owned node types.

## Generation timestamp

Last-generated timestamp stored in `annotation_docs.generated.{target_id}` via the expirable key-value store (expires after 2 years). Displayed in the main panel as "Last generated: N days ago". No staleness threshold — regeneration is always free.

## Generation pipeline

The `ai` module's `getText()` returns HTML-encoded responses (e.g. `&gt;` instead of `>`). The service runs `html_entity_decode()` before passing to `League\CommonMark\CommonMarkConverter`, which converts the markdown output to HTML. The result is stored as `full_html`.

LLMs reliably output markdown regardless of HTML instructions, so the pipeline always treats AI output as markdown.

## Generation prompt

The system prompt is stored as an `ai_prompt` config entity: `ai.ai_prompt.annotations_docs__generate__default`. Site admins can edit it at `/admin/config/ai/prompts`. The service falls back to a hardcoded default if the config entity is absent.

The prompt type is `annotations_docs__generate`. No variables or tokens — context is sent as the user message, not interpolated into the system prompt. The system prompt explicitly instructs the AI not to open with an H1/H2 for the content type name, as the page title already provides that.

## Dependencies

- `annotations:annotations_context` — context assembly and markdown rendering
- `ai:ai` — `AiProviderPluginManager` for chat calls and `ai_prompt` config entity
- `node:node` — document storage

## Deferred

- Personalised documents per user/role: generate a document scoped to what a specific role can see and do. Requires passing `role` or `account` to `ContextAssembler::assemble()`.
- Multiple document types per target (e.g. editor guide vs developer reference) with separate prompts.
- Site overview document covering all enabled targets.
- Content moderation workflow as an optional layer over draft/published.
- Staleness detection wired to `annotations_audit` structural change fingerprints.
- Drush command / ECA automation for bulk generation (Batch API for admin UI; Queue API for drush/cron).
- Settings form to select a non-default `ai_prompt` entity.
