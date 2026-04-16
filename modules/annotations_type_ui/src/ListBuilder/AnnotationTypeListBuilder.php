<?php

declare(strict_types=1);

namespace Drupal\annotations_type_ui\ListBuilder;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\annotations\AnnotationsGlyph;

/**
 * List builder for AnnotationType config entities.
 */
class AnnotationTypeListBuilder extends DraggableListBuilder {

  protected $weightKey = 'weight';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotation_type_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Label'),
      'description' => $this->t('Description'),
      'weight' => $this->t('Weight'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $entity */
    $center = ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['annotations-center']]];
    // @todo $yes/$no are unused — kept from dot_type_ui; remove or wire up
    //   when behavior columns are added to the list builder.
    $yes = $center + ['icon' => ['#theme' => 'annotations_status_icon', '#glyph' => AnnotationsGlyph::CHECK, '#label' => $this->t('Yes'), '#modifier' => 'yes']];
    $no  = $center + ['icon' => ['#theme' => 'annotations_status_icon', '#glyph' => AnnotationsGlyph::CROSS, '#label' => $this->t('No'), '#modifier' => 'no']];

    return [
      // Plain string — DraggableListBuilder::buildForm() wraps label in #markup.
      'label' => $entity->label(),
      'description' => ['#plain_text' => $entity->getDescription() ?: ''],
      'weight' => [
        '#type' => 'weight',
        '#title' => $this->t('Weight for %title', ['%title' => $entity->label()]),
        '#title_display' => 'invisible',
        '#default_value' => $entity->getWeight(),
        '#attributes' => ['class' => ['weight']],
      ],
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Annotation type order saved.'));
  }

}
