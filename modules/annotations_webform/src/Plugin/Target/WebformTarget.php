<?php

declare(strict_types=1);

namespace Drupal\annotations_webform\Plugin\Target;

use Drupal\annotations\Plugin\Target\TargetBase;

/**
 * Target plugin for Webform config entities.
 *
 * Each webform is its own annotatable target using scope key
 * "webform__{webform_id}" (e.g. "webform__contact"). No field-level
 * annotations — to annotate individual form elements use WebformSubmissionTarget
 * (scope "webform_submission__{webform_id}") instead.
 */
class WebformTarget extends TargetBase {

  protected string $entityTypeId = 'webform';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Webforms');
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
   * Each webform entity is its own "bundle" — the webform entity type has no
   * bundle system of its own.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $result = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $id => $webform) {
      $result[$id] = (string) $webform->label();
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function discover(array $scopes): array {
    if (!$this->isAvailable()) {
      return [];
    }
    
    $results = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $webform_id => $webform) {
      $scope_key = 'webform__' . $webform_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }
      $results[$webform_id] = [
        'label' => (string) $webform->label(),
        'entity_type' => 'webform',
        'bundle' => $webform_id,
        'fields' => [],
      ];
    }
    return $results;
  }

}
