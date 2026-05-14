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
    $form = parent::buildForm($form, $form_state);

    $form['mcp'] = [
      '#type'   => 'fieldset',
      '#title'  => $this->t('MCP API key'),
      '#weight' => 0,
    ];

    $form['mcp']['description'] = [
      '#type'   => 'item',
      '#markup' => '<p>' . $this->t('Use this key when calling the MCP API from a local client. Generate a key here, then either Save it to configuration (testing) or copy it into a file for use via the Key module (production).') . '</p>',
    ];

    $form['mcp']['mcp_api_key'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('API key'),
      '#config_target' => 'annotations_context.settings:mcp_api_key',
      '#attributes'    => ['style' => 'font-family: monospace;'],
      '#description'   => $this->t('Generated MCP API key. Clear and save to remove.'),
    ];

    $form['mcp']['generate'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Generate key'),
      '#submit'                  => ['::generateKey'],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['#weight'] = 10;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    if ($form_state->getValue(['mcp', 'mcp_api_key'])) {
      $this->messenger()->addWarning($this->t(
        'The API key is stored in site configuration. If your site\'s configuration is exported as part of a deployment process, this key will be included in those files. For production environments, use the <a href=":key_url">Key module</a> to store the key outside your codebase instead.',
        [':key_url' => 'https://www.drupal.org/project/key']
      ));
    }
  }

  /**
   * Generates a new random key and populates the field without saving.
   */
  public function generateKey(array &$form, FormStateInterface $form_state): void {
    $key = Crypt::randomBytesBase64(32);
    $input = $form_state->getUserInput();
    $input['mcp_api_key'] = $key;
    $form_state->setUserInput($input);
    $form_state->setRebuild();
  }

}
