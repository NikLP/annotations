<?php

declare(strict_types=1);

namespace Drupal\annotations;

/**
 * Provides dynamic consume permissions for installed AnnotationType entities.
 *
 * Called as a permission_callbacks entry in annotations.permissions.yml.
 * Generates one consume permission per type:
 *
 *   consume {id} annotations — inclusion in end-user context output
 *     (annotations_context, annotations_ai_context)
 *
 * Returns early (no permissions) if the entity type is not yet registered,
 * which can happen during initial module install.
 *
 * Edit permissions live in annotations_ui (AnnotationsUiPermissions) since
 * editing requires that module. Consume permissions live here because
 * annotations_context and annotations_ai_context consume them regardless of
 * whether annotations_ui is installed.
 */
class AnnotationsPermissions {

  /**
   * Returns per-type consume permissions.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, restrict access: bool}>
   */
  public static function permissions(): array {
    $permissions = [];
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition('annotation_type')) {
      return $permissions;
    }
    $storage = $entity_type_manager->getStorage('annotation_type');
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $type */
    foreach ($storage->loadMultiple() as $type) {
      $permissions[$type->getConsumePermission()] = [
        'title' => t('%label: consume annotations', ['%label' => $type->label()]),
        'restrict access' => FALSE,
      ];
    }
    return $permissions;
  }

}
