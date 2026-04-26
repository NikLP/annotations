<?php

declare(strict_types=1);

namespace Drupal\annotations_ai_context\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\PluginManager\AiDataTypeConverterPluginManager;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Assembles annotations context for the current page and returns it.
 *
 * The orchestrating LLM agent uses the returned documentation to answer
 * site-specific questions without drawing on general Drupal knowledge.
 */
#[FunctionCall(
  id: 'annotations_ai_context:get_site_context',
  function_name: 'annotations_ai_context_get_site_context',
  name: 'Get Annotations Site Context',
  description: 'Retrieves documentation for this specific Drupal site: content types, fields, editorial guidelines, site purpose, and workflows. Always call this before answering any question about how to use the site.',
  group: 'annotations_ai_context',
  module_dependencies: ['annotations_context'],
)]
class GetSiteContext extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The context assembler.
   */
  protected ContextAssembler $assembler;

  /**
   * The context renderer.
   */
  protected ContextRenderer $renderer;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The alias manager.
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ContextDefinitionNormalizer $context_definition_normalizer,
    AiDataTypeConverterPluginManager $data_type_converter_manager,
    ContextAssembler $assembler,
    ContextRenderer $renderer,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
    AliasManagerInterface $alias_manager,
    AccountProxyInterface $current_user,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $context_definition_normalizer, $data_type_converter_manager);
    $this->assembler = $assembler;
    $this->renderer = $renderer;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasManager = $alias_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
      $container->get('annotations_context.assembler'),
      $container->get('annotations_context.renderer'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('path_alias.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $allTypes */
    $allTypes = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
    $aiTypes = array_keys(array_filter(
      $allTypes,
      fn($t) => $t->getThirdPartySetting('annotations_context', 'in_ai_context', FALSE),
    ));

    $options = ['types' => $aiTypes, 'account' => $this->currentUser];
    $targetId = $this->detectTargetFromRequest();
    if ($targetId) {
      $options['target_id'] = $targetId;
    }

    $payload = $this->assembler->assemble($options);

    // Strip markdown headings so the LLM doesn't mimic heading structure.
    $contextMarkdown = preg_replace('/^#{1,6}\s+/m', '', $this->renderer->render($payload));

    if (empty(trim($contextMarkdown))) {
      $this->stringOutput = (string) $this->t('No site documentation is available yet. Please add annotations to your content types and fields.');
      return;
    }

    $this->stringOutput = $contextMarkdown;
  }

  /**
   * Detects an annotation_target ID from context sent in the request body.
   *
   * Strategy 1 (preferred): reads contexts.route_name and contexts.route_params
   * sent by AnnotationsChatBlock and derives entity type + ID directly from the
   * route parameter key (e.g. 'node' => '42').
   *
   * Strategy 2 (fallback): reads contexts.current_route, resolves any path
   * alias to its system path, then pattern-matches common Drupal entity URLs.
   *
   * @return string|null
   *   An annotation_target ID like 'node__article', or NULL if not detected.
   */
  protected function detectTargetFromRequest(): ?string {
    $content = $this->requestStack->getCurrentRequest()->getContent();
    if (empty($content)) {
      return NULL;
    }

    $data = json_decode($content, TRUE);
    $contexts = $data['contexts'] ?? [];

    // Strategy 1: route-name based detection.
    $routeParams = $contexts['route_params'] ?? [];
    if (!empty($contexts['route_name']) && $routeParams) {
      foreach (['node', 'taxonomy_term', 'media', 'user'] as $entityType) {
        if (isset($routeParams[$entityType]) && is_numeric($routeParams[$entityType])) {
          $result = $this->resolveTargetId($entityType, (int) $routeParams[$entityType]);
          if ($result) {
            return $result;
          }
        }
      }
    }

    // Strategy 2: alias-resolved URL pattern matching.
    $route = $contexts['current_route'] ?? '';
    if (empty($route)) {
      return NULL;
    }

    $path = parse_url($route, PHP_URL_PATH) ?? $route;
    $path = preg_replace('#/(edit|revisions|delete|latest|translations)$#', '', $path);
    $path = $this->aliasManager->getPathByAlias($path);

    $createPatterns = [
      '#^/node/add/([^/]+)$#' => 'node',
      '#^/media/add/([^/]+)$#' => 'media',
    ];

    foreach ($createPatterns as $pattern => $entityType) {
      if (preg_match($pattern, $path, $matches)) {
        return $this->resolveTargetIdFromBundle($entityType, $matches[1]);
      }
    }

    $entityPatterns = [
      '#^/node/(\d+)$#' => 'node',
      '#^/taxonomy/term/(\d+)$#' => 'taxonomy_term',
      '#^/media/(\d+)$#' => 'media',
      '#^/user/(\d+)$#' => 'user',
    ];

    foreach ($entityPatterns as $pattern => $entityType) {
      if (preg_match($pattern, $path, $matches)) {
        return $this->resolveTargetId($entityType, (int) $matches[1]);
      }
    }

    return NULL;
  }

  /**
   * Returns an annotation_target ID from a known entity type and bundle.
   */
  protected function resolveTargetIdFromBundle(string $entityType, string $bundle): ?string {
    $targetId = $entityType . '__' . $bundle;
    if ($this->entityTypeManager->getStorage('annotation_target')->load($targetId)) {
      return $targetId;
    }
    return NULL;
  }

  /**
   * Loads an entity and returns its annotation_target ID if a target exists.
   */
  protected function resolveTargetId(string $entityType, int $entityId): ?string {
    $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
    if (!$entity) {
      return NULL;
    }
    $targetId = $entityType . '__' . $entity->bundle();
    if ($this->entityTypeManager->getStorage('annotation_target')->load($targetId)) {
      return $targetId;
    }
    return NULL;
  }

}
