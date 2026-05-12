<?php

declare(strict_types=1);

namespace Drupal\annotations_explorer\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for annotations_explorer.
 */
class AnnotationsExplorerHooks {

  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_explorer' => [
        'variables' => [
          'nav' => [],
          'main' => [],
        ],
      ],
    ];
  }

}
