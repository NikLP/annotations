<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Catch-all Target plugin for custom or unclaimed fieldable entity types.
 *
 * Created dynamically by AnnotationDiscoveryService for any fieldable entity
 * type that * does not have a dedicated plugin. Agencies using custom entity
 * types (e.g. * via ECK or hand-rolled entities) will see them in the scope
 * UI without needing to write a plugin.
 *
 * The label and entity type ID are derived from the entity type definition
 * rather than a hardcoded property, since this class is instantiated at
 * runtime for arbitrary entity types.
 */
class GenericTarget extends TargetBase {

  /**
   * Human-readable label for the entity type, from its definition.
   */
  private string $entityTypeLabel;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $bundleInfo,
    EntityFieldManagerInterface $fieldManager,
    EntityTypeInterface $definition,
  ) {
    parent::__construct($entityTypeManager, $bundleInfo, $fieldManager);
    $this->entityTypeId = $definition->id();
    $this->entityTypeLabel = (string) $definition->getLabel();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return $this->entityTypeLabel;
  }

}
