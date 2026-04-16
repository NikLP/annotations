<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for Target plugins.
 *
 * Concrete plugins set $entityTypeId and override getLabel(). The default
 * discover() implementation handles standard entity types with bundles and
 * field definitions. Override if the entity type needs special handling.
 */
abstract class TargetBase implements TargetInterface {

  /**
   * Base fields included in annotation scope alongside FieldConfig fields.
   *
   * Most entity base fields (uid, created, changed, status, langcode, etc.)
   * are system fields not worth annotating. These four are editorial content
   * fields that editors actively work with. Custom base fields defined by
   * contributed modules are not included — add them to a site-specific plugin
   * if needed.
   */
  const NOTABLE_BASE_FIELDS = ['title', 'body', 'name', 'description'];

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * The entity type ID this plugin handles. Set in each concrete plugin.
   */
  protected string $entityTypeId;

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId(): string {
    return $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFields(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    return array_map(
      fn($info) => (string) $info['label'],
      $this->bundleInfo->getBundleInfo($this->entityTypeId)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->entityTypeManager->hasDefinition($this->entityTypeId);
  }

  /**
   * {@inheritdoc}
   */
  public function discover(array $scopes): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $results = [];
    $bundles = $this->bundleInfo->getBundleInfo($this->entityTypeId);

    foreach ($bundles as $bundle_id => $bundle_info) {
      $scope_key = $this->entityTypeId . '__' . $bundle_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $scope = $scopes[$scope_key];
      $fields = [];

      $field_definitions = $this->fieldManager->getFieldDefinitions($this->entityTypeId, $bundle_id);
      foreach ($field_definitions as $field_name => $definition) {
        if (!$scope->isFieldIncluded($field_name)) {
          continue;
        }

        $fields[$field_name] = [
          'label' => (string) $definition->getLabel(),
          'type' => $definition->getType(),
          'required' => $definition->isRequired(),
          'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
          'description' => (string) $definition->getDescription(),
        ];
      }

      $results[$bundle_id] = [
        'label' => (string) $bundle_info['label'],
        'entity_type' => $this->entityTypeId,
        'bundle' => $bundle_id,
        'fields' => $fields,
      ];
    }

    return $results;
  }

}
