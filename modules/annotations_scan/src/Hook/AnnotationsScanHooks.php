<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\annotations_scan\ScanService;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for the annotations_scan module.
 */
class AnnotationsScanHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ScanService $scanner,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    return match ($route_name) {
      'help.page.annotations_scan' => '<p>' . $this->t(
        'The Annotations Scan module crawls your site structure and produces a structured snapshot of opted-in targets. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>.',
        [':url' => '/admin/config/annotations/targets']
      ) . '</p>',
      default => '',
    };
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $stored = $this->scanner->loadSnapshot();
    $result = $this->scanner->scan();
    $diff = $this->scanner->computeDiff($result, $stored);
    if ($this->scanner->diffHasChanges($diff)) {
      $this->scanner->storePendingDiff($diff);
      $this->logger->warning('Annotations Scan: site structure has changed since the last accepted scan. Visit the <a href=":url">scanner page</a> to review.', [
        ':url' => '/admin/config/annotations/scanner',
      ]);
    }
    $this->scanner->saveSnapshot($result);
  }

}
