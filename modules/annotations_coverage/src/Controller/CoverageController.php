<?php

declare(strict_types=1);

namespace Drupal\annotations_coverage\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationsGlyph;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Entity\AnnotationTargetInterface;
use Drupal\annotations_coverage\CoverageService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the annotation coverage report page.
 */
class CoverageController extends ControllerBase {

  public function __construct(
    protected CoverageService $coverageService,
    protected EntityFieldManagerInterface $fieldManager,
    protected AnnotationDiscoveryService $discoveryService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_coverage.coverage_service'),
      $container->get('entity_field.manager'),
      $container->get('annotations.discovery'),
    );
  }

  /**
   * Builds the coverage report page render array.
   */
  public function page(Request $request): array {
    $coverage = $this->coverageService->getCoverage();
    $score = $this->coverageService->getScore($coverage);

    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all_types */
    $all_types = $this->entityTypeManager()
      ->getStorage('annotation_type')
      ->loadMultiple();
    uasort($all_types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());

    $plugins = $this->discoveryService->getPlugins();
    $entity_types = [];
    foreach ($coverage as $row) {
      $type_id = $row['target']->getTargetEntityTypeId();
      if (!isset($entity_types[$type_id])) {
        $entity_types[$type_id] = isset($plugins[$type_id])
          ? (string) $plugins[$type_id]->getLabel()
          : $type_id;
      }
    }
    asort($entity_types);

    $filter_type   = (string) $request->query->get('entity_type', '');
    $filter_status = (string) $request->query->get('status', '');

    $filtered = $coverage;
    if ($filter_type !== '') {
      $filtered = array_filter($filtered, fn($r) => $r['target']->getTargetEntityTypeId() === $filter_type);
    }
    if ($filter_status !== '') {
      $filtered = array_filter($filtered, fn($r) => $r['status'] === $filter_status);
    }

    $score_tier = match (TRUE) {
      $score['percent'] === 100 => 'good',
      $score['percent'] >= 50  => 'warning',
      default                  => 'poor',
    };

    $score_build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-report__score', 'annotations-report__score--' . $score_tier]],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@percent% annotation coverage', ['@percent' => $score['percent']]),
        '#attributes' => ['class' => ['annotations-report__score-heading']],
      ],
      'tracked' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@filled/@total tracked types complete', [
          '@filled' => $score['filled_tracked'],
          '@total'  => $score['total_tracked'],
        ]),
        '#attributes' => ['class' => ['annotations-report__score-detail', 'annotations-report__score-detail--tracked']],
      ],
      'optional' => $score['total_optional'] > 0 ? [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('@filled/@total optional types complete', [
          '@filled' => $score['filled_optional'],
          '@total'  => $score['total_optional'],
        ]),
        '#attributes' => ['class' => ['annotations-report__score-detail']],
      ] : [],
    ];

    $filter_form = $this->formBuilder()->getForm(
      'Drupal\annotations_coverage\Form\CoverageFilterForm',
      $entity_types,
      $filter_type,
      $filter_status,
    );

    $can_annotate = $this->moduleHandler()->moduleExists('annotations_ui')
      && $this->currentUser()->hasPermission('edit any annotation');

    $na_label = $this->t('N/A');

    $rows = [];
    foreach ($filtered as $id => $row) {
      $target = $row['target'];
      $status = $row['status'];

      $type_label = $entity_types[$target->getTargetEntityTypeId()]
        ?? $target->getTargetEntityTypeId();

      $status_cell = ['data' => $this->buildStatusCell($status), 'class' => ['annotations-center']];

      $gaps_cell = $status !== 'complete'
        ? ['data' => $this->buildGapCell($target, $row, $all_types)]
        : ['data' => ['#markup' => $na_label]];

      $ops = [];
      if ($can_annotate) {
        $ops['annotate'] = [
          'title' => $this->t('Annotate'),
          'url'   => Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $id], [
            'query' => ['destination' => Url::fromRoute('annotations_coverage.report')->toString()],
          ]),
        ];
      }

      $table_row   = [$target->label(), $type_label, $status_cell, $gaps_cell];
      $table_row[] = !empty($ops)
        ? ['data' => ['#type' => 'operations', '#links' => $ops]]
        : ['#markup' => $na_label];

      $rows[] = $table_row;
    }

    return [
      '#attached' => ['library' => ['annotations/annotations.admin']],
      '#cache' => [
        'tags'     => ['annotation_list', 'annotation_target_list', 'annotation_type_list'],
        'contexts' => array_merge(
          ['languages:language_interface', 'url.query_args', 'user.permissions'],
          $this->languageManager()->isMultilingual() ? ['languages:content'] : [],
        ),
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['annotations-report__header']],
        'filters' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['annotations-report__filters']],
          'form' => $filter_form,
        ],
        'score' => $score_build,
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Target'),
          $this->t('Type'),
          ['data' => $this->t('Status'), 'class' => ['annotations-center']],
          $this->t('Gaps'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No targets match the current filters.'),
      ],
    ];
  }

  /**
   * Builds a status cell render array with an accessible icon.
   */
  private function buildStatusCell(string $status): array {
    $map = [
      'complete' => [AnnotationsGlyph::CHECK, $this->t('Complete'), 'complete'],
      'partial'  => [AnnotationsGlyph::PARTIAL, $this->t('Partial'), 'partial'],
      'empty'    => [AnnotationsGlyph::CROSS, $this->t('Empty'), 'empty'],
    ];

    [$glyph, $label, $modifier] = $map[$status] ?? ['?', $status, ''];

    return [
      '#theme'    => 'annotations_status_icon',
      '#glyph'    => $glyph,
      '#label'    => $label,
      '#modifier' => $modifier,
    ];
  }

  /**
   * Builds the render array for the Gaps column cell of a non-complete target.
   */
  private function buildGapCell(AnnotationTargetInterface $target, array $coverage_entry, array $all_types): array {
    $missing   = $coverage_entry['missing'];
    $gap_count = array_sum(array_map('count', $missing));

    if ($gap_count === 0) {
      return [];
    }

    $field_labels = [];
    if ($this->entityTypeManager()->getDefinition($target->getTargetEntityTypeId(), FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      $definitions = $this->fieldManager->getFieldDefinitions(
        $target->getTargetEntityTypeId(),
        $target->getBundle(),
      );
      foreach ($definitions as $field_name => $definition) {
        $field_labels[$field_name] = (string) $definition->getLabel();
      }
    }

    $tracked_types  = array_filter($all_types, fn($t) => $this->coverageService->affectsCoverage($t));
    $optional_types = array_filter($all_types, fn($t) => !$this->coverageService->affectsCoverage($t));

    $tracked_gap_count = 0;
    foreach ($tracked_types as $type_id => $_) {
      $tracked_gap_count += count($missing[$type_id] ?? []);
    }

    $summary_text = (string) $this->formatPlural($gap_count, '1 gap', '@count gaps');
    if ($tracked_gap_count > 0 && $tracked_gap_count < $gap_count) {
      $summary_text .= ' (' . $this->formatPlural($tracked_gap_count, '1 tracked', '@count tracked') . ')';
    }
    elseif ($tracked_gap_count === $gap_count && $gap_count > 0) {
      $summary_text .= ' (' . (string) $this->t('all tracked') . ')';
    }

    return [
      '#prefix' => '<details class="annotations-report__gaps" name="annotations-report-gaps"><summary>' . Html::escape($summary_text) . '</summary>',
      '#suffix' => '</details>',
      'empty'    => $this->buildCompletelyEmptySection($target, $missing, $all_types, $field_labels),
      'tracked'  => $this->buildGapSection((string) $this->t('Tracked types'), $tracked_types, $missing, 'tracked', $field_labels),
      'optional' => $this->buildGapSection((string) $this->t('Optional types'), $optional_types, $missing, 'optional', $field_labels),
    ];
  }

  /**
   * Builds a gap section for one priority tier.
   *
   * @param string $heading
   *   Section heading text.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *   Annotation types to include in this section.
   * @param array $missing
   *   Missing locations keyed by type ID.
   * @param string $modifier
   *   BEM modifier class for the section.
   * @param array $field_labels
   *   Field machine name to human-readable label map.
   *
   * @return array
   *   Render array for the gap section, or empty array if no gaps.
   */
  private function buildGapSection(string $heading, array $types, array $missing, string $modifier = '', array $field_labels = []): array {
    $items = [];
    $total = 0;

    foreach ($types as $type_id => $type) {
      $locations = $missing[$type_id] ?? [];
      if (empty($locations)) {
        continue;
      }
      $total += count($locations);
      $parts = [];
      if (in_array('overview', $locations, TRUE)) {
        $parts[] = (string) $this->t('overview');
      }
      foreach (array_filter($locations, fn($l) => $l !== 'overview') as $fname) {
        $parts[] = $field_labels[$fname] ?? $fname;
      }
      $items[] = Markup::create(
        '<strong>' . Html::escape((string) $type->label()) . '</strong>'
        . ': ' . Html::escape(implode(', ', $parts))
      );
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#theme'    => 'annotations_coverage_gap_section',
      '#heading'  => $heading . ' (' . (string) $this->formatPlural($total, '1 missing', '@count missing') . ')',
      '#modifier' => $modifier,
      '#items'    => ['#theme' => 'item_list', '#items' => $items, '#list_type' => 'ul'],
    ];
  }

  /**
   * Builds a section listing locations with zero annotations of any type.
   */
  private function buildCompletelyEmptySection(AnnotationTargetInterface $target, array $missing, array $all_types, array $field_labels = []): array {
    $total_types    = count($all_types);
    $missing_counts = [];
    foreach ($missing as $locations) {
      foreach ($locations as $loc) {
        $missing_counts[$loc] = ($missing_counts[$loc] ?? 0) + 1;
      }
    }

    $completely_empty = array_keys(array_filter(
      $missing_counts,
      fn($count) => $count === $total_types
    ));

    if (empty($completely_empty)) {
      return [];
    }

    $items = array_map(
      fn($loc) => $loc === 'overview'
        ? (string) $this->t('Target overview')
        : ($field_labels[$loc] ?? Markup::create('<code>' . Html::escape($loc) . '</code>')),
      $completely_empty
    );

    return [
      '#theme'                  => 'annotations_coverage_gap_section',
      '#heading'                => $this->t('No annotations at all'),
      '#visually_hidden_prefix' => $this->t('Urgent:'),
      '#modifier'               => 'urgent',
      '#items'                  => ['#theme' => 'item_list', '#items' => $items, '#list_type' => 'ul'],
    ];
  }

}
