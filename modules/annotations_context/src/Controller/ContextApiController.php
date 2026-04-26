<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\annotations_context\ContextAssembler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoint returning the assembled context payload for a single target.
 *
 * Intended for headless consumers (Canvas, React, Mercury) that need
 * annotation data without an AI dependency. Shares the assembler with
 * annotations_ai_context * and ContextPreviewController; no AI module required.
 */
class ContextApiController extends ControllerBase {

  public function __construct(
    private readonly ContextAssembler $assembler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_context.assembler'),
    );
  }

  /**
   * Returns the assembled context payload for a target as JSON.
   *
   * Query parameters (all optional):
   *   ref_depth=0|1|2        — entity reference traversal depth (default 0)
   *   include_field_meta=1   — include field type/cardinality/description.
   */
  public function endpoint(string $target_id, Request $request): CacheableJsonResponse {
    $target = $this->entityTypeManager()->getStorage('annotation_target')->load($target_id);

    if (!$target) {
      $response = new CacheableJsonResponse(['error' => 'Annotation target not found.'], 404);
      $meta = new CacheableMetadata();
      // Cache 404 against tag so it invalidates if target is later created.
      $meta->addCacheTags(['annotation_target_list']);
      $response->addCacheableDependency($meta);
      
      return $response;
    }

    $options = [
      'target_id' => $target_id,
      'account'   => $this->currentUser(),
    ];

    $ref_depth_raw = $request->query->get('ref_depth');
    if ($ref_depth_raw !== NULL) {
      $options['ref_depth'] = max(0, (int) $ref_depth_raw);
    }

    if ($request->query->get('include_field_meta') === '1') {
      $options['include_field_meta'] = TRUE;
    }

    $payload = $this->assembler->assemble($options);

    $meta = new CacheableMetadata();
    $meta->addCacheTags(['annotation_list', 'annotation_target_list', 'annotation_type_list']);
    $meta->addCacheContexts(array_merge(
      ['languages:language_interface', 'url.query_args', 'user.permissions'],
      $this->languageManager()->isMultilingual() ? ['languages:content'] : [],
    ));
    // Fold in cache metadata contributed by hook_annotations_context_alter().
    $meta->merge($this->assembler->getLastCacheableMetadata());

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($meta);

    return $response;
  }

}
