<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\annotations\AnnotationsGlyph;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Entity\AnnotationTarget;
use Drupal\annotations\Plugin\Target\TargetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the annotation landing and add-new pages.
 *
 * Landing page: lists all opted-in annotation_target entities grouped by
 * entity type, each with a dropbutton for Add / Edit / Delete. Accessible
 * to users with 'access annotation overview' without requiring
 * 'administer annotations'.
 *
 * Add page: shows a table of annotation slots that have no content yet for a
 * given target, with inline Add links per missing type per field. When all
 * slots are filled a link to the edit view is shown instead.
 *
 * Create form: returns AnnotationEditForm pre-populated for a new entity so
 * the user can write the annotation text and choose a moderation state.
 */
class AnnotationController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationDiscoveryService $discoveryService,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly AnnotationStorageService $annotationStorage,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('annotations.discovery'),
      $container->get('entity_field.manager'),
      $container->get('annotations.annotation_storage'),
    );
  }

  /**
   * Builds the annotate landing page.
   */
  public function page(Request $request): array {
    $open_section = $request->query->get('open', '');

    $targets = $this->entityTypeManager
      ->getStorage('annotation_target')
      ->loadMultiple();

    if (empty($targets)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No targets have been selected yet.'),
      ];
    }

    $plugins = $this->discoveryService->getPlugins();

    $groups = [];
    foreach ($targets as $target) {
      $groups[$target->getTargetEntityTypeId()][] = $target;
    }
    foreach ($groups as &$group) {
      uasort($group, fn($a, $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));
    }
    unset($group);

    $type_labels = [];
    foreach (array_keys($groups) as $type_id) {
      if (isset($plugins[$type_id])) {
        $type_labels[$type_id] = $plugins[$type_id]->getLabel();
      }
      else {
        $def = $this->entityTypeManager->getDefinition($type_id, FALSE);
        $type_labels[$type_id] = $def ? (string) $def->getLabel() : $type_id;
      }
    }
    asort($type_labels);

    $exclusive = (bool) $this->config('annotations.settings')->get('use_accordion_single');

    $build = [];
    foreach ($type_labels as $type_id => $type_label) {
      $rows = [];
      foreach ($groups[$type_id] as $target) {
        $rows[] = [
          ['data' => ['#plain_text' => $target->label()]],
          [
            'data' => [
              '#type' => 'operations',
              '#links' => [
                'add' => [
                  'title' => $this->t('Add new annotations'),
                  'url' => Url::fromRoute('annotations_ui.target.add', [
                    'annotation_target' => $target->id(),
                  ]),
                ],
                'edit' => [
                  'title' => $this->t('Edit existing annotations'),
                  'url' => Url::fromRoute('annotations_ui.target.collection', [
                    'annotation_target' => $target->id(),
                  ]),
                ],
                'delete_all' => [
                  'title' => $this->t('Delete annotations'),
                  'url' => Url::fromRoute('annotations_ui.target.delete_all', [
                    'annotation_target' => $target->id(),
                  ]),
                ],
              ],
            ],
          ],
        ];
      }

      $build[$type_id] = [
        '#type' => 'details',
        '#title' => $type_label,
        '#open' => $type_id === $open_section,
        '#attributes' => $exclusive ? ['name' => 'annotations-annotate'] : [],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Target'),
            $this->t('Operations'),
          ],
          '#rows' => $rows,
        ],
      ];
    }

    return $build;
  }

  /**
   * Embeds the annotations_target view for a single target.
   */
  public function collectionPage(AnnotationTarget $annotation_target): array {
    return views_embed_view('annotations_target', 'embed_1', $annotation_target->id()) ?? [];
  }

  /**
   * Title callback for the per-target collection page.
   */
  public function collectionTitle(AnnotationTarget $annotation_target): TranslatableMarkup {
    return $this->t('Annotations for %label', ['%label' => $annotation_target->label()]);
  }

  /**
   * Builds the add-new-annotations page for a target.
   *
   * Renders a table of fields/overview with inline Add links for each
   * annotation type that has no content yet. When all slots are filled a
   * message with a link to the edit view is shown instead.
   */
  public function addPage(AnnotationTarget $annotation_target, Request $request): array {
    $types = $this->loadAnnotationTypes();

    if (empty($types)) {
      if ($this->moduleHandler()->moduleExists('annotations_type_ui')) {
        $link = Url::fromRoute('entity.annotation_type.add_form', [], [
          'query' => ['destination' => $request->getPathInfo()],
        ])->toString();
        $message = $this->t('No annotation types are available. <a href=":url">Add an annotation type</a> to get started.', [':url' => $link]);
      }
      else {
        $message = $this->t('No annotation types are available. Install the <em>annotations_type_ui</em> module to create annotation types.');
      }
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $message,
      ];
    }

    // Use latest revisions so in-progress drafts count as filled.
    $existing = $this->annotationStorage->getLatestForTarget($annotation_target->id());
    $field_info = $this->getFieldInfo($annotation_target->getTargetEntityTypeId(), $annotation_target->getBundle());

    $rows = [];

    // Overview (bundle-level) row.
    $missing = $this->missingTypes($existing[''] ?? [], $types);
    if (!empty($missing)) {
      $rows[] = $this->buildAddRow($annotation_target, '_overview', $this->t('Overview'), $missing);
    }

    // Per-field rows.
    foreach (array_keys($annotation_target->getFields()) as $field_name) {
      $label = $field_info[$field_name]['label'] ?? $field_name;
      $missing = $this->missingTypes($existing[$field_name] ?? [], $types);
      if (!empty($missing)) {
        $rows[] = $this->buildAddRow($annotation_target, $field_name, $label, $missing);
      }
    }

    if (empty($rows)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t(
          'Annotation is complete for this target. <a href=":url">Edit existing annotations</a>.',
          [':url' => Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $annotation_target->id()])->toString()],
        ),
      ];
    }

    $build = [];
    if ($this->config('annotations_ui.settings')->get('show_target_details')) {
      $panel = $this->buildTargetDetailsPanel($annotation_target, $request->getPathInfo());
      if ($panel) {
        $build['target_details'] = $panel;
      }
    }
    $build['fields_table'] = [
      '#theme' => 'table',
      '#header' => [$this->t('Field'), $this->t('Missing annotations')],
      '#rows' => $rows,
    ];
    return $build;
  }

  /**
   * Title callback for the add-new-annotations page.
   */
  public function addTitle(AnnotationTarget $annotation_target): TranslatableMarkup {
    return $this->t('Add annotations: %label', ['%label' => $annotation_target->label()]);
  }

  /**
   * Returns AnnotationEditForm pre-populated for a new annotation slot.
   *
   * @param \Drupal\annotations\Entity\AnnotationTarget $annotation_target
   *   The target.
   * @param string $field_name
   *   Field machine name, or '_overview' for the bundle-level annotation.
   * @param string $type_id
   *   Annotation type machine name (also the annotation bundle).
   */
  public function createAnnotationForm(AnnotationTarget $annotation_target, string $field_name, string $type_id): array {
    if (!$this->entityTypeManager()->getStorage('annotation_type')->load($type_id)) {
      throw new NotFoundHttpException();
    }

    $entity = $this->entityTypeManager()->getStorage('annotation')->create([
      'target_id' => $annotation_target->id(),
      'field_name' => $field_name === '_overview' ? '' : $field_name,
      'type_id' => $type_id,
    ]);

    return [
      'form' => $this->entityFormBuilder()->getForm($entity, 'edit'),
    ];
  }

  /**
   * Title callback for the new-annotation create form.
   */
  public function createAnnotationTitle(AnnotationTarget $annotation_target, string $field_name, string $type_id): TranslatableMarkup {
    $type = $this->entityTypeManager()->getStorage('annotation_type')->load($type_id);
    $type_label = $type ? (string) $type->label() : $type_id;

    if ($field_name === '_overview') {
      return $this->t('Add %type annotation: %target overview', [
        '%type' => $type_label,
        '%target' => $annotation_target->label(),
      ]);
    }

    $field_label = $field_name;
    $entity_type_id = $annotation_target->getTargetEntityTypeId();
    if ($this->entityTypeManager()->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      $defs = $this->fieldManager->getFieldDefinitions($entity_type_id, $annotation_target->getBundle());
      if (isset($defs[$field_name])) {
        $field_label = (string) $defs[$field_name]->getLabel();
      }
    }

    return $this->t('Add %type annotation: %target &rsaquo; %field</em>', [
      '%type' => $type_label,
      '%target' => $annotation_target->label(),
      '%field' => $field_label,
    ]);
  }

  /**
   * Builds the collapsible target details panel for the add page.
   *
   * Lists all annotatable fields for the target with their type and whether
   * each is included in scope. Returns an empty array when the target is not
   * fieldable (non-fieldable targets have nothing to list here).
   */
  private function buildTargetDetailsPanel(AnnotationTarget $annotation_target, string $destination = ''): array {
    $all_fields = $this->getFieldInfo($annotation_target->getTargetEntityTypeId(), $annotation_target->getBundle());

    if (empty($all_fields)) {
      return [];
    }

    $in_scope = $annotation_target->getFields();
    $rows = [];
    foreach ($all_fields as $field_name => $info) {
      $in = array_key_exists($field_name, $in_scope);
      $scope_cell = $in
        ? [
          'data' => [
            '#theme' => 'annotations_status_icon',
            '#glyph' => AnnotationsGlyph::CHECK,
            '#label' => $this->t('Yes'),
            '#modifier' => 'yes',
          ],
        ]
        : [
          'data' => [
            '#theme' => 'annotations_status_icon',
            '#glyph' => AnnotationsGlyph::CROSS,
            '#label' => $this->t('No'),
            '#modifier' => 'no',
          ],
        ];
      $rows[] = [
        ['data' => ['#plain_text' => $info['label']]],
        ['data' => ['#markup' => '<code>' . Html::escape($field_name) . '</code>']],
        ['data' => ['#plain_text' => $info['type']]],
        $scope_cell,
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Target details'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Field'),
          $this->t('Machine name'),
          $this->t('Type'),
          $this->t('In scope'),
        ],
        '#rows' => $rows,
      ],
      'configure_link' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Configure scope for this target'),
          '#url' => Url::fromRoute('annotations.target.fields', ['annotation_target' => $annotation_target->id()], $destination ? ['query' => ['destination' => $destination]] : []),
        ],
      ],
    ];
  }

  /**
   * Builds a table row for a field/overview slot w/ Add links per missing type.
   *
   * @param \Drupal\annotations\Entity\AnnotationTarget $annotation_target
   *   The target.
   * @param string $field_name
   *   Field machine name or '_overview'.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Human-readable label for the row.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $missing_types
   *   Types that have no annotation for this slot.
   */
  private function buildAddRow(AnnotationTarget $annotation_target, string $field_name, $label, array $missing_types): array {
    $destination = Url::fromRoute('annotations_ui.target.add', ['annotation_target' => $annotation_target->id()])->toString();

    $buttons = ['#type' => 'container', '#attributes' => ['class' => ['annotations-add-buttons']]];
    foreach ($missing_types as $type) {
      $buttons[$type->id()] = [
        '#type' => 'link',
        '#title' => $this->t('Add @label', ['@label' => $type->label()]),
        '#url' => Url::fromRoute('annotations_ui.target.create', [
          'annotation_target' => $annotation_target->id(),
          'field_name' => $field_name,
          'type_id' => $type->id(),
        ], ['query' => ['destination' => $destination]]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    return [
      ['data' => ['#plain_text' => (string) $label]],
      ['data' => $buttons],
    ];
  }

  /**
   * Returns types with no annotation value in the given existing map.
   *
   * @param array<string, string> $existing
   *   Existing annotation values keyed by type ID for one field/overview slot.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *   All accessible types.
   *
   * @return \Drupal\annotations\Entity\AnnotationTypeInterface[]
   *   Types that have no annotation for this slot.
   */
  private function missingTypes(array $existing, array $types): array {
    return array_filter($types, fn($type) => !array_key_exists($type->id(), $existing));
  }

  /**
   * Loads annotation types the current user may edit, sorted by weight.
   *
   * @return array<string, \Drupal\annotations\Entity\AnnotationTypeInterface>
   *   Annotation types keyed by type ID, sorted by weight.
   */
  private function loadAnnotationTypes(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $types */
    $types = $this->entityTypeManager()
      ->getStorage('annotation_type')
      ->loadMultiple();
    $account = $this->currentUser();
    $types = array_filter(
      $types,
      fn($type) => $account->hasPermission($type->getEditPermission()),
    );
    uasort($types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    return $types;
  }

  /**
   * Returns field info keyed by machine name for given entity type + bundle.
   *
   * @return array<string, array{label: string, type: string}>
   *   Field info arrays keyed by field machine name.
   */
  private function getFieldInfo(string $entity_type_id, string $bundle_id): array {
    $info = [];

    if (!$this->entityTypeManager()->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return $info;
    }

    $fields = $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle_id);
    foreach ($fields as $field_name => $definition) {
      if (!($definition instanceof FieldConfigInterface)
        && !in_array($field_name, TargetBase::NOTABLE_BASE_FIELDS, TRUE)
      ) {
        continue;
      }
      $info[$field_name] = [
        'label' => (string) $definition->getLabel(),
        'type' => $definition->getType(),
      ];
    }
    return $info;
  }

}
