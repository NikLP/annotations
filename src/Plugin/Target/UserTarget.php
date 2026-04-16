<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for the user account entity.
 *
 * The user entity type has a single bundle ("user"). This plugin handles
 * fields added to the user account (bio, department, profile picture, etc.).
 *
 * User roles are handled separately by RoleTarget.
 */
class UserTarget extends TargetBase {

  protected string $entityTypeId = 'user';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('User account');
  }

}
