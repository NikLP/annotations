<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[FieldFormatter(
  id: 'annotations_overlay',
  label: new TranslatableMarkup('Annotations overlay'),
  field_types: ['annotations_overlay'],
)]
class AnnotationsOverlayFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_display.repository'),
    );
  }

  public static function defaultSettings(): array {
    return ['annotation_view_mode' => 'overlay'] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::settingsForm($form, $form_state);

    $view_modes = $this->entityDisplayRepository->getViewModes('annotation');
    $options = ['overlay' => $this->t('Overlay (default)')];
    foreach ($view_modes as $id => $info) {
      $options[$id] = $info['label'];
    }

    $element['annotation_view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $options,
      '#default_value' => $this->getSetting('annotation_view_mode'),
    ];

    return $element;
  }

  public function settingsSummary(): array {
    return [$this->t('View mode: @mode', ['@mode' => $this->getSetting('annotation_view_mode')])];
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    // Rendering is handled by hook_entity_view_alter which reads this field's
    // display component settings. This formatter exists to surface the
    // annotation_view_mode setting in the Manage Display UI.
    return [];
  }

}
