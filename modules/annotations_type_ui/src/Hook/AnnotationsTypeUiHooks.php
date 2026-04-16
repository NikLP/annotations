<?php

declare(strict_types=1);

namespace Drupal\annotations_type_ui\Hook;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\annotations_type_ui\Form\AnnotationTypeDeleteForm;
use Drupal\annotations_type_ui\Form\AnnotationTypeForm;
use Drupal\annotations_type_ui\ListBuilder\AnnotationTypeListBuilder;

/**
 * Hook implementations for the annotations_type_ui module.
 */
class AnnotationsTypeUiHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_insert().
   *
   * Creates a default form display for new annotation_type bundles so that
   * AnnotationEditForm renders the value and revision_log_message fields.
   * Without this, ContentEntityForm renders a blank form for types created
   * through this UI that do not ship an explicit form display config.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'annotation_type') {
      return;
    }

    $type_id = $entity->id();
    $storage = $this->entityTypeManager;

    if ($storage->getStorage('entity_form_display')->load('annotation.' . $type_id . '.default') === NULL) {
      EntityFormDisplay::create([
        'targetEntityType' => 'annotation',
        'bundle'           => $type_id,
        'mode'             => 'default',
        'status'           => TRUE,
      ])
        ->setComponent('value', [
          'type'     => 'string_textarea',
          'weight'   => 0,
          'region'   => 'content',
          'settings' => ['rows' => 8, 'placeholder' => ''],
        ])
        ->setComponent('revision_log_message', [
          'type'     => 'string_textarea',
          'weight'   => 10,
          'region'   => 'content',
          'settings' => ['rows' => 2, 'placeholder' => ''],
        ])
        ->save();
    }

    if ($storage->getStorage('entity_view_display')->load('annotation.' . $type_id . '.default') === NULL) {
      EntityViewDisplay::create([
        'targetEntityType' => 'annotation',
        'bundle'           => $type_id,
        'mode'             => 'default',
        'status'           => TRUE,
      ])
        ->setComponent('value', [
          'label'    => 'hidden',
          'type'     => 'basic_string',
          'weight'   => 0,
          'region'   => 'content',
          'settings' => [],
        ])
        ->save();
    }
  }

  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['annotation_type'])) {
      $entity_types['annotation_type']
        ->setListBuilderClass(AnnotationTypeListBuilder::class)
        ->setFormClass('default', AnnotationTypeForm::class)
        ->setFormClass('delete', AnnotationTypeDeleteForm::class)
        ->setLinkTemplate('collection', '/admin/structure/annotation-types')
        ->setLinkTemplate('add-form', '/admin/structure/annotation-types/add')
        ->setLinkTemplate('edit-form', '/admin/structure/annotation-types/{annotation_type}')
        ->setLinkTemplate('delete-form', '/admin/structure/annotation-types/{annotation_type}/delete');
    }

    // field_ui_base_route enables "Manage fields / form display / display"
    // routes per bundle, which AnnotationTypeListBuilder surfaces as operations.
    // Side-effect: Admin Toolbar Extra Tools adds these routes to the admin menu.
    if (isset($entity_types['annotation'])) {
      $entity_types['annotation']->set(
        'field_ui_base_route',
        'entity.annotation_type.edit_form',
      );
    }
  }

}
