# CLAUDE.md — annotations_context_ccc

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

Bridges `annotations_context` into AI Context (CCC / `ai_context` module) by appending assembled annotations documentation to agent system prompts.

## What it does

When an agent fires, annotations documentation is injected into the system prompt:

1. Loads all annotation types with `annotations_context.in_ai_context = TRUE`.
2. If the current route or event tokens expose a content entity, resolves its `entity_type__bundle` to an `annotation_target` ID and scopes the payload to that target only.
3. Falls back to all opted-in targets when no entity context is detectable.
4. Renders via `ContextRenderer` (markdown) and appends under `## Site Documentation`.
5. Skips entirely if no types are opted in or the rendered output is empty.

## Dependencies

- `annotations:annotations_context` — `ContextAssembler` + `ContextRenderer`
- `ai_agents:ai_agents` — `BuildSystemPromptEvent`
- `ai_context:ai_context` — declares this as a CCC integration; gates installation
- `ai:ai_assistant_api` — provides the `ai_assistant` config entity type shipped in `config/install`

## Bundled config

`config/install/` ships three config entities installed automatically when the module is enabled:

| File | Entity | Role |
| --- | --- | --- |
| `ai_agents.ai_agent.annotation_agent.yml` | AI Agent | Worker agent; receives annotation context from CCC injection |
| `ai_agents.ai_agent.annotations_assistant.yml` | AI Agent | Orchestration agent; routes to `annotation_agent` as a tool |
| `ai_assistant_api.ai_assistant.annotations_assistant.yml` | AI Assistant | Chatbot wrapper; uses `__default__` provider; exposes via AI Chatbot block |

The assistant uses the site default LLM provider (`__default__`) so it works regardless of which provider is configured. Roles `administrator` and `author` have access by default — adjust on the assistant edit form as needed.

To expose the chatbot after enabling the module, place the **AI Chatbot** block and select `Annotations assistant`.

## Enabling annotation types for injection

On the annotation type edit form, check **Include in AI context** (the `annotations_context.in_ai_context` third-party setting). Types with this off are never injected regardless of this module being enabled.

## Replacing annotations_ai_context

This module supersedes `annotations_ai_context`. Disable that module once this one is enabled — they serve the same purpose via different mechanisms.

---

## Two mechanisms — both present, one universal

This module ships two implementations of the same injection logic. The subscriber is the shipped default; the plugin only activates on environments where the upstream patch has been applied.

### Shipped default: `AnnotationsContextCccSubscriber`

`src/EventSubscriber/AnnotationsContextCccSubscriber.php` — a direct `BuildSystemPromptEvent` subscriber at priority 50 (after CCC's own subscriber at 100). Registered in `annotations_context_ccc.services.yml` and active on all environments.

This works without any changes to `ai_context` but is not a CCC citizen: no admin UI visibility, no weight ordering relative to other CCC providers.

### Patch-only: `AnnotationsContextProvider` plugin

`src/Plugin/AiContextProvider/AnnotationsContextProvider.php` — an `#[AiContextProvider]` plugin auto-discovered by `AiContextProviderManager`. Only active on environments where the local `ai_context` patch has been applied (see below). On those environments both mechanisms fire, injecting the same content twice — this is acceptable while the patch remains a local-only development tool.

This is the correct long-term integration shape: annotations context becomes a proper CCC citizen, visible in the admin UI and sortable by weight alongside other providers. Once the patch is accepted upstream and the plugin type ships in a released version of `ai_context`, the subscriber can be removed.

---

## The `ai_context` patch

The `AiContextProvider` plugin type does not exist in `ai_context` 1.0.0-beta2 (the current Packagist release). It has been added locally as an unmanaged patch to the installed copy of the module.

**Modified files in `web/modules/contrib/ai_context/` vs 1.0.0-beta2:**

| File | Change |
| --- | --- |
| `ai_context.services.yml` | Registers `AiContextProviderManager` as a service |
| `src/EventSubscriber/AiContextSystemPromptSubscriber.php` | Injects the manager and calls `getProvidersSortedByWeight()` after item assembly |

**Untracked additions (new files):**

- `src/AiContextProviderManager.php`
- `src/Attribute/AiContextProvider.php`
- `src/Plugin/AiContextProvider/AiContextProviderInterface.php`

---

## Architectural position

Annotations adheres to CCC rather than the other way around. CCC is the primary system for context delivery into agent prompts.

### Why not a scope plugin

CCC's `AiContextScope` plugin type is for matching `AiContextItem` entities to the current request context — it is not a content-source API. A scope plugin cannot inject external content; it only filters existing entities. A direct event subscriber (or the `AiContextProvider` plugin type once merged) is the correct integration shape.

### Per-agent opt-out: not needed

The `annotations_context.in_ai_context` flag on annotation types already gates injection per type. An agent that should not receive certain documentation simply has no opted-in types covering that content. Per-agent opt-out would only add value for "type X to agent A but not agent B" — a cross-product that the type-level flag handles adequately.

---

## Status

- [x] `AnnotationsContextCccSubscriber` — active; registered in `services.yml`; works on all environments
- [x] `AnnotationsContextProvider` plugin — active on patched environments only; duplicates injection there but harmlessly
- [ ] Formalise `ai_context` patch via `composer-patches` so it survives `composer update`
- [ ] Open upstream MR/issue on drupal.org for `AiContextProvider` plugin type
- [ ] Remove subscriber once upstream patch is accepted and released
