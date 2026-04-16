<?php

declare(strict_types=1);

namespace Drupal\annotations_type_ui\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\Entity\AnnotationTypeInterface;

/**
 * Add/edit form for AnnotationType config entities.
 */
class AnnotationTypeForm extends EntityForm {

  /**
   * Title callback for the edit form route.
   */
  public static function editTitle(AnnotationTypeInterface $annotation_type): TranslatableMarkup {
    return t('Edit annotation type: %label', ['%label' => $annotation_type->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [$this->entityTypeManager->getStorage('annotation_type'), 'load'],
        'source' => ['label'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('What this annotation type is for. Shown as help text on the annotation form.'),
      '#default_value' => $entity->getDescription(),
      '#rows' => 3,
    ];

    $form['behavior'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Behavior'),
      '#after_build' => [static::class . '::hideIfEmpty'],
    ];

    return $form;
  }

  /**
   * Hides the fieldset if no children were added by any module.
   */
  public static function hideIfEmpty(array $element, FormStateInterface $form_state): array {
    if (empty(Element::children($element))) {
      $element['#access'] = FALSE;
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.annotation_type.collection'),
      '#attributes' => ['class' => ['button']],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $label = $this->entity->label();
    $op = $result === SAVED_NEW ? 'created' : 'updated';
    $this->messenger()->addStatus($this->t('Annotation type %label has been @op.', [
      '%label' => $label,
      '@op' => $op,
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
