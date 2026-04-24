<?php

declare(strict_types=1);

namespace Drupal\annotations_ui;

use Drupal\annotations\Entity\Annotation;

/**
 * Resolves human-readable label parts for an annotation title callback.
 *
 * Used by AnnotationViewController::title() and AnnotationEditForm::title().
 * Each consumer supplies its own translatable format string; this trait
 * provides only the shared label resolution.
 */
trait AnnotationTitleTrait {

  /**
   * Resolves the target, field, and type labels for an annotation entity.
   *
   * @return array{'@target': string, '@field': string, '@type': string}
   *   Substitution tokens for the annotation title format string.
   */
  protected static function resolveAnnotationTitleParts(Annotation $annotation): array {
    $target_id  = (string) $annotation->get('target_id')->value;
    $field_name = (string) $annotation->get('field_name')->value;
    $type_id    = (string) $annotation->get('type_id')->value;

    $etm = \Drupal::entityTypeManager();

    $target       = $etm->getStorage('annotation_target')->load($target_id);
    $type         = $etm->getStorage('annotation_type')->load($type_id);
    $target_label = $target ? (string) $target->label() : $target_id;
    $type_label   = $type ? (string) $type->label() : $type_id;

    $field_label = $field_name === '' ? (string) t('Overview') : $field_name;
    if ($field_name !== '' && $target !== NULL) {
      $defs = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions($target->getTargetEntityTypeId(), $target->getBundle());
      if (isset($defs[$field_name])) {
        $field_label = (string) $defs[$field_name]->getLabel();
      }
    }

    return [
      '@target' => $target_label,
      '@field'  => $field_label,
      '@type'   => $type_label,
    ];
  }

}
