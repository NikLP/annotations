<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Controller for the Annotations Scan admin UI.
 */
class ScanController extends ControllerBase {

  /**
   * Renders the scan overview page.
   */
  public function overview(): array {
    $target_count = $this->entityTypeManager()
      ->getStorage('annotation_target')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->count()
      ->execute();

    return [
      'summary' => [
        '#type' => 'container',
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t(
            'Active scan targets: <strong>@count</strong>. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>.',
            [
              '@count' => $target_count,
              ':url' => Url::fromRoute('entity.annotation_target.collection')->toString(),
            ]
          ),
        ],
      ],
      'run_form' => $this->formBuilder()->getForm(
        'Drupal\annotations_scan\Form\ScanRunForm'
      ),
    ];
  }

}
