<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\filter;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters annotation rows by field name, showing human-readable labels.
 *
 * Options are built from the fields declared in-scope across all annotation
 * targets. Bundle-level annotations (field_name IS NULL) are not listed here;
 * leaving this filter unset naturally includes them.
 */
#[ViewsFilter('annotation_field_filter')]
class AnnotationFieldFilter extends InOperator {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  public function getValueOptions(): array {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }

    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    $options = [];

    foreach ($targets as $target) {
      $fields = $target->getFields();
      if (empty($fields)) {
        continue;
      }
      $definitions = $this->entityFieldManager->getFieldDefinitions(
        $target->getTargetEntityTypeId(),
        $target->getBundle(),
      );
      foreach (array_keys($fields) as $field_name) {
        if (!isset($options[$field_name])) {
          $options[$field_name] = isset($definitions[$field_name])
            ? (string) $definitions[$field_name]->getLabel()
            : $field_name;
        }
      }
    }

    asort($options);
    // Prepend Overview so it appears at the top regardless of sort order.
    $this->valueOptions = ['' => (string) $this->t('Overview')] + $options;
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   *
   * Translates the Overview sentinel ('') to an IS NULL check, since
   * field_name = '' is stored as NULL by Drupal's string field storage.
   */
  public function query(): void {
    if (empty($this->value)) {
      return;
    }

    $this->ensureMyTable();
    $field = "$this->tableAlias.$this->realField";

    $wants_null = in_array('', $this->value, TRUE);
    $real_values = array_values(array_filter($this->value, fn($v) => $v !== ''));

    if (!$wants_null) {
      parent::query();
      return;
    }

    if (empty($real_values)) {
      $this->query->addWhereExpression($this->options['group'], "$field IS NULL");
      return;
    }

    // Mix of Overview + specific fields: build IN (...) OR IS NULL.
    $id_key = str_replace(['-', '.'], '_', $this->options['id']);
    $args = [];
    $placeholders = [];
    foreach ($real_values as $i => $val) {
      $key = ":aff_{$id_key}_{$i}";
      $placeholders[] = $key;
      $args[$key] = $val;
    }
    $in = implode(', ', $placeholders);
    $this->query->addWhereExpression(
      $this->options['group'],
      "($field IN ($in) OR $field IS NULL)",
      $args,
    );
  }

}
