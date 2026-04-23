<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\annotations_scan\ScanService;

/**
 * Hook implementations for the annotations_scan module.
 */
class AnnotationsScanHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ScanService $scanner,
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
    $result = $this->scanner->scan();
    if (!empty($result)) {
      $this->scanner->saveSnapshot($result);
    }
  }

}
