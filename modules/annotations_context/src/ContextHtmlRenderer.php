<?php

declare(strict_types=1);

namespace Drupal\annotations_context;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Renders a ContextAssembler payload as Drupal render arrays.
 *
 * Produces a clean, browseable document. Each entity type is an h2 section.
 * Each target is a collapsible details/summary element. Inside: bundle-level
 * annotation text, then a border-separated fields block with field name and
 * annotation text for each in-scope field.
 *
 * No status icons, no scores, no coverage chrome — the content is the point.
 * Judgments about coverage belong in the coverage module.
 */
class ContextHtmlRenderer {

  use StringTranslationTrait;

  /**
   * Whether to render annotation type labels.
   *
   * Set to FALSE when the payload contains only one distinct annotation type —
   * repeating the same label on every block adds no information.
   */
  private bool $showTypeLabel = TRUE;

  /**
   * Whether open accordion panels should close siblings (exclusive mode).
   *
   * Set from the annotations.settings 'use_accordion_single' config key.
   */
  private bool $exclusiveAccordions = FALSE;

  public function __construct(
    TranslationInterface $stringTranslation,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->stringTranslation = $stringTranslation;
    $this->exclusiveAccordions = (bool) $configFactory->get('annotations.settings')->get('use_accordion_single');
  }

  /**
   * Renders the full payload as a Drupal render array.
   *
   * @param array $payload
   *   The payload returned by ContextAssembler::assemble().
   *
   * @return array
   *   A Drupal render array.
   */
  public function render(array $payload): array {
    $this->showTypeLabel = $this->countDistinctTypeIds($payload) > 1;
    $build = [];

    // One section per entity type group.
    foreach ($payload['groups'] as $et_id => $group) {
      if (!empty($group['targets'])) {
        $build['group_' . $et_id] = $this->buildGroup($group);
      }
    }

    return $build;
  }

  /**
   * Builds the h2 section container for one entity type group.
   */
  private function buildGroup(array $group): array {
    $section = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__group']],
      'heading'     => [
        '#type'  => 'html_tag',
        '#tag'   => 'h2',
        '#value' => Html::escape($group['label']),
        '#attributes' => ['class' => ['annotations-context__group-heading']],
      ],
    ];

    foreach ($group['targets'] as $target_data) {
      if (empty($target_data['annotations']) && empty($target_data['fields']) && empty($target_data['references'])) {
        continue;
      }
      $section['target_' . $target_data['id']] = $this->buildTarget($target_data);
    }

