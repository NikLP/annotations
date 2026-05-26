<?php

declare(strict_types=1);

namespace Drupal\annotations_ai_context\Plugin\AiContextProvider;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_context\Attribute\AiContextProvider;
use Drupal\ai_context\Plugin\AiContextProvider\AiContextProviderInterface;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contributes annotation documentation to agent system prompts.
 *
 * Injects markdown-rendered annotation content for annotation types that have
 * the annotations_context.in_ai_context third-party setting enabled. When an
 * entity is detectable from the current route or event tokens, injection is
 * scoped to the matching annotation_target; otherwise all opted-in targets
 * are included.
 */
#[AiContextProvider(
  id: 'annotations_context',
  label: new TranslatableMarkup('Annotations'),
  description: new TranslatableMarkup('Injects annotation documentation for opted-in annotation types into agent system prompts.'),
  weight: 50,
)]
class AnnotationsContextProvider extends PluginBase implements AiContextProviderInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('annotations_context.assembler'),
      $container->get('annotations_context.renderer'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(BuildSystemPromptEvent $event): bool {
    return !empty($this->getAiContextTypeIds());
  }

  /**
   * {@inheritdoc}
   */
  public function getContent(BuildSystemPromptEvent $event): string {
    $types = $this->getAiContextTypeIds();
    if (empty($types)) {
      return '';
    }

    $options = ['types' => $types, 'account' => $this->currentUser];

    $target_id = $this->resolveTargetId($event->getTokens());
    if ($target_id !== NULL) {
      $options['target_id'] = $target_id;
    }

    $payload = $this->assembler->assemble($options);
    $markdown = $this->renderer->render($payload);
    if (trim($markdown) === '') {
      return '';
    }

    return "## Site Documentation\n\n" . $markdown;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->pluginDefinition['weight'];
  }

  /**
   * Returns IDs of annotation types opted in to AI context injection.
   *
   * @return string[]
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
   * Checks the current route first, then falls back to event tokens. Returns
   * NULL when no entity is detectable or no annotation_target exists for the
   * detected entity type + bundle, falling back to all opted-in targets.
   */
  private function resolveTargetId(array $tokens): ?string {
    $entity = $this->getEntityFromRoute() ?? $this->getEntityFromTokens($tokens);
    if ($entity === NULL) {
      return NULL;
    }

    $target_id = $entity->getEntityTypeId() . '__' . $entity->bundle();
    return $this->entityTypeManager->getStorage('annotation_target')->load($target_id) !== NULL
      ? $target_id
      : NULL;
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
        && is_string($tokens['entity_type']) && is_numeric($tokens['entity_id'])
        && $this->entityTypeManager->hasDefinition($tokens['entity_type'])) {
      $entity = $this->entityTypeManager->getStorage($tokens['entity_type'])->load($tokens['entity_id']);
      if ($entity instanceof EntityInterface && $entity->access('view')) {
        return $entity;
      }
    }

    return NULL;
  }

}
