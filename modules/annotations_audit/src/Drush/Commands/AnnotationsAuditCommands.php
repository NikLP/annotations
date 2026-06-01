<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Drush\Commands;

use Symfony\Component\Yaml\Yaml;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\annotations_audit\ScanService;
use Psr\Container\ContainerInterface;

/**
 * Drush commands for the annotations_audit module.
 */
final class AnnotationsAuditCommands extends DrushCommands {

  public function __construct(
    private readonly ScanService $scanner,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_audit.scan_service'),
    );
  }

  /**
   * Run an annotations scan against all opted-in targets and print a summary.
   */
  #[CLI\Command(name: 'annotations:scan', aliases: ['ann:scan'])]
  #[CLI\Option(name: 'fields', description: 'Show per-target field names instead of a field count')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Option(name: 'diff', description: 'Show structural delta against the last stored snapshot and save the new snapshot')]
  #[CLI\Option(name: 'check', description: 'Like --diff but exits non-zero if annotation-relevant changes are detected (does not save snapshot)')]
  #[CLI\Usage(name: 'drush annotations:scan', description: 'Run scan, print summary table, save snapshot')]
  #[CLI\Usage(name: 'drush annotations:scan --fields', description: 'Include field names in output')]
  #[CLI\Usage(name: 'drush ann:scan --format=json', description: 'Output full scan result as JSON')]
  #[CLI\Usage(name: 'drush ann:scan --diff', description: 'Show delta against last snapshot')]
  #[CLI\Usage(name: 'drush ann:scan --check', description: 'Exit 1 if structural changes detected (pre-commit use)')]
  public function scan(array $options = ['fields' => FALSE, 'format' => 'table', 'diff' => FALSE, 'check' => FALSE]): int {
    $strict    = (bool) $options['check'];
    $show_diff = $strict || (bool) $options['diff'];

    $result = $this->scanner->scan();

    if (empty($result)) {
      $this->io()->warning('No targets discovered. Configure targets at /admin/config/annotations/targets.');
      return self::EXIT_SUCCESS;
    }

    if ($show_diff) {
      return $this->handleDiff($result, $options, $strict);
    }

    $this->scanner->saveSnapshot($result);
    $this->scanner->clearAccumulatedChanges();

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($result, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($result, 4));
      return self::EXIT_SUCCESS;
    }

    $this->printSummaryTable($result, (bool) $options['fields']);
    $this->io()->success(sprintf('Scan complete: %d target(s) discovered. Snapshot saved.', count($result)));

    return self::EXIT_SUCCESS;
  }

  /**
   * Runs diff logic for --diff and --strict modes.
   */
  private function handleDiff(array $result, array $options, bool $strict): int {
    $stored = $this->scanner->loadSnapshot();

    if (empty($stored)) {
      $this->io()->warning('No snapshot stored yet. Run `ann:scan` first to establish a baseline.');
      if (!$strict) {
        $this->scanner->saveSnapshot($result);
        $this->scanner->clearAccumulatedChanges();
        $this->io()->note('Snapshot saved from this run.');
      }
      return self::EXIT_SUCCESS;
    }

    $diff        = $this->scanner->computeDiff($result, $stored);
    $has_changes = $this->scanner->diffHasChanges($diff);

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($diff, JSON_PRETTY_PRINT));
    }
    elseif ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($diff, 4));
    }
    else {
      $this->printDiffTable($diff);
    }

    if (!$has_changes) {
      $this->io()->success('No structural changes detected.');
      if (!$strict) {
        $this->scanner->saveSnapshot($result);
        $this->scanner->clearAccumulatedChanges();
      }
      return self::EXIT_SUCCESS;
    }

    if ($strict) {
      $this->io()->error('Annotation-relevant structural changes detected. Commit blocked.');
      return self::EXIT_FAILURE;
    }

    $this->io()->note('Structural changes detected. Snapshot updated.');
    $this->scanner->saveSnapshot($result);
    $this->scanner->clearAccumulatedChanges();

    return self::EXIT_SUCCESS;
  }

  /**
   * Prints a human-readable diff table to the console.
   */
  private function printDiffTable(array $diff): void {
    $rows = [];

    foreach (array_keys($diff['added']) as $target_id) {
      $rows[] = ['+', $target_id, '(new target)', ''];
    }

    foreach (array_keys($diff['removed']) as $target_id) {
      $rows[] = ['-', $target_id, '(removed target)', ''];
    }

    foreach ($diff['changed'] as $target_id => $changes) {
      foreach ($changes['fields_added'] as $field) {
        $rows[] = ['~', $target_id, $field, 'field added'];
      }
      foreach ($changes['fields_removed'] as $field) {
        $rows[] = ['~', $target_id, $field, 'field removed'];
      }
      foreach ($changes['fields_changed'] as $field) {
        $rows[] = ['~', $target_id, $field, 'field changed'];
      }
    }

    if (empty($rows)) {
      return;
    }

    $this->io()->table(['', 'Target', 'Field', 'Change'], $rows);
  }

  /**
   * Prints the standard scan summary table.
   */
  private function printSummaryTable(array $result, bool $show_fields): void {
    $headers = ['Target', 'Label', 'Fields'];
    $rows    = [];
    if ($show_fields) {
      foreach ($result as $target_id => $data) {
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          implode(', ', array_keys($data['fields'] ?? [])) ?: '(none)',
        ];
      }
    }
    else {
      foreach ($result as $target_id => $data) {
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          count($data['fields'] ?? []),
        ];
      }
    }

    $this->io()->table($headers, $rows);
  }

}
