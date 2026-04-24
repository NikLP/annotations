<?php

declare(strict_types=1);

namespace Drupal\annotations\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Plugin\Target\TargetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Single-page accordion form for managing annotation targets.
 *
 * Each plugin contributes an accordion section. Within each section all
 * available targets are shown as a table with a checkbox per row. Selecting a
 * row includes that target in scanning and annotation. Selected rows with
 * configurable fields show a "Configure" link.
 *
 * On save, newly selected targets become new AnnotationTarget config entities
 * with all fields pre-populated. Deselected targets with annotation data are
 * routed through a confirmation form before deletion.
 */
class TargetOverviewForm extends FormBase {

  public function __construct(
    private readonly AnnotationDiscoveryService $discoveryService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly AnnotationStorageService $annotationStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations.discovery'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('annotations.annotation_storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_target_overview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $plugins = $this->discoveryService->getPlugins();
    $existing = $this->loadExistingTargets();

    $enabled = $this->configFactory()->get('annotations.target_types')->get('enabled_target_types') ?? [];
    $plugins = array_intersect_key($plugins, array_flip($enabled));

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t(
        'Select targets for inclusion in annotation. For targets with configurable fields, select/save, then use <em>Configure</em> to choose which are included.'
      ),
    ];

    if (empty($plugins)) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No entity types have been enabled for annotation. <a href=":url">Configure entity types</a> first.', [
          ':url' => Url::fromRoute('annotations.configure')->toString(),
        ]),
      ];
      return $form;
    }

    $open_section = $this->getRequest()->query->get('open', '');
    $exclusive = (bool) $this->configFactory()->get('annotations.settings')->get('use_accordion_single');

    foreach ($plugins as $entity_type_id => $plugin) {
      if (!$plugin->isAvailable()) {
        continue;
      }

      $targets = $plugin->getBundles();
      if (empty($targets)) {
        continue;
      }

      $selected_in_group = count(array_filter(
        array_keys($targets),
        fn($key) => isset($existing[$entity_type_id . '__' . $key])
      ));

      $group_summary = $this->t('@selected of @total selected', [
        '@selected' => $selected_in_group,
        '@total' => count($targets),
      ]);

      $form[$entity_type_id] = [
        '#type' => 'details',
        '#title' => $plugin->getLabel() . ' &bull; ' . $group_summary,
        '#open' => $entity_type_id === $open_section,
        '#attributes' => $exclusive ? ['name' => 'annotations-targets'] : [],
      ];

      $form[$entity_type_id]['table'] = [
        '#type' => 'table',
        '#header' => [
          'selected' => '',
          'label' => $this->t('Target'),
          'coverage' => $this->t('Fields'),
          'operations' => ['data' => $this->t('Operations'), 'class' => ['annotations-center']],
        ],
        '#empty' => $this->t('No targets found.'),
      ];

      foreach ($targets as $target_key => $target_label) {
        $scope_key = $entity_type_id . '__' . $target_key;
        $target = $existing[$scope_key] ?? NULL;
        $is_selected = $target !== NULL;

        $operations_links = [];
        $coverage_cell = [];

        if ($is_selected && $plugin->hasFields()) {
          $selected_count = count($target->getFields());
          $omitted = $this->countAvailableFields($entity_type_id, $target_key) - $selected_count;
          $coverage_cell = [
            '#markup' => $omitted > 0
              ? $this->t('@selected selected, @omitted omitted', [
                '@selected' => $selected_count,
                '@omitted' => $omitted,
              ])
              : $this->formatPlural(
                $selected_count,
                'The field is selected',
                'All @count fields selected'
            ),
          ];
          $operations_links['configure'] = [
            'title' => $this->t('Configure'),
            'url' => Url::fromRoute(
              'annotations.target.fields',
              ['annotation_target' => $target->id()],
              ['query' => ['destination' => Url::fromRoute('entity.annotation_target.collection', [], ['query' => ['open' => $entity_type_id]])->toString()]],
            ),
          ];
        }

        // N/A only when this entity type genuinely has no fields — non-fieldable
        // types like roles, views, and menus. Unselected fieldable rows get an
        // empty cell; the operations column isn't meaningful until opted in.
        if (!empty($operations_links)) {
          $operations_cell = ['#type' => 'operations', '#links' => $operations_links];
        }
        elseif (!$plugin->hasFields()) {
          $operations_cell = ['#plain_text' => $this->t('N/A')];
        }
        else {
          $operations_cell = [];
        }

        $form[$entity_type_id]['table'][$target_key] = [
          'selected' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Include @target', ['@target' => $target_label]),
            '#title_display' => 'invisible',
            '#default_value' => (int) $is_selected,
            '#parents' => ['targets', $entity_type_id, $target_key],
          ],
          'label' => ['#markup' => $target_label],
          'coverage' => $coverage_cell,
          'operations' => $operations_cell,
        ];
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save target configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $plugins = $this->discoveryService->getPlugins();
    $existing = $this->loadExistingTargets();
    $selections = $form_state->getValue('targets', []);

    $to_create = [];
    $to_delete_safe = [];
    $to_delete_confirm = [];

    foreach ($selections as $entity_type_id => $targets) {
      foreach ($targets as $target_key => $is_selected) {
        $scope_key = $entity_type_id . '__' . $target_key;

        if ($is_selected && !isset($existing[$scope_key])) {
          $to_create[$scope_key] = [
            'entity_type' => $entity_type_id,
            'bundle' => $target_key,
            'label' => $this->deriveLabel($entity_type_id, $target_key, $plugins),
          ];
        }
        elseif (!$is_selected && isset($existing[$scope_key])) {
          $target = $existing[$scope_key];
          if ($this->annotationStorage->hasAnnotationData($scope_key)) {
            $to_delete_confirm[$scope_key] = $target;
          }
          else {
            $to_delete_safe[$scope_key] = $target;
          }
        }
      }
    }

    $storage = $this->entityTypeManager->getStorage('annotation_target');
    foreach ($to_create as $scope_key => $data) {
      $fields = $this->buildDefaultFieldsMap($data['entity_type'], $data['bundle']);
      $storage->create([
        'id' => $scope_key,
        'label' => $data['label'],
        'entity_type' => $data['entity_type'],
        'bundle' => $data['bundle'],
        'fields' => $fields,
        'status' => TRUE,
      ])->save();
    }

    foreach ($to_delete_safe as $target) {
      $target->delete();
    }

    if (!empty($to_delete_confirm)) {
      if (!empty($to_create) || !empty($to_delete_safe)) {
        $this->messenger()->addStatus(
          $this->t('Target configuration partially saved.')
        );
      }
      $form_state->setRedirectUrl(Url::fromRoute('annotations.target.delete_confirm', [], [
        'query' => ['ids' => array_keys($to_delete_confirm)],
      ]));

      return;
    }

    $this->messenger()->addStatus($this->t('Target configuration saved.'));
    $form_state->setRedirect('entity.annotation_target.collection');
  }

  /**
   * Counts the total available editorial fields for a target.
   */
  protected function countAvailableFields(
    string $entity_type_id,
    string $bundle_id,
  ): int {
    if (!$this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return 0;
    }

    $count = 0;
    $definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle_id);
    foreach ($definitions as $field_name => $definition) {
      if (
        !($definition instanceof FieldConfigInterface)
        && !in_array($field_name, TargetBase::NOTABLE_BASE_FIELDS, TRUE)
      ) {
        continue;
      }
      $count++;
    }

    return $count;
  }

  /**
   * Builds the default fields map for a newly selected target.
   *
   * Pre-populates all available editorial fields so all fields are included by
   * default. The annotator can use "Configure" to adjust the selection.
   *
   * @return array<string, array>
   *   Fields map keyed by field machine name.
   */
  protected function buildDefaultFieldsMap(
    string $entity_type_id,
    string $bundle_id,
  ): array {
    $fields = [];

    if (!$this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return $fields;
    }

    $definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle_id);
    foreach ($definitions as $field_name => $definition) {
      if (
        !($definition instanceof FieldConfigInterface)
        && !in_array($field_name, TargetBase::NOTABLE_BASE_FIELDS, TRUE)
      ) {
        continue;
      }
      $fields[$field_name] = [];
    }

    return $fields;
  }

  /**
   * Loads all AnnotationTarget entities keyed by "{entity_type}__{bundle}".
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   *   Existing targets keyed by scope key.
   */
  protected function loadExistingTargets(): array {
    $indexed = [];
    $targets = $this->entityTypeManager
      ->getStorage('annotation_target')
      ->loadMultiple();
    foreach ($targets as $target) {
      $key = $target->getTargetEntityTypeId() . '__' . $target->getBundle();
      $indexed[$key] = $target;
    }

    return $indexed;
  }

  /**
   * Derives a human-readable label for a new target.
   */
  protected function deriveLabel(
    string $entity_type_id,
    string $target_key,
    array $plugins,
  ): string {
    $plugin = $plugins[$entity_type_id] ?? NULL;
    if ($plugin) {
      $targets = $plugin->getBundles();
      if (isset($targets[$target_key])) {
        return $targets[$target_key];
      }
    }
    return $entity_type_id . '__' . $target_key;
  }

}
