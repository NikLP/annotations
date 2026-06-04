<?php

declare(strict_types=1);

namespace Drupal\annotations_webform\Plugin\Target;

use Drupal\annotations\Plugin\Target\TargetBase;

/**
 * Target plugin for webform_submission entities.
 *
 * Overrides GenericTarget for webform_submission. Instead of enumerating the
 * submission entity's base fields (sid, uid, created etc.), this plugin
 * enumerates the webform's own input elements as the annotatable field list.
 * Scope key: "webform_submission__{webform_id}" (e.g.
 * "webform_submission__contact"). Bundle = webform ID.
 *
 * Element labels are resolved via hook_annotations_overlay_field_label_alter
 * implemented in AnnotationsWebformHooks — the base field name fallback in
 * AnnotationsOverlayService::resolveFieldLabel() would otherwise return the
 * element machine name rather than the human-readable #title.
 *
 * Limitation: trigger injection in hook_form_alter uses isset($form[$key]),
 * so triggers only appear for top-level elements. Elements nested inside
 * containers, fieldsets, or wizard pages are silently skipped. This mirrors
 * the field_group limitation documented in annotations_overlay.
 */
class WebformSubmissionTarget extends TargetBase {

  /**
   * The target entity type ID.
   *
   * @var string
   */
  protected string $entityTypeId = 'webform_submission';

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->t('Webform submissions');
  }

  /**
   * {@inheritdoc}
   *
   * Uses the standard bundle info lookup — webform_submission declares
   * bundle_entity_type = "webform", so getBundleInfo() correctly returns
   * each webform ID as a bundle.
   */
  public function getBundles(): array {
    if (!$this->isAvailable()) {
      return [];
    }

    return array_map(
      fn($info) => (string) $info['label'],
      $this->bundleInfo->getBundleInfo($this->entityTypeId),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Enumerates webform input elements as the "fields" for each opted-in
   * webform submission target. Only elements for which isInput() is TRUE are
   * included — containers, markup, and wizard pages are excluded.
   */
  public function discover(array $scopes): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $results = [];

    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $webform_id => $webform) {
      $scope_key = 'webform_submission__' . $webform_id;
      if (!isset($scopes[$scope_key])) {
        continue;
      }

      $scope = $scopes[$scope_key];
      $elements = $webform->getElementsInitializedFlattenedAndHasValue();
      $fields = [];

      if (is_array($elements)) {
        foreach ($elements as $element_key => $element) {
          if (!$scope->isFieldIncluded((string) $element_key)) {
            continue;
          }
          // Prefer #title, then fall back to #admin_title and the element key.
          $title = $element['#title'] ?: ($element['#admin_title'] ?: $element_key);
          $fields[(string) $element_key] = [
            'label' => (string) $title,
            'type' => $element['#type'] ?? 'unknown',
            'required' => !empty($element['#required']),
          ];
        }
      }

      $results[$webform_id] = [
        'label' => (string) $webform->label(),
        'entity_type' => 'webform_submission',
        'bundle' => $webform_id,
        'fields' => $fields,
      ];
    }

    return $results;
  }

}
