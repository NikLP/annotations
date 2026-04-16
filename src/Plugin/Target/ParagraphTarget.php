<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Paragraphs types (requires Paragraphs module).
 *
 * isAvailable() returns FALSE if the paragraphs_type entity type is not
 * installed, so this plugin gracefully does nothing on sites without Paragraphs.
 */
class ParagraphTarget extends TargetBase {

  protected string $entityTypeId = 'paragraph';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Paragraph types');
  }

}
