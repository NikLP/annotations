<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Computed field type that marks an entity as having an annotations overlay.
 */
#[FieldType(
  id: 'annotations_overlay',
  label: new TranslatableMarkup('Annotations overlay'),
  no_ui: TRUE,
)]
class AnnotationsOverlayItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Value'))
      ->setComputed(TRUE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return FALSE;
  }

}
