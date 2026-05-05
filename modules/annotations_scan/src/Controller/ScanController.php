<?php

declare(strict_types=1);

namespace Drupal\annotations_scan\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\annotations_scan\ScanService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Annotations Scan admin UI.
 */
class ScanController extends ControllerBase {

  public function __construct(
    private readonly ScanService $scanner,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_scan.scanner'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the scan overview page.
   */
  public function overview(): array {
    $snapshot = $this->scanner->loadSnapshot();
    $last_scan = $this->scanner->getLastScanTimestamp();

    $build = [];

    $build['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $last_scan
        ? $this->t('Last scan: <strong>@time</strong>. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>.', [
          '@time' => $this->dateFormatter->format($last_scan, 'short'),
          ':url' => Url::fromRoute('entity.annotation_target.collection')->toString(),
        ])
        : $this->t('No scan has been run yet. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>, then run a scan.', [
          ':url' => Url::fromRoute('entity.annotation_target.collection')->toString(),
        ]),
      '#cache' => ['max-age' => 0],
    ];

    if (!empty($snapshot)) {
      $rows = [];
      foreach ($snapshot as $target_id => $data) {
        $field_count = count($data['fields'] ?? []);
        $edge_count  = count($data['edges'] ?? []);
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          $field_count ?: $this->t('(none)'),
          $edge_count  ?: $this->t('(none)'),
        ];
      }

      $build['snapshot'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Target'),
          $this->t('Label'),
          $this->t('Fields'),
          $this->t('Edges'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No targets in snapshot.'),
        '#cache' => ['max-age' => 0],
      ];
    }

    $build['run_form'] = $this->formBuilder()->getForm(
      'Drupal\annotations_scan\Form\ScanRunForm'
    );

    return $build;
  }

}
