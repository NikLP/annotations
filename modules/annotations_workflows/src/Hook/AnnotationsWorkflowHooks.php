<?php

declare(strict_types=1);

namespace Drupal\annotations_workflows\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the annotations_workflows module.
 *
 * Keeps the annotations workflow in sync with the set of installed
 * annotation_type bundles automatically — types are attached on install
 * and on each subsequent type create/delete. No manual steps required.
 */
class AnnotationsWorkflowHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Attaches the workflow when a new annotation type is created.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'annotation_type') {
      return;
    }
    $workflow = $this->entityTypeManager
      ->getStorage('workflow')
      ->load('annotations');
    if (!$workflow) {
      return;
    }
    $plugin = $workflow->getTypePlugin();
    if (!$plugin->appliesToEntityTypeAndBundle('annotation', $entity->id())) {
      $plugin->addEntityTypeAndBundle('annotation', $entity->id());
      $workflow->save();
    }
  }

  /**
   * Detaches the workflow when an annotation type is deleted.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'annotation_type') {
      return;
    }
    $workflow = $this->entityTypeManager
      ->getStorage('workflow')
      ->load('annotations');
    if (!$workflow) {
      return;
    }
    $plugin = $workflow->getTypePlugin();
    if ($plugin->appliesToEntityTypeAndBundle('annotation', $entity->id())) {
      $plugin->removeEntityTypeAndBundle('annotation', $entity->id());
      $workflow->save();
    }
  }

}
