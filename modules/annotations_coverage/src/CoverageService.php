<?php

declare(strict_types=1);

namespace Drupal\annotations_coverage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\Entity\AnnotationTargetInterface;
use Drupal\annotations\Entity\AnnotationTypeInterface;

/**
 * Computes annotation coverage across all opted-in annotation_target entities.
 *
 * Whether a given annotation type affects coverage status is controlled by the
 * annotations_coverage third-party setting on AnnotationType (affects_coverage).
 * The default is FALSE, so types only affect status when explicitly opted in.
 *
 * Status rollup per target:
 *   - complete: all status-affecting types filled at target and field level
 *   - partial:  target-level primary type filled, but gaps remain elsewhere
 *   - empty:    the primary status-affecting type is blank at target level
 *
 * This service is the public API for coverage data. Any module that needs to
 * act on coverage (reporting, enforcement, CI checks) should inject
 * annotations_coverage.coverage_service and call getCoverage() or getCoverageForTarget().
 */
class CoverageService {

  /**
   * Per-request cache for loadAnnotationTypes().
   *
   * Entity storage already caches loaded entities, but this avoids repeating
   * the uasort() on every internal call within a single page build.
   *
   * @var AnnotationTypeInterface[]|null
   */
  private ?array $annotationTypeCache = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AnnotationStorageService $annotationStorage,
  ) {}

  /**
   * Returns coverage data for all annotation_target entities, keyed by target ID.
   *
   * Each entry:
   *   - 'target': AnnotationTargetInterface
   *   - 'status': 'complete'|'partial'|'empty'
   *   - 'missing': array keyed by annotation type ID, each a string[] of
   *     locations: 'overview' (target level) or a field machine name.
   *
   * @return array<string, array{target: AnnotationTargetInterface, status: string, missing: array}>
   */
  public function getCoverage(): array {
    $types   = $this->loadAnnotationTypes();
    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    $results = [];
    foreach ($targets as $id => $target) {
      // Only published annotations count as filled coverage. When
      // annotations_workflows
      // is not installed the 'published' filter is a no-op.
      $annotations = $this->annotationStorage->getForTarget($id, TRUE);
      $results[$id] = $this->assessTarget($target, $types, $annotations);
    }
    return $results;
  }

  /**
   * Returns coverage data for a single target.
   *
   * @return array{target: AnnotationTargetInterface, status: string, missing: array}
   */
  public function getCoverageForTarget(AnnotationTargetInterface $target): array {
    $types       = $this->loadAnnotationTypes();
    $annotations = $this->annotationStorage->getForTarget($target->id(), TRUE);
    return $this->assessTarget($target, $types, $annotations);
  }

  /**
   * Returns aggregate score across all targets.
   *
   * Percentage is slot-based: (filled slots / total slots) × 100, where a
   * slot is one status-affecting annotation type × one annotatable location
   * (the target overview + each in-scope field).
   *
   * @param array<string, array> $coverage
   *   Output from getCoverage().
   *
   * @return array{complete: int, total: int, percent: int, filled_tracked: int, total_tracked: int, filled_optional: int, total_optional: int}
   */
  public function getScore(array $coverage): array {
    $total    = count($coverage);
    $complete = count(array_filter($coverage, fn($r) => $r['status'] === 'complete'));

    $all_types = $this->loadAnnotationTypes();

    $tracked_slots   = 0;
    $tracked_missing = 0;
    $optional_slots   = 0;
    $optional_missing = 0;

    foreach ($coverage as $entry) {
      $locations = 1 + count($entry['target']->getFields());
      foreach ($all_types as $type_id => $type) {
        $gap_count = count($entry['missing'][$type_id] ?? []);
        if ($this->affectsCoverage($type)) {
          $tracked_slots   += $locations;
          $tracked_missing += $gap_count;
        }
        else {
          $optional_slots   += $locations;
          $optional_missing += $gap_count;
        }
      }
    }

    $percent = $tracked_slots > 0
      ? (int) round(($tracked_slots - $tracked_missing) / $tracked_slots * 100)
      : 0;

    return [
      'complete'         => $complete,
      'total'            => $total,
      'percent'          => $percent,
      'filled_tracked'   => $tracked_slots - $tracked_missing,
      'total_tracked'    => $tracked_slots,
      'filled_optional'  => $optional_slots - $optional_missing,
      'total_optional'   => $optional_slots,
    ];
  }

  /**
   * Returns TRUE if the given annotation type affects coverage status.
   *
   * Reads the annotations_coverage third-party setting.
   */
  public function affectsCoverage(AnnotationTypeInterface $type): bool {
    return (bool) $type->getThirdPartySetting('annotations_coverage', 'affects_coverage', FALSE);
  }

  /**
   * Assesses a single target and returns its coverage entry.
   *
   * @param AnnotationTypeInterface[] $types
   * @param array<string, array<string, string>> $annotations
   */
  protected function assessTarget(AnnotationTargetInterface $target, array $types, array $annotations): array {
    $missing = [];
    foreach (array_keys($types) as $type_id) {
      $missing[$type_id] = [];
    }

    foreach ($types as $type_id => $type) {
      if (!array_key_exists($type_id, $annotations[''] ?? [])) {
        $missing[$type_id][] = 'overview';
      }
    }

    foreach (array_keys($target->getFields()) as $field_name) {
      foreach ($types as $type_id => $type) {
        if (!array_key_exists($type_id, $annotations[$field_name] ?? [])) {
          $missing[$type_id][] = $field_name;
        }
      }
    }

    $status_types    = array_filter($types, fn($t) => $this->affectsCoverage($t));
    $primary_type_id = array_key_first($status_types);

    $target_primary_blank = $primary_type_id !== NULL
      && in_array('overview', $missing[$primary_type_id] ?? [], TRUE);

    $has_status_gaps = (bool) array_filter(
      $missing,
      fn($locations, $type_id) => !empty($locations) && isset($status_types[$type_id]),
      ARRAY_FILTER_USE_BOTH
    );

    $status = match (TRUE) {
      $target_primary_blank => 'empty',
      $has_status_gaps      => 'partial',
      default               => 'complete',
    };

    return [
      'target'  => $target,
      'status'  => $status,
      'missing' => $missing,
    ];
  }

  /**
   * Loads all annotation types sorted by weight ascending.
   *
   * @return array<string, AnnotationTypeInterface>
   */
  protected function loadAnnotationTypes(): array {
    if ($this->annotationTypeCache === NULL) {
      /** @var AnnotationTypeInterface[] $types */
      $types = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
      uasort($types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
      $this->annotationTypeCache = $types;
    }
    return $this->annotationTypeCache;
  }

}
