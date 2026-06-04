<?php

declare(strict_types=1);

namespace Drupal\annotations\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for annotation content entities.
 *
 * Drupal maps entity operations as follows:
 *   view   → canonical / revision view
 *   update → edit-form
 *   delete → delete-form / delete-multiple-form.
 *
 * Revision-specific operations (revert, delete revision, view all revisions,
 * view revision) are handled by hook_entity_access in AnnotationsUiHooks
 * because EntityAccessControlHandler::checkAccess() only receives the four
 * standard CRUD operations.
 */
class AnnotationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer annotations')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $bundle = $entity->bundle();

    return match ($operation) {
      'update' => AccessResult::allowedIfHasPermission($account, 'edit any annotation')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'edit ' . $bundle . ' annotations'))
        ->cachePerPermissions(),

      'delete' => AccessResult::allowedIfHasPermission($account, 'delete any annotation')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'delete ' . $bundle . ' annotations'))
        ->cachePerPermissions(),

      'view' => AccessResult::allowedIfHasPermission($account, 'edit any annotation')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'edit ' . $bundle . ' annotations'))
        ->orIf(AccessResult::allowedIfHasPermission($account, 'delete any annotation'))
        ->orIf(AccessResult::allowedIfHasPermission($account, 'delete ' . $bundle . ' annotations'))
        ->cachePerPermissions(),

      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    $result = AccessResult::allowedIfHasPermission($account, 'administer annotations')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'edit any annotation'));

    if ($entity_bundle !== NULL) {
      $result = $result->orIf(AccessResult::allowedIfHasPermission($account, 'edit ' . $entity_bundle . ' annotations'));
    }

    return $result->cachePerPermissions();
  }

}
