<?php

declare(strict_types=1);

namespace Drupal\annotations\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * General Annotations settings form.
 */
class AnnotationsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['annotations.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('annotations.settings');

    $form['ui'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User interface'),
    ];

    $form['ui']['use_accordion_single'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use exclusive accordions'),
      '#description' => $this->t('When enabled, opening an accordion panel automatically closes any other open panel in the same group.'),
      '#default_value' => $config->get('use_accordion_single'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('annotations.settings')
      ->set('use_accordion_single', (bool) $form_state->getValue('use_accordion_single'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
