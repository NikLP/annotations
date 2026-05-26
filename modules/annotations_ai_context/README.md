# Annotations AI Context

Bridges the [Annotations](../../README.md) module suite into [AI Context](https://www.drupal.org/project/ai_context) by injecting assembled annotations documentation into AI agent system prompts.

## Requirements

- `annotations:annotations_context`
- `ai_agents:ai_agents`
- `ai_context:ai_context`
- `ai:ai_assistant_api`

## Installation

Enable the module:

```bash
drush en annotations_ai_context
```

## Bundled chatbot

Enabling this module installs two AI agents and an AI assistant out of the box:

- **Annotation agent** (`annotation_agent`) — the worker agent; receives annotation context via AI Context injection.
- **Annotations assistant** (`annotations_assistant`) — an orchestration agent that routes to the annotation agent.
- **Annotations assistant** (`annotations_assistant`, `ai_assistant`) — the chatbot wrapper; uses the site default LLM provider.

To expose the chatbot, place the **AI Chatbot** block via **Admin > Structure > Block layout** and select the `Annotations assistant` assistant. No further agent or assistant configuration is needed.

## Configuration

Opt annotation types into injection on their edit form: **Admin > Config > Annotations > Annotation Types > [edit type] > Include in AI context**.

Types without this flag are never injected regardless of this module being enabled.

## How it works

Subscribes to `BuildSystemPromptEvent` at priority 50 (after AI Context's own subscriber at 100):

1. Loads annotation types with `in_ai_context = TRUE`. Skips if none.
2. Detects a content entity from the current route or event tokens (supports Canvas AI's `entity_type` + `entity_id` token pair).
3. If an entity is found and a matching `annotation_target` exists, scopes the payload to that target only. Falls back to all opted-in targets otherwise.
4. Assembles via `ContextAssembler`, renders to markdown via `ContextRenderer`.
5. Appends under `## Site Documentation` in the system prompt. Skips if output is empty.

The module also ships an `AiContextProvider` plugin for tighter AI Context integration, but this requires a local patch to `ai_context` that has not yet been accepted upstream. See CLAUDE.md for details.

## Scope control

Injection is scoped by annotation target (`entity_type__bundle`). On a node edit page the subscriber detects the node, resolves `node__article` (for example), and only injects annotations relevant to that content type. Outside entity context all opted-in targets are included.

Fine-grained control over what gets injected is handled at the annotation type level via `in_ai_context` — there is no separate per-agent opt-out setting. To exclude content from a specific agent, create a dedicated annotation type without the flag enabled.
