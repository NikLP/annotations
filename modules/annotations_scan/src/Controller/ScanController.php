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

    if ($pending = $this->scanner->getPendingDiff()) {
      $diff = $pending['diff'];
      $items = [];

      foreach (array_keys($diff['added'] ?? []) as $target_id) {
        $items[] = $this->t('Added: @target', ['@target' => $target_id]);
      }

      foreach (array_keys($diff['removed'] ?? []) as $target_id) {
        $items[] = $this->t('Removed: @target', ['@target' => $target_id]);
      }

      foreach ($diff['changed'] ?? [] as $target_id => $changes) {
        $parts = [];
        if ($changes['fields_added']) {
          $parts[] = $this->t('@n field(s) added', ['@n' => count($changes['fields_added'])]);
        }

        if ($changes['fields_removed']) {
          $parts[] = $this->t('@n field(s) removed', ['@n' => count($changes['fields_removed'])]);
        }

        if ($changes['fields_changed']) {
          $parts[] = $this->t('@n field(s) changed', ['@n' => count($changes['fields_changed'])]);
        }
        
        $items[] = $this->t('Changed: @target (@changes)', [
          '@target' => $target_id,
          '@changes' => implode(', ', $parts),
        ]);
      }

      $build['pending_diff'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#cache' => ['max-age' => 0],
        'intro' => [
          '#markup' => $this->t('Site structure changes detected by cron on @time. Run a scan below to accept the current structure and dismiss this notice.', [
            '@time' => $this->dateFormatter->format($pending['detected'], 'short'),
          ]),
        ],
        'changes' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

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
        $rows[] = [
          $target_id,
          $data['label'] ?? '',
          $field_count ?: $this->t('(none)'),
        ];
      }

      $build['snapshot'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Target'),
          $this->t('Label'),
          $this->t('Fields'),
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
