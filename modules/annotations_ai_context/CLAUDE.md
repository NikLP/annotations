# CLAUDE.md — annotations_ai_context

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Connects annotations context to the site's AI chatbot. Provides a `GetSiteContext` function call tool that the LLM agent invokes to retrieve site-specific documentation, and an `AnnotationsChatBlock` to place the pre-configured chat UI on any page.

Depends on `annotations_context` for payload assembly and rendering. Depends on `ai`, `ai_assistant_api`, `ai_chatbot`, and `ai_agents` for the chatbot infrastructure.

## What it owns

### Plugins

| Plugin | Type | Purpose |
| --- | --- | --- |
| `GetSiteContext` | `AiFunctionCall` | Assembles annotations context scoped to the current page and returns it as text for the LLM |
| `AnnotationsChatBlock` | `Block` | Pre-configured DeepChat block wired to the Annotations assistant |

### Shipped config

`config/install/ai_agents.ai_agent.annotations.yml` — the orchestrating `AiAgent` entity:

- `system_prompt` — combined persona, routing rules, and tone/format instructions
- `tools: annotations_ai_context:get_site_context` — the function call tool
- `max_loops: 3`, `allow_history: session`

`config/install/ai_assistant_api.ai_assistant.annotations.yml` — the `AiAssistant` entity:

- `ai_agent: annotations` — delegates entirely to the AiAgent entity above
- `llm_provider: __default__` / `llm_model: __default__` — uses the admin-configured default provider

Both are editable via `/admin/config/ai/agents`.

## How it works

```text
User types → AiAssistant (annotations) → AiAgent (annotations)
  LLM sees system_prompt + tool description
  LLM calls GetSiteContext tool
    → reads contexts.current_route from request body
    → detects entity type/bundle from URL pattern
    → calls ContextAssembler::assemble(['types' => $aiTypes, ...])
    → renders to markdown, strips headings
    → returns documentation text to LLM
  LLM answers using only that documentation
```

## GetSiteContext

`src/Plugin/AiFunctionCall/GetSiteContext.php`

Function call plugin — the LLM invokes this tool automatically before answering. No parameters: it reads the current route from the AJAX request body (set by `DeepChatFormBlock`).

**Entity detection patterns:**

| URL pattern | Entity type |
| --- | --- |
| `/node/{id}` | `node` |
| `/taxonomy/term/{id}` | `taxonomy_term` |
| `/media/{id}` | `media` |
| `/user/{id}` | `user` |
| `/node/add/{bundle}` | `node` (create form) |
| `/media/add/{bundle}` | `media` (create form) |

Action suffixes (`/edit`, `/revisions`, `/delete`, etc.) are stripped before matching. Falls back to full site context if no entity is detected or no matching `annotation_target` exists.

## AnnotationsChatBlock

`src/Plugin/Block/AnnotationsChatBlock.php`

Extends `DeepChatFormBlock`. Hardwires `ai_assistant: annotations` — no assistant selection in the block config form. Sets `verbose_mode: FALSE` by default (verbose is for developers, not end users).

## AI instructions

The `AiAgent` entity's `system_prompt` (editable at `/admin/config/ai/agents/annotations/edit`) explicitly:

- Requires the LLM to call the `get_site_context` tool before answering anything
- Prevents answering from general Drupal knowledge
- Falls back to "I don't have specific information about that yet" rather than guessing

## Setup after `drush en annotations_ai_context`

1. Configure a default Chat provider at `/admin/config/ai/providers`
2. Place the **Annotations Site Assistant** block (Annotations category) in any region
3. Done — no further configuration required

## Deferred

### REST endpoint for decoupled editors (`annotations_context` or `annotations_ai_context`)

A lightweight `/api/annotations/{target_id}` endpoint returning JSON annotation data. Needed for any editing interface that bypasses the Drupal form pipeline and cannot use `hook_form_alter` — Drupal CMS Canvas being the primary example, but also any headless/decoupled front end.

**Design notes:**

- Simple `JsonResponse` controller, no JSON:API or REST contrib required. Route in `annotations_ai_context.routing.yml` (or `annotations_context` if it makes more sense to keep non-AI consumers separate).
- `{target_id}` maps directly to `annotation_target` machine name (e.g. `node__article`). Controller loads the target, checks it exists, then calls `AnnotationStorageService::getForTarget()`.
- Permission check: `view annotations overlay` gates access (same permission as the form overlay). Per-type `view {type} annotations` filtering applied server-side before returning.
- Response shape mirrors what `buildPanel()` already produces internally — array of `{type_id: {label, value}}` per field key, plus `_bundle` for the bundle-level overview.
- Cache tags: `annotation_list` + the specific `annotation_target` config entity cache tag so responses invalidate when annotations are saved.

**Canvas integration path** (future `annotations_canvas` submodule or Canvas plugin):

Canvas is a decoupled React frontend using JSON:API for content but extensible with custom React plugins. An `annotations_canvas` plugin would:

1. Read the current entity type/bundle from the Canvas editing context
2. Call `/api/annotations/{target_id}` on load
3. Render the response as a collapsible panel in the Canvas sidebar

This is viable but Canvas's plugin API is still stabilising (as of early 2026). The endpoint should be built first — it serves as the integration contract regardless of which decoupled editor calls it. Mercury Editor could use it too if the `hook_form_alter` approach proves unreliable in its AJAX context.

**The key proviso for Canvas/DCMS:** Even without the overlay UX, `annotations_ai_context` already works in any editing environment. The Annotations chat assistant has full site context regardless of whether the editor is standard Drupal forms, Mercury Editor, or Canvas. The endpoint/overlay work is purely about surfacing annotations inline while editing — the AI value is not blocked by any of this.

## Current status

- [x] `GetSiteContext` function call plugin — entity detection, context assembly, tool output
- [x] `AnnotationsChatBlock` plugin — pre-configured, verbose off
- [x] Shipped `ai_agents.ai_agent.annotations` config entity
- [x] Shipped `ai_assistant_api.ai_assistant.annotations` config entity
- [x] Role-based context filtering — `account` option passed to `ContextAssembler`; filters to types the current user can view via their combined role permissions
- [ ] Query logging — deferred; use `ai` module suite logging rather than a custom module
- [ ] `/api/annotations/{target_id}` endpoint — needed for Canvas and other decoupled editors
- [ ] Canvas panel plugin (`annotations_canvas`) — blocked on Canvas plugin API stability and endpoint above
