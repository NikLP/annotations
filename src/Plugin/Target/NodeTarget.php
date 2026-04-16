<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Node (content types).
 */
class NodeTarget extends TargetBase {

  protected string $entityTypeId = 'node';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Content types');
  }

}
