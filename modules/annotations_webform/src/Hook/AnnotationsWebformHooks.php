<?php

declare(strict_types=1);

namespace Drupal\annotations_webform\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the annotations_webform module.
 */
class AnnotationsWebformHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

    $webform = $this->entityTypeManager->getStorage('webform')->load($bundle);
    if ($webform === NULL) {
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

}
