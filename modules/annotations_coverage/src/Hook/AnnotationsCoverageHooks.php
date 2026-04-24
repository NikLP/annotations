<?php

declare(strict_types=1);

namespace Drupal\annotations_coverage\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for the annotations_coverage module.
 */
class AnnotationsCoverageHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Registers the annotations_coverage_gap_section theme hook.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'annotations_coverage_gap_section' => [
        'variables' => [
          'heading' => NULL,
          'visually_hidden_prefix' => NULL,
          'items' => [],
          'modifier' => '',
        ],
      ],
    ];
  }

  /**
   * Injects the "Affects coverage status" checkbox into annotation type form.
   */
  #[Hook('form_annotation_type_form_alter')]
  public function formAnnotationTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $entity */
    $entity = $form_state->getFormObject()->getEntity();

    $form['behavior']['affects_coverage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Affects coverage status'),
      '#description' => $this->t('When enabled, targets missing an annotation of this type are flagged in coverage reports and counted against the coverage score.'),
      '#default_value' => $entity->getThirdPartySetting('annotations_coverage', 'affects_coverage', FALSE),
    ];

    $form['#entity_builders'][] = [$this, 'buildAnnotationTypeEntity'];
  }

  /**
   * Entity builder: saves the affects_coverage third-party setting.
   */
  public function buildAnnotationTypeEntity(string $_entity_type, mixed $entity, array &$_form, FormStateInterface $form_state): void {
    $entity->setThirdPartySetting('annotations_coverage', 'affects_coverage', (bool) $form_state->getValue('affects_coverage'));
  }

}
