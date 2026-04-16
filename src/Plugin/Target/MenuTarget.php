<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

/**
 * Target plugin for Drupal menus.
 *
 * Each menu is its own annotatable target using the scope key
 * "menu__{menu_id}" (e.g. "menu__main", "menu__footer").
 *
 * Menus are config entities and are not fieldable — the fields array is
 * always empty. The annotation value describes the menu's purpose and the
 * kinds of links it contains.
 *
 * All menus are exposed, including Drupal's built-in ones (main, admin,
 * footer, tools, account). Which menus are actually brought into scope is
 * the agency's decision via the Targets page.
 */
class MenuTarget extends TargetBase {

  protected string $entityTypeId = 'menu';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Menus');
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
   * Each menu entity is its own target. menu has no bundles in the Drupal
   * sense — individual menu entities are the targets.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $result = [];
    foreach ($this->entityTypeManager->getStorage('menu')->loadMultiple() as $id => $menu) {
      $result[$id] = (string) $menu->label();
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
    foreach ($this->entityTypeManager->getStorage('menu')->loadMultiple() as $menu_id => $menu) {
      $scope_key = 'menu__' . $menu_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $results[$menu_id] = [
        'label' => (string) $menu->label(),
        'entity_type' => 'menu',
        'bundle' => $menu_id,
        'fields' => [],
      ];
    }

    return $results;
  }

}
