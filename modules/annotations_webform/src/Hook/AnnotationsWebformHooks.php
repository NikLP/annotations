<?php

declare(strict_types=1);

namespace Drupal\annotations_webform\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\annotations_overlay\Service\AnnotationsOverlayService;
use Drupal\webform\WebformInterface;

/**
 * Hook implementations for the annotations_webform module.
 */
class AnnotationsWebformHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationsOverlayService $overlayService,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Implements hook_annotations_overlay_field_label_alter().
   *
   * Replaces the machine-name fallback with the webform element's #title for
   * webform_submission targets. Without this, resolveFieldLabel() falls back
   * to the element key (e.g. "first_name") because webform elements have no
   * field_config entity.
   *
   * Uses #title (what the user sees on the form) in preference to #admin_title
   * (an internal label) so the overlay dialog heading matches the visible label.
   */
  #[Hook('annotations_overlay_field_label_alter')]
  public function annotationsOverlayFieldLabelAlter(string &$label, array $context): void {
    if (($context['entity_type_id'] ?? '') !== 'webform_submission') {
      return;
    }

    $bundle = $context['bundle'] ?? '';
    $field_name = $context['field_name'] ?? '';

    /** @var \Drupal\webform\WebformInterface|null $webform */
    $webform = $this->entityTypeManager->getStorage('webform')->load($bundle);
    if (!$webform instanceof WebformInterface) {
      return;
    }

    $elements = $webform->getElementsInitializedFlattenedAndHasValue();
    if (!is_array($elements) || !isset($elements[$field_name])) {
      return;
    }

    $element = $elements[$field_name];
    $title = $element['#title'] ?: ($element['#admin_title'] ?: NULL);
    if ($title !== NULL) {
      $label = strip_tags((string) $title);
    }
  }

  /**
   * Implements hook_form_alter().
   *
   * Injects annotation overlay triggers into webform submission forms.
   * WebformSubmissionForm places elements at $form['elements'][$key] rather
   * than $form[$key], so annotations_overlay's form_alter skips all trigger
   * injection. This hook runs after (module name is alphabetically later) and
   * wraps each matched element in a <div data-annotations-field="..."> via
   * #prefix/#suffix so the trigger button is a DOM child of the positioning
   * context. A child render element on #type => textfield is silently dropped
   * by Drupal's element renderer, so the wrapper approach is required.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $_form_id): void {
    if (!isset($form['annotations_overlay_dialogs'])) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }

    $entity = $form_object->getEntity();
    if ($entity->getEntityTypeId() !== 'webform_submission') {
      return;
    }

    $visible_types = $this->overlayService->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $bundle = $entity->bundle();
    $overlay_data = $this->overlayService->buildDialogsForTarget(
      'webform_submission__' . $bundle,
      $visible_types,
    );
    if ($overlay_data === NULL || empty($overlay_data['fields_with_annotations'])) {
      return;
    }

    foreach (array_keys($overlay_data['fields_with_annotations']) as $field_name) {
      if (!isset($form['elements'][$field_name])) {
        continue;
      }
      $field_label = $this->overlayService->resolveFieldLabel('webform_submission', $bundle, $field_name);
      $aria_label = (string) $this->t('Annotation for @field', ['@field' => $field_label]);

      $trigger_build = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['annotations-overlay-trigger', 'js-annotations-overlay-trigger'],
          'data-annotations-field' => $field_name,
          'aria-label' => $aria_label,
          'title' => $aria_label,
        ],
        '#value' => Markup::create('<span aria-hidden="true">?</span>'),
      ];

      $wrapper_attributes = new Attribute([
        'class' => ['annotations-field-wrapper'],
        'data-annotations-field' => $field_name,
      ]);

      $existing_prefix = isset($form['elements'][$field_name]['#prefix'])
        ? (string) $form['elements'][$field_name]['#prefix']
        : '';
      $existing_suffix = isset($form['elements'][$field_name]['#suffix'])
        ? (string) $form['elements'][$field_name]['#suffix']
        : '';

      $form['elements'][$field_name]['#prefix'] = Markup::create('<div' . $wrapper_attributes . '>' . $existing_prefix);
      $form['elements'][$field_name]['#suffix'] = Markup::create(
        $existing_suffix . $this->renderer->renderInIsolation($trigger_build) . '</div>'
      );
    }
  }

}
