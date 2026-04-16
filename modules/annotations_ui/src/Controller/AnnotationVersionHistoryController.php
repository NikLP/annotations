<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Controller;

use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\Controller\VersionHistoryController;

/**
 * Revision history controller for annotation entities.
 *
 * Extends core's VersionHistoryController to add "Compare with previous"
 * operation links via the diff module's revisions_diff route. Preloads
 * revision IDs in revisionOverview() so getOperationLinks() can resolve
 * the preceding revision without additional queries per row.
 *
 * The diff route (entity.annotation.revisions_diff) is registered by
 * DiffRouteProvider via AnnotationsUiHooks::entityTypeAlter() when the diff
 * module is present. If diff is not installed the operation links fall back
 * to core behaviour (Revert / Delete only).
 */
class AnnotationVersionHistoryController extends VersionHistoryController {

  /**
   * Revision IDs for the current entity, sorted DESC (newest first).
   *
   * Populated in revisionOverview() so getOperationLinks() can look up
   * adjacent revisions without an extra query per row.
   */
  private array $revisionIds = [];

  /**
   * {@inheritdoc}
   *
   * Preloads revision IDs before delegating to the parent so that
   * getOperationLinks() can determine the preceding revision for diff links.
   */
  protected function revisionOverview(RevisionableInterface $entity): array {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    assert($storage instanceof RevisionableStorageInterface);
    $entityType = $entity->getEntityType();

    $result = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition($entityType->getKey('id'), $entity->id())
      ->sort($entityType->getKey('revision'), 'DESC')
      ->execute();

    $this->revisionIds = array_map('intval', array_keys($result));

    return parent::revisionOverview($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Adds a "Compare with previous" link when the diff module is available and
   * a preceding revision exists.
   */
  protected function getOperationLinks(RevisionableInterface $revision): array {
    $links = parent::getOperationLinks($revision);

    if (!$this->moduleHandler()->moduleExists('diff')) {
      return $links;
    }

    $revId = $revision->getRevisionId();
    $pos = array_search((int) $revId, $this->revisionIds);

    if ($pos === FALSE || !isset($this->revisionIds[$pos + 1])) {
      return $links;
    }

    $url = Url::fromRoute('entity.annotation.revisions_diff', [
      'annotation' => $revision->id(),
      'left_revision' => $this->revisionIds[$pos + 1],
      'right_revision' => $revId,
      'filter' => 'split_fields',
    ]);

    if ($url->access()) {
      $links['diff'] = [
        'title' => $this->t('Compare with previous'),
        'url' => $url,
      ];
    }

    return $links;
  }

}
