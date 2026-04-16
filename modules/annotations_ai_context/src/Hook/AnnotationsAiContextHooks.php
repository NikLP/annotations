<?php

declare(strict_types=1);

namespace Drupal\annotations_ai_context\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for the annotations_ai_context module.
 */
class AnnotationsAiContextHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Injects the "Include in AI context" checkbox into the annotation type form.
   */
  #[Hook('form_annotation_type_form_alter')]
  public function formAnnotationTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $entity */
    $entity = $form_state->getFormObject()->getEntity();

    $form['behavior']['in_ai_context'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include in AI context'),
      '#description' => $this->t('When enabled, annotations of this type are included in payloads assembled for AI consumption.'),
      '#default_value' => $entity->getThirdPartySetting('annotations_ai_context', 'in_ai_context', TRUE),
    ];

    $form['#entity_builders'][] = [$this, 'buildAnnotationTypeEntity'];
  }

  /**
   * Entity builder: saves the in_ai_context third-party setting on annotation types.
   */
  public function buildAnnotationTypeEntity(string $_entity_type, mixed $entity, array &$_form, FormStateInterface $form_state): void {
    $entity->setThirdPartySetting('annotations_ai_context', 'in_ai_context', (bool) $form_state->getValue('in_ai_context'));
  }

}
