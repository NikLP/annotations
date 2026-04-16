<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the annotations_context module.
 */
class AnnotationsContextHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_context_checkbox' => [
        'variables' => [
          'id'      => '',
          'name'    => '',
          'value'   => '1',
          'checked' => FALSE,
          'label'   => '',
        ],
      ],
    ];
  }

}
