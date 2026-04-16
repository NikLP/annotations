<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Views (requires Views module).
 *
 * Views don't have bundles. This plugin discovers enabled views and their
 * displays. It uses scope key "view__{view_id}".
 */
class ViewTarget extends TargetBase {

  protected string $entityTypeId = 'view';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Views');
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
   * Each view is its own "bundle" — no bundle concept applies here.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $result = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $id => $view) {
      $result[$id] = (string) $view->label();
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
    $views = $this->entityTypeManager->getStorage('view')->loadMultiple();

    foreach ($views as $view_id => $view) {
      $scope_key = 'view__' . $view_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $displays = [];
      foreach ($view->get('display') as $display_id => $display) {
        $displays[$display_id] = [
          'label' => $display['display_title'] ?? $display_id,
          'type' => $display['display_plugin'] ?? 'unknown',
        ];
      }

      $results[$view_id] = [
        'label' => $view->label(),
        'entity_type' => 'view',
        'bundle' => $view_id,
        'displays' => $displays,
        'fields' => [],
      ];
    }

    return $results;
  }

}
