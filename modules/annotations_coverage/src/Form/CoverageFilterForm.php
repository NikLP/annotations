<?php

declare(strict_types=1);

namespace Drupal\annotations_coverage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * GET filter form for the annotation coverage page.
 */
class CoverageFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_coverage_filter_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, string> $entity_types
   * @param string $current_type
   * @param string $current_status
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $entity_types = [], string $current_type = '', string $current_status = ''): array {
    $form['#method'] = 'get';
    $form['#after_build'][] = [static::class, 'removeHiddenFields'];

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];

    $form['filters']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => ['' => $this->t('Any type')] + $entity_types,
      '#default_value' => $current_type,
    ];

    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        ''         => $this->t('Any status'),
        'complete' => $this->t('Complete'),
        'partial'  => $this->t('Partial'),
        'empty'    => $this->t('Empty'),
      ],
      '#default_value' => $current_status,
    ];

    $form['filters']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];

    return $form;
  }

  /**
   * After-build callback: removes Drupal's hidden form fields from GET forms.
   */
  public static function removeHiddenFields(array $form, FormStateInterface $form_state): array {
    unset($form['form_build_id'], $form['form_token'], $form['form_id']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET form — submission handled by the browser.
  }

}
