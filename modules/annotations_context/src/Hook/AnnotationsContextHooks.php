<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for the annotations_context module.
 */
class AnnotationsContextHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_form_annotation_type_form_alter().
   *
   * Adds the 'in_ai_context' checkbox to the annotation type behavior fieldset.
   * The setting controls whether this type's annotations are included when
   * assembling context for AI/MCP consumers.
   */
  #[Hook('form_annotation_type_form_alter')]
  public function formAnnotationTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $type */
    $type = $form_state->getFormObject()->getEntity();

    $form['behavior']['in_ai_context'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Include in AI contexts'),
      '#description'   => $this->t('When enabled, annotations of this type are included when assembling context for AI consumers.'),
      '#default_value' => $type->getThirdPartySetting('annotations_context', 'in_ai_context', FALSE),
    ];

    $form['#entity_builders'][] = [static::class, 'buildAnnotationTypeEntity'];
  }

  /**
   * Entity builder: saves the in_ai_context third-party setting.
   */
  public static function buildAnnotationTypeEntity(string $entity_type, $entity, array &$form, FormStateInterface $form_state): void {
    $entity->setThirdPartySetting('annotations_context', 'in_ai_context', (bool) $form_state->getValue('in_ai_context'));
  }

}
