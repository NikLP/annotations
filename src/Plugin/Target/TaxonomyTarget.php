<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Taxonomy terms (vocabularies).
 */
class TaxonomyTarget extends TargetBase {

  protected string $entityTypeId = 'taxonomy_term';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Taxonomy vocabularies');
  }

}
