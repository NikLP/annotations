# ADR: Upstream AiContextProvider plugin type for ai_context

**Status:** Proposed — pending hands-on validation and upstream discussion  
**Module affected:** `annotations_ai_context`  
**Upstream target:** [drupal.org/project/ai_context](https://www.drupal.org/project/ai_context)

---

## Context

`ai_context` manages curated, admin-authored context items and delivers them
into agent system prompts via its `AiContextSystemPromptSubscriber`. It has an
`AiContextScope` plugin type for filtering which items apply to a given request.

`AiContextScope` is not a content-source API. It filters existing `AiContextItem`
entities; it cannot inject content that does not already exist as an entity.
Modules with dynamically-generated context (assembled from database state, the
current entity, user permissions, etc.) have no native integration point. The only
option is a direct `BuildSystemPromptEvent` subscriber that operates outside AI
Context's model: invisible in the admin UI, not subject to its loop-aware token
optimisation, and not weight-ordered relative to its own output.

`annotations_ai_context` is currently in this position. Its subscriber appends
annotations documentation to the prompt correctly at runtime, but AI Context has no
visibility into it.

## Decision

Propose a new `AiContextProvider` plugin type to the `ai_context` maintainers.
Providers contribute dynamic content into the same assembled block as AI Context's own
items, making them genuine participants in the pipeline rather than bolt-ons.

Once accepted upstream, replace `AnnotationsAiContextSubscriber` with an
`AnnotationsContextProvider` plugin — identical logic, different ownership.

## Proposed interface

```php
// src/Plugin/AiContextProvider/AiContextProviderInterface.php

interface AiContextProviderInterface extends PluginInspectionInterface {
  public function isApplicable(BuildSystemPromptEvent $event): bool;
  public function getContent(BuildSystemPromptEvent $event): string;
  public function getWeight(): int;
  public function getLabel(): string;
}
```

```php
// src/Attribute/AiContextProvider.php

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AiContextProvider extends Plugin {
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}
}
```

## Change to AiContextSystemPromptSubscriber

The subscriber's early return on empty `$contextText` must move to after provider
collection, so providers can contribute content even when no `AiContextItem`
entities are applicable.

```php
$contextText = $result->getRenderedText();

foreach ($this->providerManager->getProvidersSortedByWeight() as $provider) {
  if ($provider->isApplicable($event)) {
    $providerText = $provider->getContent($event);
    if (!empty($providerText)) {
      $contextText .= ($contextText ? "\n\n" : '') . $providerText;
    }
  }
}

if (empty($contextText)) {
  return;
}
```

Loop-aware skip, usage tracking, and prompt wrapping are unchanged — provider
content benefits from them automatically.

## Footprint in ai_context

| File | Change |
| --- | --- |
| `src/Attribute/AiContextProvider.php` | New, ~20 lines |
| `src/Plugin/AiContextProvider/AiContextProviderInterface.php` | New, ~15 lines |
| `src/AiContextProviderManager.php` | New, ~30 lines (mirrors `AiContextScopeManager`) |
| `ai_context.services.yml` | One new `plugin.manager.ai_context_provider` entry |
| `AiContextSystemPromptSubscriber.php` | ~10 line addition to `onPreSystemPrompt()` |

No schema changes, no new entities, no breaking changes.

## Our implementation once upstream accepts it

```php
#[AiContextProvider(
  id: 'annotations_context',
  label: new TranslatableMarkup('Annotations'),
  weight: 50,
)]
class AnnotationsContextProvider implements AiContextProviderInterface {
  // Entity detection, target scoping, markdown rendering —
  // identical to AnnotationsAiContextSubscriber, different class shape.
}
```

`annotations_ai_context.services.yml` drops the event subscriber entry entirely.

## Before posting the upstream issue

- [ ] Enable ai_agents, ai_context, and annotations_ai_context in DDEV
- [ ] Create AiContextItem entities via the AI Context admin UI; observe what it manages
- [ ] Trigger an agent session; inspect the assembled system prompt
- [ ] Confirm annotations content appears but is absent from AI Context's admin UI
- [ ] Verify subscriber change handles the empty-items / non-empty-provider case
- [ ] Confirm return type of `getContent()` — string is proposed; consider whether CacheableMetadata is needed
