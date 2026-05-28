<?php

declare(strict_types=1);

namespace Drupal\annotations_audit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\annotations\AnnotationDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Executes site structure scans and manages scan snapshots.
 *
 * Uses AnnotationDiscoveryService (from annotations) to get available plugins,
 * loads opted-in annotation_target entities as scope, then calls each plugin's
 * discover() to produce a structured snapshot of the site's content model.
 *
 * Usage:
 * @code
 * $scanner = \Drupal::service('annotations_audit.scan_service');
 * $result = $scanner->scan();
 * $scanner->saveSnapshot($result);
 * $diff = $scanner->computeDiff($result, $scanner->loadSnapshot());
 * @endcode
 */
class ScanService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected AnnotationDiscoveryService $discovery,
    protected Connection $database,
    protected StateInterface $state,
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
      $this->logger->info('Annotations Audit: no targets configured, nothing to scan.');
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
        $this->logger->error('Annotations Audit: plugin @plugin failed: @message', [
          '@plugin' => get_class($plugin),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('Annotations Audit: scan complete. @count targets discovered.', [
      '@count' => count($result),
    ]);

    return $result;
  }

  /**
   * Saves a scan result as the current snapshot, replacing any prior state.
   *
   * @param array<string, array<string, mixed>> $result
   *   The scan result returned by scan().
   */
  public function saveSnapshot(array $result): void {
    $now = time();

    foreach ($result as $target_id => $data) {
      $this->database->merge('annotations_audit')
        ->key('target_id', $target_id)
        ->fields([
          'data' => \json_encode($data),
          'saved' => $now,
        ])
        ->execute();
    }

    if (!empty($result)) {
      $this->database->delete('annotations_audit')
        ->condition('target_id', array_keys($result), 'NOT IN')
        ->execute();
    }
    else {
      $this->database->truncate('annotations_audit')->execute();
    }

    $this->logger->info('Annotations Audit: snapshot saved for @count target(s).', [
      '@count' => count($result),
    ]);
  }

  /**
   * Loads the stored snapshot.
   *
   * @return array<string, array<string, mixed>>
   *   Last saved scan result keyed by "{entity_type}__{bundle}", or empty array.
   */
  public function loadSnapshot(): array {
    $rows = $this->database->select('annotations_audit', 's')
      ->fields('s', ['target_id', 'data'])
      ->execute()
      ->fetchAllKeyed();

    $snapshot = [];
    foreach ($rows as $target_id => $json) {
      $decoded = \json_decode($json, TRUE);
      if (\is_array($decoded)) {
        $snapshot[$target_id] = $decoded;
      }
    }

    return $snapshot;
  }

  /**
   * Computes a structural diff between a current scan and a stored snapshot.
   *
   * @param array<string, array<string, mixed>> $current
   *   Fresh scan result.
   * @param array<string, array<string, mixed>> $stored
   *   Previously saved snapshot (from loadSnapshot()).
   *
   * @return array{
   *   added: array<string, array<string, mixed>>,
   *   removed: array<string, array<string, mixed>>,
   *   changed: array<string, array{fields_added: list<string>, fields_removed: list<string>, fields_changed: list<string>}>
   *   }
   */
  public function computeDiff(array $current, array $stored): array {
    $added   = array_diff_key($current, $stored);
    $removed = array_diff_key($stored, $current);
    $changed = [];

    foreach (array_intersect_key($current, $stored) as $target_id => $current_data) {
      $current_fields = $current_data['fields'] ?? [];
      $stored_fields  = $stored[$target_id]['fields'] ?? [];

      $fields_added   = array_values(array_diff(array_keys($current_fields), array_keys($stored_fields)));
      $fields_removed = array_values(array_diff(array_keys($stored_fields), array_keys($current_fields)));

      $fields_changed = [];
      foreach (array_intersect_key($current_fields, $stored_fields) as $field_name => $current_field) {
        if ($current_field !== $stored_fields[$field_name]) {
          $fields_changed[] = $field_name;
        }
      }

      if ($fields_added || $fields_removed || $fields_changed) {
        $changed[$target_id] = [
          'fields_added'   => $fields_added,
          'fields_removed' => $fields_removed,
          'fields_changed' => $fields_changed,
        ];
      }
    }

    return [
      'added'   => $added,
      'removed' => $removed,
      'changed' => $changed,
    ];
  }

  /**
   * Returns the Unix timestamp of the last saved snapshot, or NULL if none.
   */
  public function getLastScanTimestamp(): ?int {
    $saved = $this->database->select('annotations_audit', 's')
      ->fields('s', ['saved'])
      ->orderBy('saved', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $saved !== FALSE ? (int) $saved : NULL;
  }

  /**
   * Returns TRUE if a diff result contains any structural changes.
   */
  public function diffHasChanges(array $diff): bool {
    return !empty($diff['added']) || !empty($diff['removed']) || !empty($diff['changed']);
  }

  /**
   * Stores a pending diff in state for display on the audit scan page.
   */
  public function storePendingDiff(array $diff): void {
    $this->state->set('annotations_audit.pending_diff', [
      'diff'     => $diff,
      'detected' => time(),
    ]);
  }

  /**
   * Returns the stored pending diff, or NULL if none exists.
   *
   * @return array{diff: array, detected: int}|null
   */
  public function getPendingDiff(): ?array {
    return $this->state->get('annotations_audit.pending_diff');
  }

  /**
   * Clears the stored pending diff.
   */
  public function clearPendingDiff(): void {
    $this->state->delete('annotations_audit.pending_diff');
  }

  /**
   * Loads annotation_target entities, keyed by "{entity_type}__{bundle}".
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   *   All opted-in targets, keyed by "{entity_type}__{bundle}".
   */
  protected function loadScopes(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface[] $scopes */
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
