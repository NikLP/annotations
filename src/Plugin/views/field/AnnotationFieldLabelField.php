<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\field;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the human-readable label for an annotated field.
 */
#[ViewsField('annotation_field_label')]
class AnnotationFieldLabelField extends FieldPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->additional_fields['target_id'] = 'target_id';
    $this->addAdditionalFields();
    parent::query();
  }

  public function render(ResultRow $values): mixed {
    $field_name = (string) ($this->getValue($values) ?? '');
    $target_id  = (string) ($this->getValue($values, 'target_id') ?? '');

    if ($target_id === '') {
      return $this->t('N/A');
    }
    if ($field_name === '') {
      return $this->t('Overview');
    }

    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if (!$target) {
      return $field_name;
    }

    $entity_type = $this->entityTypeManager->getDefinition($target->getTargetEntityTypeId(), FALSE);
    if (!$entity_type || !$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
      return $field_name;
    }

    $definitions = $this->fieldManager->getFieldDefinitions(
      $target->getTargetEntityTypeId(),
      $target->getBundle(),
    );

    return isset($definitions[$field_name])
      ? $definitions[$field_name]->getLabel()
      : $field_name;
  }

}
