<?php

declare(strict_types=1);

namespace Drupal\annotations;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\annotations\Plugin\Target\GenericTarget;

/**
 * Collects and exposes all registered Target plugins.
 *
 * Used by the scope management UI (in annotations) and the scan executor (in
 * annotations_scan) to get the full list of available target plugins. Any
 * module can contribute a plugin by tagging a service with annotations.target.
 * GenericTarget is auto-instantiated for any fieldable entity type
 * not claimed by a specific plugin.
 */
class AnnotationDiscoveryService {

  /**
   * @param iterable<\Drupal\annotations\Plugin\Target\TargetInterface> $scanTargets
   *   All registered Target plugins, injected via service tag annotations.target.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected iterable $scanTargets,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * Returns all available scan target plugins, keyed by entity type ID.
   *
   * Includes explicitly registered plugins plus auto-discovered generic
   * plugins for any fieldable entity type not already claimed.
   *
   * @return array<string, TargetInterface>
   */
  public function getPlugins(): array {
    $plugins = [];
    foreach ($this->scanTargets as $plugin) {
      $plugins[$plugin->getEntityTypeId()] = $plugin;
    }

    // Auto-discover any fieldable entity types not covered by a specific plugin.
    // This ensures custom entity types from ECK, hand-rolled modules, etc.
    // appear in the scope UI automatically without requiring a dedicated plugin.
    /** @var array<string, \Drupal\Core\Entity\EntityTypeInterface> $definitions */
    $definitions = $this->entityTypeManager->getDefinitions();

    foreach ($definitions as $type_id => $definition) {
      if (isset($plugins[$type_id])) {
        continue;
      }

      // Only auto-discover fieldable entity types. GenericTarget is
      // field-oriented and produces a broken UX for non-fieldable entities.
      // Non-fieldable entity types that Annotations supports (roles, views,
      // menus, workflows) have dedicated plugins and are already collected in
      // the loop above — they never reach this fallback.
      if (!$definition->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }

      // Omit entity types provided by annotations itself.
      if (str_starts_with($definition->getProvider(), 'annotations')) {
        continue;
      }

      $plugins[$type_id] = new GenericTarget(
        $this->entityTypeManager,
        $this->bundleInfo,
        $this->fieldManager,
        $definition,
      );
    }

    return $plugins;
  }

}
