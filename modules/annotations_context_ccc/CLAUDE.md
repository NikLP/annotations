# CLAUDE.md — annotations_context_ccc

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

Bridges `annotations_context` into AI Context (CCC / `ai_context` module) by appending assembled annotations documentation to agent system prompts.

## What it does

Subscribes to `BuildSystemPromptEvent` (priority 50, after CCC's own items at 100). When an agent fires:

1. Loads all annotation types with `annotations_context.in_ai_context = TRUE`.
2. If the current route or event tokens expose a content entity, resolves its `entity_type__bundle` to an `annotation_target` ID and scopes the payload to that target only.
3. Falls back to all opted-in targets when no entity context is detectable.
4. Renders via `ContextRenderer` (markdown) and appends under `## Site Documentation`.
5. Skips entirely if no types are opted in or the rendered output is empty.

## Dependencies

- `annotations:annotations_context` — `ContextAssembler` + `ContextRenderer`
- `ai_agents:ai_agents` — `BuildSystemPromptEvent`
- `ai_context:ai_context` — declares this as a CCC integration; gates installation

## Enabling annotation types for injection

On the annotation type edit form, check **Include in AI context** (the `annotations_context.in_ai_context` third-party setting). Types with this off are never injected regardless of this module being enabled.

## Replacing annotations_ai_context

This module supersedes `annotations_ai_context`. Disable that module once this one is enabled — they serve the same purpose via different mechanisms.

---

## Architectural position

Annotations adheres to CCC rather than the other way around. CCC is the primary system for context delivery into agent prompts.

### Why an event subscriber, not a scope plugin

CCC's `AiContextScope` plugin type is for matching `AiContextItem` entities to the current request context — it is not a content-source API. A scope plugin cannot inject external content; it only filters existing entities. The event subscriber is therefore the correct integration shape given CCC's current architecture.

### Current gap: not a CCC citizen

Our subscriber appends annotations content outside CCC's data model. Consequences:

- No visibility in CCC's admin UI — admins cannot see annotations injection from CCC's interface
- CCC's scope dimensions (use case, tag, site section) do not apply to annotations content
- Always appends after CCC's assembled block; cannot be interleaved by weight

These are tooling gaps, not runtime failures. The agent receives the documentation correctly.

### Proposed upstream: `AiContextProvider` plugin type

The right fix is contributing a dynamic content-source plugin type to `ai_context` upstream. Design sketch:

```php
// In ai_context:
interface AiContextProviderInterface extends PluginInspectionInterface {
  public function isApplicable(BuildSystemPromptEvent $event): bool;
  public function getContent(BuildSystemPromptEvent $event): string;
}
```

Plugin attribute carries `id`, `label`, `weight`, `description`. CCC's subscriber loads all providers after its own `AiContextItem` assembly, calls `isApplicable()`, merges `getContent()` results ordered by weight.

Our implementation would replace the event subscriber with an `#[AiContextProvider(id: 'annotations_context', weight: 50)]` plugin — identical logic, but CCC owns orchestration, providers are visible in the admin UI, and weight-based ordering works correctly.

Contribution footprint: new plugin type + manager in `ai_context`, ~15 lines added to CCC's subscriber. Worth proposing to CCC maintainers.

### Per-agent opt-out: not needed

The `annotations_context.in_ai_context` flag on annotation types already gates injection per type. An agent that should not receive certain documentation simply has no opted-in types covering that content. Per-agent opt-out would only add value for "type X to agent A but not agent B" — a cross-product that the type-level flag handles adequately.

---

## Status

- [x] `AnnotationsContextCccSubscriber` — entity detection, target scoping, markdown injection
- [ ] Upstream `AiContextProvider` plugin type contribution to `ai_context` (see above)
- [ ] Migrate subscriber to `AiContextProvider` plugin once upstream accepts the type
