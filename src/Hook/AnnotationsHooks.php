<?php

declare(strict_types=1);

namespace Drupal\annotations\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the annotations module.
 */
class AnnotationsHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_status_icon' => [
        'variables' => [
          'glyph'    => '',
          'label'    => '',
          'modifier' => '',
        ],
      ],
    ];
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * Registers the field configuration form handler on annotation_target so
   * that annotations_scan (and any other module) can use
   * _entity_form: 'annotation_target.fields' in routes without annotations
   * needing to know about the specific form class.
   *
   * The fields form itself lives in annotations because it is part of the core
   * scope management UI — it controls which fields are included for a target.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['annotation_target'])) {
      $entity_types['annotation_target']->setFormClass('fields', 'Drupal\annotations\Form\TargetFieldsForm');
    }
  }

  /**
   * Implements hook_views_data().
   *
   * EntityViewsData (declared on Annotation) auto-generates standard field
   * data including the entity_operations dropdown. We add only the derived
   * label fields that EntityViewsData cannot know about. Do NOT re-declare
   * standard columns here or they will appear twice in the UI.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data = [];

    // Custom derived fields — all on annotation_field_data, matching where
    // EntityViewsData exposes the underlying raw columns they map to.
    $data['annotation_field_data']['target_label'] = [
      'title'      => t('Target label'),
      'help'       => t('Label for the annotation_target.'),
      'real field' => 'target_id',
      'field'      => ['id' => 'annotation_target_label'],
      'filter'     => ['id' => 'annotation_target_filter'],
    ];

    $data['annotation_field_data']['field_label'] = [
      'title'      => t('Field label'),
      'help'       => t('Label for the annotated field.'),
      'real field' => 'field_name',
      'field'      => ['id' => 'annotation_field_label'],
      'filter'     => ['id' => 'annotation_field_filter'],
    ];

    $data['annotation_field_data']['type_label'] = [
      'title'      => t('Annotation type label'),
      'help'       => t('Label for the annotation type or site section.'),
      'real field' => 'type_id',
      'field'      => ['id' => 'annotation_type_label'],
      'filter'     => ['id' => 'annotation_type_filter'],
    ];

    return $data;
  }

}
