<?php

declare(strict_types=1);

namespace Drupal\annotations_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations_audit\ScanService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Annotations Audit scan admin UI.
 */
class ScanController extends ControllerBase {

  public function __construct(
    private readonly ScanService $scanner,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AnnotationDiscoveryService $discovery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_audit.scan_service'),
      $container->get('date.formatter'),
      $container->get('annotations.discovery'),
    );
  }

  /**
   * Renders the audit scan overview page.
   */
  public function overview(): array {
    $snapshot  = $this->scanner->loadSnapshot();
    $last_scan = $this->scanner->getLastScanTimestamp();

    $target_labels = [];
    foreach ($this->entityTypeManager()->getStorage('annotation_target')->loadMultiple() as $id => $target) {
      $target_labels[$id] = $target->label();
    }
    foreach ($snapshot as $target_id => $data) {
      $target_labels[$target_id] ??= $data['label'] ?? $target_id;
    }

    $build = [];

    if ($accumulated = $this->scanner->getAccumulatedChanges()) {
      $items = [];
      foreach ($accumulated as $change) {
        $time   = $this->dateFormatter->format($change['detected'], 'short');
        $target = $target_labels[$change['target_id']] ?? $change['target_id'];
        $items[] = match ($change['change_type']) {
          'added'         => $this->t('New target: @target (detected @time)', ['@target' => $target, '@time' => $time]),
          'removed'       => $this->t('Target removed: @target (detected @time)', ['@target' => $target, '@time' => $time]),
          'field_added'   => $this->t('@target: field @field added (detected @time)', ['@target' => $target, '@field' => $change['field'], '@time' => $time]),
          'field_removed' => $this->t('@target: field @field removed (detected @time)', ['@target' => $target, '@field' => $change['field'], '@time' => $time]),
          'field_changed' => $this->t('@target: field @field changed (detected @time)', ['@target' => $target, '@field' => $change['field'], '@time' => $time]),
          default         => $this->t('Change in @target (detected @time)', ['@target' => $target, '@time' => $time]),
        };
      }

      $build['pending_changes'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#cache' => ['max-age' => 0],
        'intro' => [
          '#markup' => $this->t('@count change(s) detected since the last waypoint. Create a new waypoint below to accept the current structure and clear this list.', [
            '@count' => count($accumulated),
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
        ? $this->t('Last waypoint: <strong>@time</strong>. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>.', [
          '@time' => $this->dateFormatter->format($last_scan, 'short'),
          ':url'  => Url::fromRoute('entity.annotation_target.collection')->toString(),
        ])
        : $this->t('No waypoint has been created yet. Configure targets at <a href=":url">Annotations &rsaquo; Targets</a>, then create a waypoint.', [
          ':url' => Url::fromRoute('entity.annotation_target.collection')->toString(),
        ]),
      '#cache' => ['max-age' => 0],
    ];

    $drift           = $this->scanner->getScopeDrift();
    $dismissed_count = count($this->scanner->getDismissedDrift());

    if (!empty($drift) || $dismissed_count > 0) {
      $build['scope_drift'] = $this->formBuilder()->getForm(
        'Drupal\annotations_audit\Form\DismissDriftForm',
        $drift,
        $dismissed_count,
        $target_labels,
      );
      $build['scope_drift']['#cache'] = ['max-age' => 0];
    }

    if (!empty($snapshot)) {
      $build['waypoint_header'] = [
        '#type' => 'container',
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Waypoint'),
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('The targets and fields recorded at the last waypoint. Cron compares the current site structure against this to detect changes.'),
        ],
        'check_form' => $this->formBuilder()->getForm(
          'Drupal\annotations_audit\Form\CheckChangesForm'
        ),
      ];

      $exclusive = (bool) $this->config('annotations.settings')->get('use_accordion_single');

      $plugins = $this->discovery->getPlugins();

      $by_entity_type = [];
      foreach ($snapshot as $target_id => $data) {
        $entity_type = $data['entity_type'] ?? explode('__', $target_id)[0];
        $by_entity_type[$entity_type][$target_id] = $data;
      }
      ksort($by_entity_type);

      foreach ($by_entity_type as $entity_type => $targets) {
        $section_title = isset($plugins[$entity_type])
          ? $plugins[$entity_type]->getLabel()
          : ($this->entityTypeManager()->getDefinition($entity_type, FALSE)?->getLabel() ?? $entity_type);

        $rows = [];
        foreach ($targets as $target_id => $data) {
          $field_count = count($data['fields'] ?? []);
          $rows[] = [
            $target_id,
            $data['label'] ?? '',
            $field_count ?: $this->t('(none)'),
          ];
        }

        $build['snapshot_' . $entity_type] = [
          '#type' => 'details',
          '#title' => $section_title,
          '#open' => FALSE,
          '#attributes' => $exclusive ? ['name' => 'annotations-audit-snapshot'] : [],
          '#cache' => ['max-age' => 0],
          'table' => [
            '#type' => 'table',
            '#header' => [
              $this->t('Target'),
              $this->t('Label'),
              $this->t('Fields'),
            ],
            '#rows' => $rows,
          ],
        ];
      }
    }

    $build['run_form'] = $this->formBuilder()->getForm(
      'Drupal\annotations_audit\Form\ScanRunForm'
    );

    return $build;
  }

}
