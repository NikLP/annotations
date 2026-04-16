<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\Target;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Target plugin for Workflow states (requires core Workflows module).
 *
 * Workflows don't have bundles in the normal sense. This plugin discovers
 * all workflows and their states. It uses the scope key "workflow__{id}".
 */
class WorkflowTarget extends TargetBase {

  protected string $entityTypeId = 'workflow';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Workflows');
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
   * Each workflow is its own "bundle" — no bundle concept applies here.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $result = [];
    foreach ($this->entityTypeManager->getStorage('workflow')->loadMultiple() as $id => $workflow) {
      $result[$id] = (string) $workflow->label();
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
    $workflows = $this->entityTypeManager->getStorage('workflow')->loadMultiple();

    foreach ($workflows as $workflow_id => $workflow) {
      $scope_key = 'workflow__' . $workflow_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $type_plugin = $workflow->getTypePlugin();
      $states = [];
      foreach ($type_plugin->getStates() as $state_id => $state) {
        $states[$state_id] = [
          'label' => $state->label(),
          'published' => method_exists($state, 'isPublishedState') ? $state->isPublishedState() : NULL,
          'default_revision' => method_exists($state, 'isDefaultRevisionState') ? $state->isDefaultRevisionState() : NULL,
        ];
      }

      $transitions = [];
      foreach ($type_plugin->getTransitions() as $transition_id => $transition) {
        $transitions[$transition_id] = [
          'label' => $transition->label(),
          'from' => array_keys($transition->from()),
          'to' => $transition->to()->id(),
        ];
      }

      $results[$workflow_id] = [
        'label' => $workflow->label(),
        'entity_type' => 'workflow',
        'bundle' => $workflow_id,
        'states' => $states,
        'transitions' => $transitions,
        'fields' => [],
      ];
    }

    return $results;
  }

}
