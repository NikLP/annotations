<?php

declare(strict_types=1);

namespace Drupal\annotations\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the annotation target config entity.
 *
 * A single AnnotationTarget represents one opted-in unit for scanning and annotation.
 * This entity stores scope only — which target is opted in and which of its
 * fields are included. Annotation text lives in annotation rows
 * managed by AnnotationStorageService.
 *
 * The presence of this entity means the target is opted in. The fields map
 * determines which fields are in scope for scanning and annotation.
 *
 * Config files are named annotations.target.{id}.yml, e.g. annotations.target.node__article.yml
 *
 * @ConfigEntityType(
 *   id = "annotation_target",
 *   label = @Translation("Annotation target"),
 *   label_collection = @Translation("Annotation targets"),
 *   label_singular = @Translation("annotation target"),
 *   label_plural = @Translation("annotation targets"),
 *   label_count = @PluralTranslation(
 *     singular = "@count annotation target",
 *     plural = "@count annotation targets",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\Core\Config\Entity\ConfigEntityListBuilder",
 *   },
 *   config_prefix = "target",
 *   admin_permission = "administer annotations",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "entity_type",
 *     "bundle",
 *     "fields",
 *   },
 * )
 */
class AnnotationTarget extends ConfigEntityBase implements AnnotationTargetInterface {

  /**
   * The machine name: "{entity_type}__{bundle}", e.g. "node__article".
   */
  protected string $id;

  /**
   * Human-readable label, e.g. "Article (Content type)".
   */
  protected string $label;

  /**
   * The Drupal entity type ID, e.g. "node", "taxonomy_term", "user_role".
   */
  protected string $entity_type = '';

  /**
   * The bundle machine name or entity ID used as sub-key.
   */
  protected string $bundle = '';

  /**
   * Fields in-scope map.
   *
   * Keyed by field machine name. Values are empty arrays — presence means the
   * field is in scope, absence means excluded. No annotation data is stored
   * here; that lives in annotation rows.
   *
   * @var array<string, array>
   */
  protected array $fields = [];

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return $this->entity_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle(): string {
    return $this->bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getFields(): array {
    return $this->fields;
  }

  /**
   * {@inheritdoc}
   */
  public function isFieldIncluded(string $field_name): bool {
    return array_key_exists($field_name, $this->fields);
  }

  /**
   * {@inheritdoc}
   */
  public function setFields(array $fields): static {
    $this->fields = $fields;
    return $this;
  }

}
