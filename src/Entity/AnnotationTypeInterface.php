<?php

declare(strict_types=1);

namespace Drupal\annotations\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for the AnnotationType config entity.
 *
 * An annotation type defines one category of annotation that can be written
 * on an annotation_target — for example "editorial", "technical", or "rules".
 * Types are config entities: sites can rename, remove, or add types via config
 * management with no code changes.
 */
interface AnnotationTypeInterface extends ConfigEntityInterface {

  /**
   * Returns the human-readable description of this annotation type.
   */
  public function getDescription(): string;

  /**
   * Returns the Drupal permission machine name required to edit this type.
   *
   * E.g. "edit editorial annotations"
   */
  public function getEditPermission(): string;

  /**
   * Returns the Drupal permission machine name required to consume this type
   * in end-user context output (overlays, AI context, exports).
   *
   * E.g. "consume editorial annotations"
   */
  public function getConsumePermission(): string;

  /**
   * Returns the ordering weight (lower = shown first in UI and output).
   *
   * Controls the order annotation type sections appear in the annotation form,
   * coverage reports, overlay panels, and context exports.
   */
  public function getWeight(): int;

}
