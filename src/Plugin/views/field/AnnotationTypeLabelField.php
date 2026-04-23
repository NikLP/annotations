<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the human-readable label for an annotation type.
 */
#[ViewsField('annotation_type_label')]
class AnnotationTypeLabelField extends FieldPluginBase {

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
  public function query(): void {
    $this->ensureMyTable();
    parent::query();
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): mixed {
    $type_id = (string) ($this->getValue($values) ?? '');
    $entity = $this->entityTypeManager->getStorage('annotation_type')->load($type_id);
    return $entity ? $entity->label() : $type_id;
  }

}
