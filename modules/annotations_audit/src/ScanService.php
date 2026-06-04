<?php

declare(strict_types=1);

namespace Drupal\annotations_audit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Plugin\Target\TargetBase;
use Drupal\field\FieldConfigInterface;
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
    protected EntityFieldManagerInterface $fieldManager,
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
    $failures = 0;

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
      catch (\Exception $e) {
        $failures++;
        $this->logger->error('Annotations Audit: plugin @plugin failed: @message', [
          '@plugin' => get_class($plugin),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('Annotations Audit: scan complete. @count targets discovered.', [
      '@count' => count($result),
    ]);

    if ($failures > 0) {
      $this->logger->warning('Annotations Audit: @failures plugin(s) failed during scan; check previous log entries for details.', [
        '@failures' => $failures,
      ]);
    }

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
   *   Last saved scan result keyed by "{entity_type}__{bundle}", or an empty
   *   array.
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
   *   changed: array<string, array{
   *     fields_added: list<string>,
   *     fields_removed: list<string>,
   *     fields_changed: list<string>
   *   }>
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
   * Returns all accumulated structural changes since the last manual scan.
   *
   * Keyed by a stable change key, e.g. "added:node__article" or
   * "changed:node__page:field_added:field_foo".
   *
   * @return array<string, array{target_id: string, change_type: string, field?: string, detected: int}>
   *   Accumulated changes keyed by stable change key.
   */
  public function getAccumulatedChanges(): array {
    return $this->state->get('annotations_audit.accumulated_changes', []);
  }

  /**
   * Clears all accumulated changes (called when a new waypoint is set).
   */
  public function clearAccumulatedChanges(): void {
    $this->state->delete('annotations_audit.accumulated_changes');
  }

  /**
   * Returns all dismissed scope drift items as "target_id:field_name" strings.
   *
   * @return list<string>
   *   Dismissed drift keys.
   */
  public function getDismissedDrift(): array {
    return $this->state->get('annotations_audit.dismissed_drift', []);
  }

  /**
   * Suppresses a specific field from the scope drift notice.
   */
  public function dismissDriftField(string $target_id, string $field_name): void {
    $dismissed = $this->getDismissedDrift();
    $key = "{$target_id}:{$field_name}";
    if (!in_array($key, $dismissed, TRUE)) {
      $dismissed[] = $key;
      $this->state->set('annotations_audit.dismissed_drift', $dismissed);
    }
  }

  /**
   * Clears all scope drift dismissals.
   */
  public function clearDismissedDrift(): void {
    $this->state->delete('annotations_audit.dismissed_drift');
  }

  /**
   * Merges a diff into the accumulated changes list.
   *
   * Adds entries for changes not previously seen; removes entries for changes
   * that have reverted to the waypoint baseline. Returns only the newly added
   * entries so callers can log or notify once per change.
   *
   * @param array $diff
   *   A diff result from computeDiff().
   *
   * @return array<string, array{target_id: string, change_type: string, field?: string, detected: int}>
   *   Only the newly detected changes, keyed by change key.
   */
  public function mergeNewChanges(array $diff): array {
    $current  = $this->flattenDiff($diff);
    $stored   = $this->getAccumulatedChanges();
    $new      = array_diff_key($current, $stored);
    $reverted = array_diff_key($stored, $current);

    $now = time();
    $new_with_time = [];

    foreach ($new as $key => $info) {
      $entry = $info + ['detected' => $now];
      $stored[$key] = $entry;
      $new_with_time[$key] = $entry;
    }

    foreach (array_keys($reverted) as $key) {
      unset($stored[$key]);
    }

    $this->state->set('annotations_audit.accumulated_changes', $stored);

    return $new_with_time;
  }

  /**
   * Flattens a diff result into individual change items keyed by a stable key.
   *
   * @param array $diff
   *   A diff result from computeDiff().
   *
   * @return array<string, array{target_id: string, change_type: string, field?: string}>
   *   Flattened change items keyed by stable change key.
   */
  private function flattenDiff(array $diff): array {
    $items = [];

    foreach (array_keys($diff['added'] ?? []) as $target_id) {
      $items["added:{$target_id}"] = ['target_id' => $target_id, 'change_type' => 'added'];
    }

    foreach (array_keys($diff['removed'] ?? []) as $target_id) {
      $items["removed:{$target_id}"] = ['target_id' => $target_id, 'change_type' => 'removed'];
    }

    foreach ($diff['changed'] ?? [] as $target_id => $changes) {
      $target_id = (string) $target_id;
      foreach ($changes['fields_added'] ?? [] as $field) {
        $items["changed:{$target_id}:field_added:{$field}"] = [
          'target_id' => $target_id,
          'change_type' => 'field_added',
          'field' => $field,
        ];
      }
      foreach ($changes['fields_removed'] ?? [] as $field) {
        $items["changed:{$target_id}:field_removed:{$field}"] = [
          'target_id' => $target_id,
          'change_type' => 'field_removed',
          'field' => $field,
        ];
      }
      foreach ($changes['fields_changed'] ?? [] as $field) {
        $items["changed:{$target_id}:field_changed:{$field}"] = [
          'target_id' => $target_id,
          'change_type' => 'field_changed',
          'field' => $field,
        ];
      }
    }

    return $items;
  }

  /**
   * Returns fields that exist in Drupal but are not in any target's scope.
   *
   * Reports FieldConfig instances (explicitly added fields) and notable base
   * fields (title, body, name, description) that the site builder may want to
   * track. Intentionally excluded fields remain in the result — use the Targets
   * admin to add them to scope or accept the gap.
   *
   * @return array<string, list<string>>
   *   Keyed by target_id, value is the list of out-of-scope field names.
   */
  public function getScopeDrift(): array {
    $scopes    = $this->loadScopes();
    $dismissed = $this->getDismissedDrift();
    $drift     = [];

    foreach ($scopes as $target_id => $target) {
      $entity_type = $target->getTargetEntityTypeId();
      $bundle      = $target->getBundle();

      foreach ($this->fieldManager->getFieldDefinitions($entity_type, $bundle) as $field_name => $definition) {
        $trackable = ($definition instanceof FieldConfigInterface)
          || in_array($field_name, TargetBase::NOTABLE_BASE_FIELDS, TRUE);

        if ($trackable && !$target->isFieldIncluded($field_name)
            && !in_array("{$target_id}:{$field_name}", $dismissed, TRUE)) {
          $drift[$target_id][] = $field_name;
        }
      }
    }

    return array_filter($drift);
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
