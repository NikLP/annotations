<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Manages the MCP API key for the annotations context endpoint.
 */
class McpSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['annotations_context.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_context_mcp_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $key = $this->config('annotations_context.settings')->get('mcp_api_key') ?? '';

    $form['mcp'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('MCP API key'),
    ];

    $form['mcp']['description'] = [
      '#type'   => 'item',
      '#markup' => '<p>' . $this->t('Use this key in the Authorization: Bearer header when calling POST /api/annotations/mcp from a local MCP client.') . '</p>',
    ];

    if ($key !== '') {
      $form['mcp']['key_display'] = [
        '#type'        => 'textfield',
        '#title'       => $this->t('Current key'),
        '#value'       => $key,
        '#attributes'  => ['readonly' => 'readonly', 'style' => 'font-family: monospace;'],
        '#description' => $this->t('Copy this value into your MCP client configuration.'),
      ];

      $form['mcp']['regenerate'] = [
        '#type'                    => 'submit',
        '#value'                   => $this->t('Regenerate key'),
        '#submit'                  => ['::generateKey'],
        '#limit_validation_errors' => [],
        '#button_type'             => 'danger',
      ];
    }
    else {
      $form['mcp']['no_key'] = [
        '#markup' => '<p><em>' . $this->t('No API key has been generated yet.') . '</em></p>',
      ];

      $form['mcp']['generate'] = [
        '#type'                    => 'submit',
        '#value'                   => $this->t('Generate key'),
        '#submit'                  => ['::generateKey'],
        '#limit_validation_errors' => [],
      ];
    }

    // No parent::buildForm() — there is no general "Save" action on this form.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intentionally empty — the only action is generateKey().
  }

  /**
   * Generates (or regenerates) the MCP API key and saves it to config.
   */
  public function generateKey(array &$form, FormStateInterface $form_state): void {
    $key = Crypt::randomBytesBase64(32);
    $this->config('annotations_context.settings')
      ->set('mcp_api_key', $key)
      ->save();

    $this->messenger()->addStatus($this->t('A new MCP API key has been generated. Copy it now — this page will show it in full until you regenerate.'));
  }

}
