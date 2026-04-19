<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\annotations_scan\ScanService;
use Psr\Container\ContainerInterface;

/**
 * Drush commands for the annotations_scan module.
 */
final class AnnotationsScanCommands extends DrushCommands {

  public function __construct(
    private readonly ScanService $scanner,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_scan.scanner'),
    );
  }

  /**
   * Run a full annotations scan against all opted-in targets and print a summary.
   */
  #[CLI\Command(name: 'annotations:scan', aliases: ['ann:scan'])]
  #[CLI\Option(name: 'fields', description: 'Show per-target field names instead of a field count')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Usage(name: 'drush annotations:scan', description: 'Run scan, print summary table')]
  #[CLI\Usage(name: 'drush annotations:scan --fields', description: 'Include field names in output')]
  #[CLI\Usage(name: 'drush ann:scan --format=json', description: 'Output full scan result as JSON')]
  public function scan(array $options = ['fields' => FALSE, 'format' => 'table']): void {
    $result = $this->scanner->scan();

    if (empty($result)) {
      $this->io()->warning('No targets discovered. Configure targets at /admin/config/annotations/targets.');
      return;
    }

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($result, JSON_PRETTY_PRINT));
      return;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(\Symfony\Component\Yaml\Yaml::dump($result, 4));
      return;
    }

    if ($options['fields']) {
      $headers = ['Target', 'Label', 'Fields'];
      $rows = [];
      foreach ($result as $target_id => $data) {
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          implode(', ', array_keys($data['fields'] ?? [])) ?: '(none)',
        ];
      }
    }
    else {
      $headers = ['Target', 'Label', 'Field count'];
      $rows = [];
      foreach ($result as $target_id => $data) {
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          count($data['fields'] ?? []),
        ];
      }
    }

    $this->io()->table($headers, $rows);
    $this->io()->success(sprintf('Scan complete: %d target(s) discovered.', count($result)));
  }

}
