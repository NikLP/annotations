<?php

declare(strict_types=1);

namespace Drupal\annotations\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for the AnnotationTarget config entity.
 *
 * An AnnotationTarget represents a single opted-in unit for scanning and annotation:
 * a content type, taxonomy vocabulary, user role, view, workflow, etc.
 *
 * The entity ID is always "{entity_type}__{bundle}", e.g. "node__article",
 * "user_role__administrator", "view__frontpage".
 *
 * This entity stores scope only — which targets and fields are in scope.
 * Annotation text is stored separately in annotation rows via
 * AnnotationStorageService.
 *
 * Field scope uses an inclusion list: fields returned by getFields() are in scope.
 * Fields absent from the list are excluded from scanning and annotation.
 */
interface AnnotationTargetInterface extends ConfigEntityInterface {

  /**
   * Returns the Drupal entity type ID (e.g. "node", "taxonomy_term").
   *
   * Distinct from getEntityTypeId() which returns "annotation_target" (the
   * config entity type of this class itself).
   */
  public function getTargetEntityTypeId(): string;

  /**
   * Returns the bundle machine name or entity ID used as sub-key.
   *
   * For standard entity types (node, taxonomy_term, media, paragraph) this is
   * a Drupal bundle machine name. For unbundled targets (user_role, view,
   * workflow) this holds the individual entity's machine name.
   */
  public function getBundle(): string;

  /**
   * Returns the list of field machine names in scope.
   *
   * @return string[]
   */
  public function getFields(): array;

  /**
   * Returns TRUE if the given field machine name is in scope.
   */
  public function isFieldIncluded(string $field_name): bool;

  /**
   * Replaces the entire fields in-scope list.
   *
   * @param string[] $fields
   *   Field machine names to include in scope.
   */
  public function setFields(array $fields): static;

}
