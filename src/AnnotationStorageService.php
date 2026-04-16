<?php

declare(strict_types=1);

namespace Drupal\annotations;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Manages persistence for annotation text.
 *
 * All annotation values — target-level and field-level — are stored as
 * annotation content entities. This layer is never touched by config
 * sync, so annotation content survives deploys and can be edited directly on
 * production.
 *
 * Empty string sentinel:
 *   - field_name = '' means bundle-level (the target's overview annotation)
 *
 * Revision behaviour:
 *   annotation implements EntityPublishedInterface (via
 *   EditorialContentEntityBase). Consumer contexts pass $published_only = TRUE
 *   to getForTarget() to filter to status = 1 rows only.
 *
 *   When a content moderation workflow is attached to annotation, saving
 *   an entity in a state with published: true automatically sets status = 1;
 *   all other states set status = 0. This is handled by ModerationHandler and
 *   requires no Annotations-specific code.
 *
 *   When no workflow is attached, status defaults to 1 on creation (all
 *   annotations are visible). getForTarget($id, TRUE) and getForTarget($id,
 *   FALSE) then behave the same — all rows have status = 1.
 *
 *   Use getLatestForTarget() in editing UIs so annotators see their
 *   in-progress draft rather than the last published revision.
 */
class AnnotationStorageService {

  /**
   * Per-request cache for getForTarget() results.
   *
   * Keyed by "{target_id}|{published_only}|{langcode}". Populated on first
   * call for a given combination; returned directly on subsequent calls within
   * the same request. The service is a DI singleton so this survives across
   * multiple callers within one page build.
   *
   * @var array<string, array<string, array<string, string>>>
   */
  private array $requestCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Returns all annotations for a target, keyed by field_name then type_id.
   *
   * Loads the DEFAULT revision of each entity. When a content moderation
   * workflow is attached, the default revision is the last revision whose
   * moderation state has published: true — i.e. status = 1.
   *
   * Bundle-level annotations are returned under the '' (empty string) key.
   * All rows for the target are included; a record with an empty value string
   * is still present in the map so that callers can distinguish "no record
   * exists" from "record exists but value is empty".
   *
   * For translatable entities, values are read from the requested language
   * translation if it exists, otherwise the default translation is used.
   *
   * @param string $target_id
   *   The annotation_target machine name. Pass '' to retrieve site-wide annotations.
   * @param bool $published_only
   *   If TRUE, only return entities with status = 1 (published). Pass TRUE in
   *   consumer contexts (overlay, report, context assembler). When no workflow
   *   is attached all entities default to status = 1, so the flag is a no-op.
   * @param string|null $langcode
   *   Language code to read values for. Defaults to the current content
   *   language. Pass LanguageInterface::LANGCODE_DEFAULT to force the default
   *   translation.
   *
   * @return array<string, array<string, string>>
   *   Keyed [field_name => [type_id => value]]. Bundle-level uses key ''.
   */
  public function getForTarget(string $target_id, bool $published_only = FALSE, ?string $langcode = NULL): array {
    $langcode ??= $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $key = $target_id . '|' . (int) $published_only . '|' . $langcode;
    if (isset($this->requestCache[$key])) {
      return $this->requestCache[$key];
    }

    $storage = $this->entityTypeManager->getStorage('annotation');
    $query = $storage->getQuery()->accessCheck(FALSE);
    // Empty string sentinels are stored as NULL by Drupal's field storage
    // (StringItem::isEmpty() treats '' as empty). Use IS NULL for those cases.
    $target_id === ''
      ? $query->condition('target_id', NULL, 'IS NULL')
      : $query->condition('target_id', $target_id);
    if ($published_only) {
      $query->condition('status', 1);
    }
    $ids = $query->execute();

    $result = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $translation = $entity->hasTranslation($langcode)
        ? $entity->getTranslation($langcode)
        : $entity;
      $field_name = (string) ($entity->get('field_name')->value ?? '');
      $type_id    = (string) $entity->get('type_id')->value;
      $value      = (string) $translation->get('value')->value;
      $result[$field_name][$type_id] = $value;
    }

    $this->requestCache[$key] = $result;
    return $result;
  }

  /**
   * Returns annotation values from the LATEST revision of each entity.
   *
   * Use this in editing UIs so that in-progress drafts are shown to the
   * annotator. Consumer contexts (overlay, report, context assembler) should
   * use getForTarget() which loads the default (published) revision.
   *
   * @param string $target_id
   *   The annotation_target machine name. Pass '' to retrieve site-wide annotations.
   * @param string|null $langcode
   *   Language code to read values for. Defaults to the current content
   *   language.
   *
   * @return array<string, array<string, string>>
   *   Keyed [field_name => [type_id => value]]. Bundle-level uses key ''.
   */
  public function getLatestForTarget(string $target_id, ?string $langcode = NULL): array {
    $langcode ??= $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $result = [];
    foreach ($this->getEntitiesForTarget($target_id) as $entity) {
      $translation = $entity->hasTranslation($langcode)
        ? $entity->getTranslation($langcode)
        : $entity;
      $field_name = (string) ($entity->get('field_name')->value ?? '');
      $type_id    = (string) $entity->get('type_id')->value;
      $value      = (string) $translation->get('value')->value;
      $result[$field_name][$type_id] = $value;
    }
    return $result;
  }

  /**
   * Returns the latest revision entity for each annotation in the target.
   *
   * Keyed by 'field_name|type_id'. Bundle-level entries use '' as field_name,
   * yielding key '|type_id'. Used by annotations_workflow to inspect and
   * transition per-entity moderation states.
   *
   * @param string $target_id
   *   The annotation_target machine name. Pass '' to retrieve site-wide entities.
   *
   * @return array<string, \Drupal\annotations\Entity\Annotation>
   *   Keyed by 'field_name|type_id'.
   */
  public function getEntitiesForTarget(string $target_id): array {
    $storage = $this->entityTypeManager->getStorage('annotation');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->latestRevision();
    $target_id === ''
      ? $query->condition('target_id', NULL, 'IS NULL')
      : $query->condition('target_id', $target_id);
    // latestRevision() returns [revision_id => entity_id].
    $revision_ids = array_keys($query->execute());

    $keyed = [];
    foreach ($storage->loadMultipleRevisions($revision_ids) as $entity) {
      $field_name = (string) ($entity->get('field_name')->value ?? '');
      $type_id    = (string) $entity->get('type_id')->value;
      $keyed[$field_name . '|' . $type_id] = $entity;
    }
    return $keyed;
  }

  /**
   * Returns annotation entities for a target, keyed by field_name then type_id.
   *
   * Loads the DEFAULT revision of each entity, same as getForTarget(). Intended
   * for consumers (e.g. annotations_overlay) that need to render full fieldable
   * entity content rather than just the base value string.
   *
   * Bundle-level annotations are returned under the '' (empty string) key.
   * The returned entity is the active translation for the requested language.
   *
   * @param string $target_id
   *   The annotation_target machine name.
   * @param bool $published_only
   *   If TRUE, only return entities with status = 1 (published).
   * @param string|null $langcode
   *   Language code to read. Defaults to the current content language.
   *
   * @return array<string, array<string, \Drupal\annotations\Entity\Annotation>>
   *   Keyed [field_name => [type_id => Annotation]]. Bundle-level uses key ''.
   */
  public function getEntityMapForTarget(string $target_id, bool $published_only = FALSE, ?string $langcode = NULL): array {
    $langcode ??= $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    $storage = $this->entityTypeManager->getStorage('annotation');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $target_id === ''
      ? $query->condition('target_id', NULL, 'IS NULL')
      : $query->condition('target_id', $target_id);
    if ($published_only) {
      $query->condition('status', 1);
    }
    $ids = $query->execute();

    $result = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $translation = $entity->hasTranslation($langcode)
        ? $entity->getTranslation($langcode)
        : $entity;
      $field_name = (string) ($entity->get('field_name')->value ?? '');
      $type_id    = (string) $entity->get('type_id')->value;
      $result[$field_name][$type_id] = $translation;
    }

    return $result;
  }

  /**
   * Returns TRUE if any non-empty annotation row exists for the given target.
   *
   * Used to gate deletion confirmation: targets with annotation data need
   * explicit user confirmation before deletion.
   */
  public function hasAnnotationData(string $target_id): bool {
    $ids = $this->entityTypeManager->getStorage('annotation')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_id', $target_id)
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

  /**
   * Deletes all annotation rows for the given target.
   *
   * Called when an annotation_target is deleted so no orphan rows remain.
   */
  public function deleteForTarget(string $target_id): void {
    $storage = $this->entityTypeManager->getStorage('annotation');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $target_id === ''
      ? $query->condition('target_id', NULL, 'IS NULL')
      : $query->condition('target_id', $target_id);
    $ids = $query->execute();

    if (!empty($ids)) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Deletes all annotation rows for a given annotation type ID.
   *
   * Called when an AnnotationType config entity is deleted, so no orphan
   * rows remain in the database for that type.
   */
  public function deleteForType(string $type_id): void {
    $storage = $this->entityTypeManager->getStorage('annotation');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type_id', $type_id)
      ->execute();

    if (!empty($ids)) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}
