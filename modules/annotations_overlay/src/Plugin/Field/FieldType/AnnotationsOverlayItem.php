<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

#[FieldType(
  id: 'annotations_overlay',
  label: new TranslatableMarkup('Annotations overlay'),
  no_ui: TRUE,
)]
class AnnotationsOverlayItem extends FieldItemBase {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Value'))
      ->setComputed(TRUE);
    return $properties;
  }

  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [];
  }

  public function isEmpty(): bool {
    return FALSE;
  }

}
