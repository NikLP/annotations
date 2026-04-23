# Annotations Context CCC

Bridges the [Annotations](../../README.md) module suite into [AI Context (CCC)](https://www.drupal.org/project/ai_context) by injecting assembled annotations documentation into AI agent system prompts.

## Requirements

- `annotations:annotations_context`
- `ai_agents:ai_agents`
- `ai_context:ai_context`

## Installation

Enable the module:

```bash
drush en annotations_context_ccc
```

If `annotations_ai_context` is enabled, disable it — these two modules serve the same purpose and should not run together:

```bash
drush pmu annotations_ai_context
```

## Configuration

Opt annotation types into injection on their edit form: **Admin > Config > Annotations > Annotation Types > [edit type] > Include in AI context**.

Types without this flag are never injected regardless of this module being enabled.

No other configuration is required. The module fires automatically whenever any AI agent builds its system prompt.

## How it works

Subscribes to `BuildSystemPromptEvent` at priority 50 (after CCC's own subscriber at 100):

1. Loads annotation types with `in_ai_context = TRUE`. Skips if none.
2. Detects a content entity from the current route or event tokens (supports Canvas AI's `entity_type` + `entity_id` token pair).
3. If an entity is found and a matching `annotation_target` exists, scopes the payload to that target only. Falls back to all opted-in targets otherwise.
4. Assembles via `ContextAssembler`, renders to markdown via `ContextRenderer`.
5. Appends under `## Site Documentation` in the system prompt. Skips if output is empty.

## Scope control

Injection is scoped by annotation target (`entity_type__bundle`). On a node edit page the subscriber detects the node, resolves `node__article` (for example), and only injects annotations relevant to that content type. Outside entity context all opted-in targets are included.

Fine-grained control over what gets injected is handled at the annotation type level via `in_ai_context` — there is no separate per-agent opt-out setting. To exclude content from a specific agent, create a dedicated annotation type without the flag enabled.
