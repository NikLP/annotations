<?php

declare(strict_types=1);

namespace Drupal\annotations_context_ccc\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Appends annotations context to AI agent system prompts.
 *
 * Fires after CCC's own subscriber (priority 50 vs 100) so annotations
 * documentation follows curated CCC context items in the prompt.
 *
 * Only injects types with the annotations_context.in_ai_context third-party
 * setting enabled. When an entity is detectable from the current route or
 * event tokens, injection is scoped to the matching annotation_target;
 * otherwise all opted-in targets are included.
 */
final class AnnotationsContextCccSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', 50],
    ];
  }

  /**
   * Appends annotations context to the agent system prompt.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $types = $this->getAiContextTypeIds();
    if (empty($types)) {
      return;
    }

    $options = ['types' => $types];

    $target_id = $this->resolveTargetId($event->getTokens());
    if ($target_id !== NULL) {
      $options['target_id'] = $target_id;
    }

    $payload = $this->assembler->assemble($options);
    $markdown = $this->renderer->render($payload);
    if (trim($markdown) === '') {
      return;
    }

    $event->setSystemPrompt(
      $event->getSystemPrompt() . "\n\n## Site Documentation\n\n" . $markdown,
    );
  }

  /**
   * Returns IDs of annotation types opted in to AI context injection.
   *
   * @return string[]
   *   Annotation type machine names opted in to AI context injection.
   */
  private function getAiContextTypeIds(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all */
    $all = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
    $types = [];
    foreach ($all as $id => $type) {
      if ($type->getThirdPartySetting('annotations_context', 'in_ai_context', FALSE)) {
        $types[] = $id;
      }
    }
    return $types;
  }

  /**
   * Resolves an annotation_target ID from the current request context.
   *
   * Returns NULL when no entity is detectable or no annotation_target exists
   * for the detected entity type + bundle, falling back to all targets.
   */
  private function resolveTargetId(array $tokens): ?string {
    $entity = $this->getEntityFromRoute() ?? $this->getEntityFromTokens($tokens);
    if ($entity === NULL) {
      return NULL;
    }

    $target_id = $entity->getEntityTypeId() . '__' . $entity->bundle();

    try {
      return $this->entityTypeManager->getStorage('annotation_target')->load($target_id) !== NULL
        ? $target_id
        : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Returns the first content entity found in current route parameters.
   */
  private function getEntityFromRoute(): ?EntityInterface {
    foreach ($this->routeMatch->getParameters()->all() as $value) {
      if ($value instanceof EntityInterface) {
        return $value;
      }
    }
    return NULL;
  }

  /**
   * Returns the first entity found in event tokens.
   *
   * Accepts entity objects directly, or an entity_type + entity_id pair
   * (as passed by Canvas AI and similar decoupled editors).
   */
  private function getEntityFromTokens(array $tokens): ?EntityInterface {
    foreach ($tokens as $value) {
      if ($value instanceof EntityInterface) {
        return $value;
      }
    }

    if (!empty($tokens['entity_type']) && !empty($tokens['entity_id'])
        && is_string($tokens['entity_type']) && is_numeric($tokens['entity_id'])) {
      try {
        $entity = $this->entityTypeManager
          ->getStorage($tokens['entity_type'])
          ->load($tokens['entity_id']);
        if ($entity instanceof EntityInterface) {
          return $entity;
        }
      }
      catch (\Throwable) {
      }
    }

    return NULL;
  }

}
