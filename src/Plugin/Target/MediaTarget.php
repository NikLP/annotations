<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Media types (requires Media module).
 *
 * Fn isAvailable() returns FALSE if the media entity type is not installed,
 * so this plugin gracefully does nothing on sites without the Media module.
 */
class MediaTarget extends TargetBase {

  protected string $entityTypeId = 'media';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Media types');
  }

}
