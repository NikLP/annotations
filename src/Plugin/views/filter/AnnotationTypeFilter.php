<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters annotation rows by annotation type, showing human-readable labels.
 */
#[ViewsFilter('annotation_type_filter')]
class AnnotationTypeFilter extends InOperator {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  public function getValueOptions(): array {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }
    $types = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
    uasort($types, fn($a, $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));
    $this->valueOptions = [];
    foreach ($types as $type) {
      $this->valueOptions[$type->id()] = (string) $type->label();
    }
    return $this->valueOptions;
  }

}
