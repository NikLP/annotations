<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Interface for Target plugins.
 *
 * A Target plugin is responsible for discovering all bundles and their
 * fields for one entity type (or a family of related entity types). The
 * scanner service iterates all enabled plugins and merges their results.
 *
 * Plugins should be placed in src/Plugin/Target/.
 */
interface TargetInterface {

  /**
   * Returns the entity type ID this plugin handles (e.g. "node").
   */
  public function getEntityTypeId(): string;

  /**
   * Returns a human-readable label for this target type.
   */
  public function getLabel(): string;

  /**
   * Returns TRUE if this plugin's entity type is available on the current site.
   *
   * Allows optional plugins (e.g. Media, Paragraphs) to gracefully do nothing
   * when their module is not installed.
   */
  public function isAvailable(): bool;

  /**
   * Returns TRUE if targets of this type have scannable fields.
   *
   * FALSE for entity types where the target itself is the unit of annotation
   * and there are no fields to include or exclude (e.g. roles, views,
   * workflows). Controls whether the "Configure fields" link is shown in the
   * scope UI.
   */
  public function hasFields(): bool;

  /**
   * Returns all available bundles/items for this entity type.
   *
   * Used by the scope configuration UI to build the accordion listing.
   * For standard entity types this comes from bundle info. For types without
   * traditional bundles (Views, Workflows), each individual entity is its
   * own "bundle".
   *
   * @return array<string, string> Bundle machine name => human-readable label.
   */
  public function getBundles(): array;

  /**
   * Discovers all bundles and their fields for this entity type.
   *
   * Returns a structured array keyed by bundle machine name:
   * @code
   * [
   *   'article' => [
   *     'label' => 'Article',
   *     'entity_type' => 'node',
   *     'bundle' => 'article',
   *     'fields' => [
   *       'title' => ['label' => 'Title', 'type' => 'string', ...],
   *       'body'  => ['label' => 'Body',  'type' => 'text_with_summary', ...],
   *     ],
   *   ],
   * ]
   * @endcode
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $scopes
   *   Indexed by "{entity_type}__{bundle}". Only bundles present here should
   *   be returned; fields listed as excluded should be omitted.
   *
   * @return array<string, array<string, mixed>>
   */
  public function discover(array $scopes): array;

}
