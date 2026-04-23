<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters annotation rows by target, showing human-readable labels.
 */
#[ViewsFilter('annotation_target_filter')]
class AnnotationTargetFilter extends InOperator {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): array {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }
    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    uasort($targets, fn($a, $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));
    $this->valueOptions = [];
    foreach ($targets as $target) {
      $this->valueOptions[$target->id()] = (string) $target->label();
    }
    return $this->valueOptions;
  }

}
