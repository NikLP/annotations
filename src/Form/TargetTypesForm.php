<?php

declare(strict_types=1);

namespace Drupal\annotations\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\annotations\DiscoveryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Opts entity types in to Annotations scanning and annotation.
 *
 * Only opted-in entity types appear on the Targets page. Entity types that
 * are enabled here but have no available bundles are still hidden from Targets
 * (the discovery plugin's isAvailable() / getBundles() checks still apply).
 *
 * Entity types cannot be disabled while annotation_target config entities
 * exist for them. Delete all targets for the entity type first.
 */
class TargetTypesForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly DiscoveryService $discoveryService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('annotations.discovery'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_target_types';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['annotations.target_types'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('annotations.target_types');
    $enabled = $config->get('enabled_target_types') ?? [];

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Select which entity types will appear as targets for annotation.'),
    ];

    $options = [];
    foreach ($this->discoveryService->getPlugins() as $entity_type_id => $plugin) {
      if ($plugin->isAvailable()) {
        $options[$entity_type_id] = $plugin->getLabel();
      }
    }
    asort($options);

    $form['enabled_target_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled entity types'),
      '#options' => $options,
      '#default_value' => $enabled,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $previously_enabled = $this->config('annotations.target_types')->get('enabled_target_types') ?? [];
    $selected = array_keys(array_filter($form_state->getValue('enabled_target_types')));
    $removed = array_diff($previously_enabled, $selected);

    if (empty($removed)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('annotation_target');
    foreach ($removed as $entity_type_id) {
      $targets = $storage->loadByProperties(['entity_type' => $entity_type_id]);
      if (!empty($targets)) {
        $form_state->setError(
          $form['enabled_target_types'],
          $this->t('Cannot disable %type: target configuration exists. Remove all targets for this entity type first.', [
            '%type' => $entity_type_id,
          ])
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_keys(array_filter($form_state->getValue('enabled_target_types')));

    $this->config('annotations.target_types')
      ->set('enabled_target_types', $selected)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
