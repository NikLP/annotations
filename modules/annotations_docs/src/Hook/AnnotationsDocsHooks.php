<?php

declare(strict_types=1);

namespace Drupal\annotations_docs\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for annotations_docs.
 */
class AnnotationsDocsHooks {

  /**
   * Implements hook_theme()
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_documents' => [
        'variables' => [
          'nav' => [],
          'main' => [],
        ],
      ],
    ];
  }

  /**
   * Grants view access to annotations_document nodes via the permission.
   *
   * This allows the permission to cover both the browser UI and direct node
   * URLs, regardless of node publish status.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, string $op, AccountInterface $account): AccessResult {
    if ($node->bundle() === 'annotations_document' && $op === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'access annotation documents');
    }
    return AccessResult::neutral();
  }

  /**
   * Blocks manual node creation for annotations_document.
   *
   * Documents are always AI-generated via DocumentGeneratorService. Denying
   * create access removes the type from the "Add content" menu and blocks
   * /node/add/annotations_document directly.
   */
  #[Hook('entity_create_access')]
  public function entityCreateAccess(AccountInterface $account, array $context, string $entity_bundle): AccessResult {
    if ($context['entity_type_id'] === 'node' && $entity_bundle === 'annotations_document') {
      return AccessResult::forbidden();
    }
    return AccessResult::neutral();
  }

}
