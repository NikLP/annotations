<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the human-readable label for an annotation_target entity.
 */
#[ViewsField('annotation_target_label')]
class AnnotationTargetLabelField extends FieldPluginBase {

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
  public function render(ResultRow $values): mixed {
    $target_id = (string) ($this->getValue($values) ?? '');
    if ($target_id === '') {
      return $this->t('Site-wide');
    }
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    return $target ? $target->label() : $target_id;
  }

}