    return $section;
  }

  /**
   * Builds the collapsible details card for a single target.
   */
  private function buildTarget(array $target_data): array {
    $has_overview = !empty($target_data['annotations']);
    $has_fields   = !empty($target_data['fields']);
    $has_refs     = !empty($target_data['references']);

    $attrs = ['class' => ['annotations-context__target']];
    if ($this->exclusiveAccordions) {
      $attrs['name'] = 'annotations-context-' . ($target_data['entity_type'] ?? 'target');
    }
    $card = [
      '#type'       => 'details',
      '#open'       => FALSE,
      '#title'      => Html::escape($target_data['label']),
      '#attributes' => $attrs,
    ];

    // Bundle-level overview — only add the "Overview" heading when other
    // sections follow; alone it adds no navigational value.
    if ($has_overview) {
      $card['overview'] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['annotations-context__overview']],
      ];
      if ($has_fields || $has_refs) {
        $card['overview']['heading'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'h3',
          '#value'      => (string) $this->t('Overview'),
          '#attributes' => ['class' => ['annotations-context__section-label']],
        ];
      }
      foreach ($target_data['annotations'] as $type_id => $annotation) {
        $card['overview'][$type_id] = $this->annotationBlock(
          $annotation['label'],
          $annotation['value'],
          $annotation['extra_fields'] ?? [],
        );
      }
    }

    // Fields block — border-top separates it from the overview.
    if ($has_fields) {
      $card['fields'] = $this->buildFieldsBlock($target_data['fields']);
    }

    // Referenced targets (only present when ref_depth > 0).
    if ($has_refs) {
      $card['references'] = $this->buildReferencesBlock($target_data['references']);
    }

    return $card;
  }

  /**
   * Builds the border-separated fields block inside a target card.
   */
  private function buildFieldsBlock(array $fields): array {
    $block = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__fields']],
      'heading'     => [
        '#type'       => 'html_tag',
        '#tag'        => 'h3',
        '#value'      => (string) $this->t('Fields'),
        '#attributes' => ['class' => ['annotations-context__section-label']],
      ],
    ];

    foreach ($fields as $field_name => $field_data) {
      $field = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['annotations-context__field']],
        'name'        => [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => Html::escape($field_data['label']),
          '#attributes' => ['class' => ['annotations-context__field-name']],
        ],
      ];

      if (!empty($field_data['meta'])) {
        $meta = $field_data['meta'];
        $summary = Html::escape($meta['type']) . ' &middot; ' . Html::escape($meta['cardinality']);
        $field['meta'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => Markup::create($summary),
          '#attributes' => ['class' => ['annotations-context__field-meta']],
        ];
        if (!empty($meta['description'])) {
          $field['meta_description'] = [
            '#type'       => 'html_tag',
            '#tag'        => 'p',
            '#value'      => Markup::create(Html::escape($meta['description'])),
            '#attributes' => ['class' => ['annotations-context__field-meta-desc']],
          ];
        }
      }

      foreach ($field_data['annotations'] as $type_id => $annotation) {
        $field['annotation_' . $type_id] = $this->annotationBlock(
          $annotation['label'],
          $annotation['value'],
          $annotation['extra_fields'] ?? [],
        );
      }

      $block['field_' . $field_name] = $field;
    }

    return $block;
  }

  /**
   * Builds the references block containing nested target cards.
   */
  private function buildReferencesBlock(array $references): array {
    $block = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__references']],
      'heading'     => [
        '#type'       => 'html_tag',
        '#tag'        => 'h3',
        '#value'      => (string) $this->t('References'),
        '#attributes' => ['class' => ['annotations-context__section-label']],
      ],
    ];

    foreach ($references as $field_name => $ref_targets) {
      foreach ($ref_targets as $ref_id => $ref_data) {
        $ref_card = [
          '#type'       => 'details',
          '#open'       => FALSE,
          '#title'      => Markup::create(
            Html::escape($ref_data['label'])
            . ' <em>(' . Html::escape((string) $this->t('via @field', ['@field' => $field_name])) . ')</em>'
          ),
          '#attributes' => ['class' => ['annotations-context__target', 'annotations-context__target--ref']],
        ];

        if (!empty($ref_data['annotations'])) {
          $ref_card['overview'] = [
            '#type'       => 'container',
            '#attributes' => ['class' => ['annotations-context__overview']],
          ];
          foreach ($ref_data['annotations'] as $type_id => $annotation) {
            $ref_card['overview'][$type_id] = $this->annotationBlock($annotation['label'], $annotation['value'], $annotation['extra_fields'] ?? []);
          }
        }
        if (!empty($ref_data['fields'])) {
          $ref_card['fields'] = $this->buildFieldsBlock($ref_data['fields']);
        }

        $block['ref_' . $field_name . '_' . $ref_id] = $ref_card;
      }
    }

    return $block;
  }

  /**
   * Builds a single annotation block: type label + text + extra fields.
   *
   * The type label is suppressed when the payload contains only one distinct
   * annotation type — in that case every block would say the same thing.
   *
   * @param string $type_label
   *   The human-readable annotation type label (e.g. "Editorial").
   * @param string $value
   *   The annotation text (stored raw — must be escaped here). May be empty
   *   when the annotation entity has configurable extra fields but no value.
   * @param array $extra_fields
   *   Configurable field values keyed by field name:
   *   ['field_foo' => ['label' => 'Foo', 'values' => ['bar', 'baz']], ...].
   */
  private function annotationBlock(string $type_label, string $value, array $extra_fields = []): array {
    $block = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__annotation']],
    ];

    if ($this->showTypeLabel) {
      $block['label'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => Html::escape($type_label),
        '#attributes' => ['class' => ['annotations-context__type-label']],
      ];
    }

    if ($value !== '') {
      $block['text'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => Markup::create(Html::escape($value)),
        '#attributes' => ['class' => ['annotations-context__annotation-text']],
      ];
    }

    foreach ($extra_fields as $field_name => $extra) {
      $block['extra_' . $field_name] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => Markup::create(
          '<strong>' . Html::escape($extra['label']) . ':</strong> '
          . implode(', ', array_map(Html::escape(...), $extra['values']))
        ),
        '#attributes' => ['class' => ['annotations-context__annotation-extra']],
      ];
    }

    return $block;
  }

  /**
   * Counts the number of distinct annotation type IDs across all targets.
   */
  private function countDistinctTypeIds(array $payload): int {
    $ids = [];
    foreach ($payload['groups'] as $group) {
      foreach ($group['targets'] as $target) {
        foreach (array_keys($target['annotations'] ?? []) as $id) {
          $ids[$id] = TRUE;
        }
        foreach ($target['fields'] ?? [] as $field) {
          foreach (array_keys($field['annotations'] ?? []) as $id) {
            $ids[$id] = TRUE;
          }
        }
      }
    }
    return count($ids);
  }

}
