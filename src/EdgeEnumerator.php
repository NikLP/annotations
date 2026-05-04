<?php

declare(strict_types=1);

namespace Drupal\annotations;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\annotations\Entity\AnnotationTargetInterface;

/**
 * Enumerates traversable annotation edges from a source target.
 *
 * An edge exists when a field in the source target's scope is an entity
 * reference and the destination bundle has a registered annotation_target.
 * Edge IDs follow the convention: {source}__{field}__{dest}.
 *
 * This service is stateless — output is always derived from current config.
 * Used by annotations_ui (add page) and, eventually, annotations_scan.
 */
final class EdgeEnumerator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * Returns outbound annotation edges from a source target.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface $target
   *   The source annotation target.
   *
   * @return array<string, array{edge_id: string, field_name: string, field_label: string, dest_id: string, dest_label: string}>
   *   Edge descriptors keyed by edge ID.
   */
  public function getEdges(AnnotationTargetInterface $target): array {
    $et_id = $target->getTargetEntityTypeId();

    if (!$this->entityTypeManager->getDefinition($et_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }

    $defs = $this->fieldManager->getFieldDefinitions($et_id, $target->getBundle());

    $target_storage = $this->entityTypeManager->getStorage('annotation_target');
    $edges = [];

    foreach (array_keys($target->getFields()) as $field_name) {
      $def = $defs[$field_name] ?? NULL;
      if ($def === NULL) {
        continue;
      }

      if (!in_array($def->getType(), ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        continue;
      }

      $ref_type = $def->getFieldStorageDefinition()->getSetting('target_type');
      $bundles  = $def->getSetting('handler_settings')['target_bundles'] ?? [];

      foreach ((array) $bundles as $ref_bundle) {
        $dest_id = $ref_type . '__' . $ref_bundle;
        if ($dest_id === $target->id()) {
          continue;
        }

        $dest_target = $target_storage->load($dest_id);
        if ($dest_target === NULL) {
          continue;
        }

        $edge_id          = $target->id() . '__' . $field_name . '__' . $dest_id;
        $edges[$edge_id]  = [
          'edge_id'     => $edge_id,
          'field_name'  => $field_name,
          'field_label' => (string) $def->getLabel(),
          'dest_id'     => $dest_id,
          'dest_label'  => (string) $dest_target->label(),
        ];
      }
    }

    return $edges;
  }

}
