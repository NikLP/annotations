<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Node (content types).
 */
class NodeTarget extends TargetBase {

  protected string $entityTypeId = 'node';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Content types');
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    $bundles = parent::getBundles();
    if (empty($bundles)) {
      return $bundles;
    }

    /**
     * Exclude node types owned by annotations submodules. These are internal
     * storage types — annotating them would be circular. The annotations_
     * prefix is the convention for all module-owned node types.
     */
    foreach (array_keys($bundles) as $bundle_id) {
      if (str_starts_with($bundle_id, 'annotations_')) {
        unset($bundles[$bundle_id]);
      }
    }

    return $bundles;
  }

}
