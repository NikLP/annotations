<?php

declare(strict_types=1);

namespace Drupal\annotations\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the AnnotationType config entity.
 *
 * An annotation type represents one category of annotation that can be written
 * on an annotation_target — for example "editorial", "technical", or "rules".
 * The three default types ship as config/install YAML. Sites can rename,
 * remove, or add types via config management (drush cim / UI) with no code
 * changes required.
 *
 * Config files are named annotations.annotation_type.{id}.yml
 *
 * @ConfigEntityType(
 *   id = "annotation_type",
 *   label = @Translation("Annotation type"),
 *   label_collection = @Translation("Annotation types"),
 *   label_singular = @Translation("annotation type"),
 *   label_plural = @Translation("annotation types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count annotation type",
 *     plural = "@count annotation types",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\Core\Config\Entity\ConfigEntityListBuilder",
 *   },
 *   bundle_of = "annotation",
 *   config_prefix = "annotation_type",
 *   admin_permission = "administer annotations",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "weight",
 *   },
 * )
 */
class AnnotationType extends ConfigEntityBundleBase implements AnnotationTypeInterface {

  /**
   * The machine name, e.g. "editorial", "technical", "rules".
   */
  protected string $id;

  /**
   * Human-readable label, e.g. "Editorial overview".
   */
  protected string $label;

  /**
   * A description of what this annotation type is for.
   */
  protected string $description = '';

  /**
   * Display/form ordering weight. Lower = shown first in the annotation form.
   */
  protected int $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getPermission(): string {
    return 'edit ' . $this->id() . ' annotations';
  }

  /**
   * {@inheritdoc}
   */
  public function getConsumePermission(): string {
    return 'consume ' . $this->id() . ' annotations';
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

}
