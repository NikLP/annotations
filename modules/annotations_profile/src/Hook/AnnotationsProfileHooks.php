`<?php

declare(strict_types=1);

namespace Drupal\annotations_profile\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\annotations_overlay\Service\AnnotationsOverlayService;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Hook implementations for the annotations_profile module.
 */
class AnnotationsProfileHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly AnnotationsOverlayService $overlayService,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Injects annotation overlay triggers and dialogs into Profile fields that
   * are embedded in the user account edit and registration forms via
   * ProfileFormWidget. Standalone profile forms at /profile/{profile}/edit
   * already receive overlays from annotations_overlay without this module.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }

    $entity = $form_object->getEntity();
    if ($entity->getEntityTypeId() !== 'user') {
      return;
    }

    $visible_types = $this->overlayService->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $injected = $this->injectProfileSubformOverlays($form, $form_state, $visible_types);

    if ($injected) {
      $form['#attached']['library'][] = 'annotations_overlay/overlay';
      $form['#attributes']['class'][] = 'annotations-has-overlay';
    }
  }

  /**
   * Injects overlay triggers and dialogs into embedded profile sub-forms.
   *
   * ProfileFormWidget renders profile fields into the user form at
   * $form['{bundle}_profiles']['widget'][$delta]['entity']. The profile entity
   * is stored in form state at ['profiles', $bundle, $delta] by the widget.
   *
   * Dialogs are placed inside the profile field wrapper element so the DOM
   * structure mirrors the approach used for inline paragraph subforms.
   *
   * Field keys are prefixed with "profile__{bundle}__" to prevent collisions
   * with user-entity field names that share the same machine name.
   *
   * @param array $form
   *   The form render array, passed by reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Pre-loaded annotation types visible to the current user.
   *
   * @return bool
   *   TRUE if at least one profile sub-form received overlays.
   */
  private function injectProfileSubformOverlays(array &$form, FormStateInterface $form_state, array $visible_types): bool {
    $injected = FALSE;

    foreach (array_keys($form) as $field_name) {
      if (str_starts_with((string) $field_name, '#')) {
        continue;
      }

      if (!isset($form[$field_name]['widget']) || !is_array($form[$field_name]['widget'])) {
        continue;
      }

      foreach (array_keys($form[$field_name]['widget']) as $delta) {
        if (!is_numeric($delta)) {
          continue;
        }

        $item = &$form[$field_name]['widget'][$delta];

        // ProfileFormWidget::formElement() stamps #bundle onto the entity
        // sub-array and stores the profile entity in form state. Both must
        // be present to confirm this is a profile sub-form.
        if (!isset($item['entity']['#bundle'])) {
          continue;
        }

        $bundle = $item['entity']['#bundle'];
        $profile = $form_state->get(['profiles', $bundle, $delta]);
        if (!$profile instanceof ProfileInterface) {
          continue;
        }

        $target_id = 'profile__' . $bundle;
        $prefix = 'profile__' . $bundle . '__';

        $overlay_data = $this->overlayService->buildDialogsForTarget(
          $target_id,
          $visible_types,
          'overlay',
          [],
          $prefix,
        );

        if ($overlay_data === NULL) {
          continue;
        }

        $profile_bundle_annotations = $overlay_data['bundle_annotations'];
        $profile_fields_with_annotations = $overlay_data['fields_with_annotations'];

        if (empty($profile_bundle_annotations) && empty($profile_fields_with_annotations)) {
          continue;
        }

        if (!empty($profile_bundle_annotations)) {
          $item['entity']['annotations_bundle_trigger'] = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => [
                'annotations-overlay-trigger',
                'annotations-overlay-trigger--bundle',
                'js-annotations-overlay-trigger',
              ],
              'data-annotations-field' => $prefix . '_bundle',
              'aria-label' => (string) $this->t('About @label', ['@label' => $overlay_data['target_label']]),
              'title' => (string) $this->t('About @label', ['@label' => $overlay_data['target_label']]),
            ],
            '#value' => Markup::create('<span aria-hidden="true">i</span>'),
            '#weight' => -1000,
          ];
        }

        foreach (array_keys($profile_fields_with_annotations) as $profile_field_name) {
          if (!isset($item['entity'][$profile_field_name])) {
            continue;
          }
          $field_label = $this->overlayService->resolveFieldLabel('profile', $bundle, $profile_field_name);
          $item['entity'][$profile_field_name]['#attributes']['data-annotations-field'] = $prefix . $profile_field_name;
          $item['entity'][$profile_field_name]['annotations_overlay_trigger'] = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => ['annotations-overlay-trigger', 'js-annotations-overlay-trigger'],
              'data-annotations-field' => $prefix . $profile_field_name,
              'aria-label' => (string) $this->t('Annotation for @field', ['@field' => $field_label]),
              'title' => (string) $this->t('Annotation for @field', ['@field' => $field_label]),
            ],
            '#value' => Markup::create('<span aria-hidden="true">?</span>'),
            '#weight' => -100,
          ];
        }

        $form[$field_name]['widget']['annotations_profile_dialogs__' . $delta] = [
          '#type' => 'container',
          '#weight' => 999,
          'dialogs' => $overlay_data['dialogs'],
        ];

        $injected = TRUE;
      }
    }

    return $injected;
  }

}
