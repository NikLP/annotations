<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for the annotations_ui module.
 */
class AnnotationsUiHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Injects the two UI-specific settings into the core annotations settings
   * form. The settings are owned by annotations_ui (annotations_ui.settings)
   * so that disabling this module cleanly removes them; the form itself lives
   * in annotations so the admin URL and task tabs are unchanged.
   */
  #[Hook('form_annotations_settings_alter')]
  public function formAnnotationsSettingsAlter(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->get('annotations_ui.settings');

    $form['ui']['show_target_details'] = [
      '#type' => 'checkbox',
      '#title' => t('Show target details on annotation pages'),
      '#description' => t('When enabled, a collapsible <em>Target details</em> panel is shown at the top of each <em>Add annotations</em> page, listing fields and their inclusion status. Useful for initial site development.'),
      '#default_value' => $config->get('show_target_details'),
    ];

    $form['ui']['show_field_metadata'] = [
      '#type' => 'checkbox',
      '#title' => t('Show target metadata on annotation forms'),
      '#description' => t('When enabled, a collapsible <em>Metadata</em> panel is shown at the top of annotation view and edit forms, showing field type, cardinality, allowed values, etc.'),
      '#default_value' => $config->get('show_field_metadata'),
    ];

    $form['#submit'][] = [$this, 'submitAnnotationsSettings'];
  }

  /**
   * Submit handler added by formAnnotationsSettingsAlter().
   */
  public function submitAnnotationsSettings(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('annotations_ui.settings')
      ->set('show_target_details', (bool) $form_state->getValue('show_target_details'))
      ->set('show_field_metadata', (bool) $form_state->getValue('show_field_metadata'))
      ->save();
  }

  // Adds routes to list that qualifies for gin sidebar.
  #[Hook('gin_content_form_routes')]
  public function ginContentFormRoutes(): array {
    return [
      'entity.annotation.edit_form',
      'entity.annotation.canonical',
      'annotations_ui.target.create',
    ];
  }

  /**
   * Implements hook_views_data_alter().
   *
   * Registers the annotation_moderation_state field plugin on the
   * annotation_field_data table. This replaces content_moderation's
   * moderation_state_field handler (when present) so that our plugin handles
   * the Status column, and also ensures the field exists when
   * annotations_workflows is not installed (since content_moderation would not
   * register it at all).
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['annotation_field_data']['moderation_state'])) {
      $data['annotation_field_data']['moderation_state'] = [
        'title' => t('Status'),
      ];
    }
    $data['annotation_field_data']['moderation_state']['field'] = [
      'id' => 'annotation_moderation_state',
      'field_name' => 'moderation_state',
    ];
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * Registers edit/delete form handlers and link templates on annotation
   * so the entity follows standard Drupal content entity conventions (Views
   * operation links, toUrl(), etc.) without creating a dependency from
   * annotations on annotations_ui.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['annotation'])) {
      $entity_types['annotation']
        ->setFormClass('edit', 'Drupal\annotations_ui\Form\AnnotationEditForm')
        ->setFormClass('delete', 'Drupal\annotations_ui\Form\AnnotationDeleteForm')
        ->setFormClass('revision-revert', RevisionRevertForm::class)
        ->setFormClass('revision-delete', RevisionDeleteForm::class)
        ->setLinkTemplate('canonical', '/admin/content/annotations/value/{annotation}')
        ->setLinkTemplate('edit-form', '/admin/content/annotations/value/{annotation}/edit')
        ->setLinkTemplate('delete-form', '/admin/content/annotations/value/{annotation}/delete')
        ->setLinkTemplate('version-history', '/admin/content/annotations/value/{annotation}/revisions')
        ->setLinkTemplate('revision', '/admin/content/annotations/value/{annotation}/revisions/{annotation_revision}/view')
        ->setLinkTemplate('revision-revert-form', '/admin/content/annotations/value/{annotation}/revisions/{annotation_revision}/revert')
        ->setLinkTemplate('revision-delete-form', '/admin/content/annotations/value/{annotation}/revisions/{annotation_revision}/delete')
        ->setLinkTemplate('delete-multiple-form', '/admin/content/annotations/delete')
        ->setHandlerClass('route_provider', [
          'html' => AdminHtmlRouteProvider::class,
          'revision' => RevisionHtmlRouteProvider::class,
        ] + ($entity_types['annotation']->getHandlerClasses()['route_provider'] ?? []));

      // Register the diff module's route provider and link template when
      // the diff module is present. DiffRouteProvider generates
      // entity.annotation.revisions_diff from the revisions-diff template.
      // AnnotationVersionHistoryController + AnnotationsUiRouteSubscriber then
      // add "Compare with previous" links on the revision history page.
      if (class_exists('Drupal\diff\Routing\DiffRouteProvider')) {
        $entity_types['annotation']
          ->setLinkTemplate('revisions-diff', '/admin/content/annotations/value/{annotation}/revisions/{left_revision}/{right_revision}/{filter}')
          ->setHandlerClass('route_provider', [
            'diff' => 'Drupal\diff\Routing\DiffRouteProvider',
          ] + ($entity_types['annotation']->getHandlerClasses()['route_provider'] ?? []));
      }
    }
  }

  /**
   * Implements hook_entity_delete().
   *
   * Works around a core content_moderation bug: EntityOperations::entityDelete()
   * calls loadFromModeratedEntity() which queries by content_entity_revision_id
   * (the currently loaded revision only). Because each new moderated-entity
   * revision creates a *new* content_moderation_state entity rather than a new
   * revision of the existing one, only the current revision's record is found and
   * deleted — every other revision's record is left as an orphan. On a dev site
   * that is reinstalled frequently this eventually causes a duplicate-key error
   * when the annotation revision auto_increment cycles back through an ID
   * that still has an orphan row in content_moderation_state.
   *
   * Because annotations_ui (a) sorts before content_moderation (c), our hook
   * runs BEFORE EntityOperations::entityDelete(). We delete all
   * content_moderation_state records for this annotation entity ID
   * preemptively; content_moderation's subsequent query finds nothing and exits
   * silently. End result is identical — all orphans removed — just with our
   * hook doing all the work instead of sharing it.
   *
   * @see \Drupal\content_moderation\EntityOperations::entityDelete()
   * @todo Remove if core fixes EntityOperations::entityDelete() to query by
   *   content_entity_id rather than content_entity_revision_id.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'annotation') {
      return;
    }
    $etm = \Drupal::entityTypeManager();
    if (!$etm->hasDefinition('content_moderation_state')) {
      return;
    }
    $storage = $etm->getStorage('content_moderation_state');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'annotation')
      ->condition('content_entity_id', $entity->id())
      ->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Implements hook_entity_access().
   *
   * - view / update / delete / revert / delete revision: requires 'edit any annotation'.
   * - view all revisions / view revision: requires 'view annotation revisions'
   *   OR 'edit any annotation' (the latter already implies full access).
   */
  #[Hook('entity_access')]
  public function entityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult {
    if ($entity->getEntityTypeId() !== 'annotation') {
      return AccessResult::neutral();
    }
    if (in_array($operation, ['view', 'update', 'delete', 'revert', 'delete revision'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'edit any annotation')
        ->cachePerPermissions();
    }
    if (in_array($operation, ['view all revisions', 'view revision'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'view annotation revisions')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'edit any annotation'))
        ->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

}
