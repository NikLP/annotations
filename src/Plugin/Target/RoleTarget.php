<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

use Drupal\Core\Session\AccountInterface;

/**
 * Target plugin for user roles.
 *
 * Each role is its own annotatable target using scope key
 * "user_role__{role_id}" (e.g. "user_role__administrator").
 *
 * Roles don't have entity fields — the fields array is always empty.
 * The annotation value is the role itself: what it can do, what workflows
 * it can trigger, what content it can access.
 */
class RoleTarget extends TargetBase {

  protected string $entityTypeId = 'user_role';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('User roles');
  }

  /**
   * {@inheritdoc}
   */
  public function hasFields(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Each role is its own target. user_role has no bundles in the Drupal
   * sense — individual role entities are the targets.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    
    $result = [];
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $id => $role) {
      if (in_array($id, [AccountInterface::ANONYMOUS_ROLE, AccountInterface::AUTHENTICATED_ROLE], TRUE) || $role->isAdmin()) {
        continue;
      }
      $result[$id] = (string) $role->label();
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * Iterates roles. Each opted-in role produces one result entry containing
   * the role label, admin flag, and permissions list. No fields apply.
   */
  public function discover(array $scopes): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $results = [];
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $role_id => $role) {
      $scope_key = 'user_role__' . $role_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $results[$role_id] = [
        'label' => $role->label(),
        'entity_type' => 'user_role',
        'bundle' => $role_id,
        'is_admin' => $role->isAdmin(),
        'permissions' => $role->getPermissions(),
        'fields' => [],
      ];
    }

    return $results;
  }

}
