<?php

declare(strict_types=1);

namespace Drupal\annotations_scan;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\annotations\DiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Executes site structure scans.
 *
 * Uses DiscoveryService (from annotations) to get available plugins, loads opted-in
 * annotation_target entities as scope, then calls each plugin's discover() to produce
 * a structured snapshot of the site's content model.
 *
 * Usage:
 * @code
 * $result = \Drupal::service('annotations_scan.scanner')->scan();
 * @endcode
 */
class ScanService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected DiscoveryService $discovery,
  ) {}

  /**
   * Runs a full scan against all opted-in targets.
   *
   * @return array<string, array<string, mixed>>
   *   Structured scan result keyed by "{entity_type}__{bundle}".
   */
  public function scan(): array {
    $scopes = $this->loadScopes();

    if (empty($scopes)) {
      $this->logger->info('Annotations Scan: no targets configured, nothing to scan.');
      return [];
    }

    $result = [];

    foreach ($this->discovery->getPlugins() as $plugin) {
      if (!$plugin->isAvailable()) {
        continue;
      }

      try {
        $discovered = $plugin->discover($scopes);
        foreach ($discovered as $bundle_id => $bundle_data) {
          $key = $bundle_data['entity_type'] . '__' . $bundle_id;
          $result[$key] = $bundle_data;
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Annotations Scan: plugin @plugin failed: @message', [
          '@plugin' => get_class($plugin),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('Annotations Scan: scan complete. @count targets discovered.', [
      '@count' => count($result),
    ]);

    return $result;
  }

  /**
   * Loads all annotation_target config entities, keyed by "{entity_type}__{bundle}".
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   */
  protected function loadScopes(): array {
    $scopes = $this->entityTypeManager
      ->getStorage('annotation_target')
      ->loadMultiple();

    $indexed = [];
    foreach ($scopes as $scope) {
      if (!$scope->status()) {
        continue;
      }
      $key = $scope->getTargetEntityTypeId() . '__' . $scope->getBundle();
      $indexed[$key] = $scope;
    }

    return $indexed;
  }

}
