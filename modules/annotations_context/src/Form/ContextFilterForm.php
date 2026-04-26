<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations_context\ContextAssembler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter form for the context preview page.
 */
class ContextFilterForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationDiscoveryService $discoveryService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('annotations.discovery'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_context_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $options = []): array {
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('annotations_context.preview')->toString();
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'removeFormSystemFields'];
    $form['#attributes']['class'][] = 'annotations-context__filters';
    $form['#attributes']['class'][] = 'form--inline';

    $form['role'] = [
      '#type'          => 'select',
      '#title'         => $this->t('View as role'),
      '#options'       => $this->buildRoleOptions(),
      '#default_value' => $options['role'] ?? '',
    ];

    $form['target_id'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Target'),
      '#options'       => $this->buildTargetOptions(),
      '#default_value' => isset($options['entity_type'])
        ? 'et:' . $options['entity_type']
        : ($options['target_id'] ?? ''),
    ];

    $form['ref_depth'] = [
      '#type'          => 'select',
      '#title'         => $this->t('References'),
      '#options'       => [
        0 => $this->t('None'),
        1 => $this->t('One hop'),
        2 => $this->t('Two hops'),
      ],
      '#default_value' => $options['ref_depth'] ?? ContextAssembler::DEFAULT_REF_DEPTH,
    ];

    $form['include_field_meta'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Include metadata'),
      '#default_value' => !empty($options['include_field_meta']),
      '#return_value'  => '1',
    ];

    $form['actions'] = [
      '#type'   => 'actions',
      'submit'  => [
        '#type'  => 'submit',
        '#value' => $this->t('Filter'),
      ],
      'clear'   => [
        '#type'       => 'link',
        '#title'      => $this->t('Clear'),
        '#url'        => Url::fromRoute('annotations_context.preview'),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * After-build callback that strips Drupal system fields from GET form URLs.
   */
  public static function removeFormSystemFields(array $form, FormStateInterface $form_state): array {
    unset($form['form_build_id'], $form['form_token'], $form['form_id']);

    return $form;
  }

  /**
   * Builds role select options, excluding the administrator role.
   */
  private function buildRoleOptions(): array {
    $options = ['' => $this->t('All roles (no filter)')];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if (!$role->isAdmin()) {
        $options[$role->id()] = $role->label();
      }
    }

    return $options;
  }

  /**
   * Builds target select options grouped by entity type.
   */
  private function buildTargetOptions(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface[] $all_targets */
    $all_targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    uasort($all_targets, fn($a, $b) =>
      $a->getTargetEntityTypeId() <=> $b->getTargetEntityTypeId()
      ?: strcmp((string) $a->label(), (string) $b->label())
    );

    $plugins = $this->discoveryService->getPlugins();
    $options = ['' => $this->t('All targets')];
    $grouped = [];

    foreach ($all_targets as $target) {
      $grouped[$target->getTargetEntityTypeId()][] = $target;
    }

    foreach ($grouped as $et_id => $targets) {
      $plugin                  = $plugins[$et_id] ?? NULL;
      $group_label             = $plugin ? (string) $plugin->getLabel() : ucfirst(str_replace('_', ' ', $et_id));
      $options['et:' . $et_id] = $this->t('All @label', ['@label' => $group_label]);
      $options[$group_label]   = [];
      foreach ($targets as $target) {
        $options[$group_label][$target->id()] = (string) $target->label();
      }
    }
    
    return $options;
  }

}
