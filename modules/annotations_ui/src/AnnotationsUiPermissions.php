<?php

declare(strict_types=1);

namespace Drupal\annotations_ui;

/**
 * Provides dynamic edit permissions for installed AnnotationType entities.
 *
 * Called as a permission_callbacks entry in annotations_ui.permissions.yml.
 * Generates one edit permission per type:
 *
 *   edit {id} annotations — write access in the annotation form
 *
 * These live in annotations_ui because editing annotations requires this
 * module. Consume permissions live in annotations (AnnotationsPermissions)
 * since context modules consume them independently of annotations_ui.
 */
class AnnotationsUiPermissions {

  /**
   * Returns per-type edit permissions.
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
      $permissions[$type->getPermission()] = [
        'title' => t('%label: edit annotations', ['%label' => $type->label()]),
        'restrict access' => TRUE,
      ];
    }
    return $permissions;
  }

}
