<?php

declare(strict_types=1);

namespace Drupal\annotations_explorer\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\Entity\AnnotationTargetInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Vault-style annotation explorer.
 */
class ExplorerController extends ControllerBase {

  public function __construct(
    protected AnnotationStorageService $storageService,
    protected EntityFieldManagerInterface $fieldManager,
    protected AnnotationDiscoveryService $discoveryService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations.annotation_storage'),
      $container->get('entity_field.manager'),
      $container->get('annotations.discovery'),
    );
  }

  /**
   * Access callback: allow if the user can consume at least one annotation type.
   */
  public function access(): AccessResultInterface {
    return AccessResult::allowedIf(!empty($this->loadAccessibleTypes()))
      ->addCacheContexts(['user.permissions']);
  }

  /**
   * Builds the full explorer page.
   */
  public function page(Request $request): array {
    $types = $this->loadAccessibleTypes();
    $targets = $this->loadAccessibleTargets($types);

    $first = !empty($targets) ? reset($targets) : NULL;
    $active_id = (string) $request->query->get('target', $first?->id() ?? '');
    $active_target = isset($targets[$active_id]) ? $targets[$active_id] : $first;

    return [
      '#attached' => ['library' => ['annotations_explorer/annotations_explorer.page']],
      '#cache' => [
        'tags' => ['annotation_list', 'annotation_target_list', 'annotation_type_list'],
        'contexts' => array_merge(
          ['languages:language_interface', 'url.query_args', 'user.permissions'],
          $this->languageManager()->isMultilingual() ? ['languages:content'] : [],
        ),
      ],
      '#theme' => 'annotations_explorer',
      '#nav' => $this->buildNav($targets, $active_target, $types),
      '#main' => $this->buildMain($active_target, $types),
    ];
  }

  /**
   * Returns an AJAX response replacing the nav and main panels.
   */
  public function targetPanel(AnnotationTargetInterface $annotation_target, Request $request): AjaxResponse|RedirectResponse|array {
    if (!$request->isXmlHttpRequest()) {
      return $this->redirect('annotations_explorer.page', [], ['query' => ['target' => $annotation_target->id()]]);
    }

    $types = $this->loadAccessibleTypes();
    $targets = $this->loadAccessibleTargets($types);

    $response = new AjaxResponse();

    $nav = $this->buildNav($targets, $annotation_target, $types);
    $nav['#prefix'] = '<div id="annotations-explorer-nav">';
    $nav['#suffix'] = '</div>';
    $response->addCommand(new ReplaceCommand('#annotations-explorer-nav', $nav));

    $main = $this->buildMain($annotation_target, $types);
    $main['#prefix'] = '<div id="annotations-explorer-main">';
    $main['#suffix'] = '</div>';
    $response->addCommand(new ReplaceCommand('#annotations-explorer-main', $main));

    return $response;
  }

  /**
   * Builds the left-panel navigation.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $targets
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface|null $active
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   */
  private function buildNav(array $targets, ?AnnotationTargetInterface $active, array $types): array {
    if (empty($targets)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No annotations yet.'),
        '#attributes' => ['class' => ['annotations-explorer__empty']],
      ];
    }

    // Group targets by entity type.
    $groups = [];
    foreach ($targets as $target) {
      $groups[$target->getTargetEntityTypeId()][$target->id()] = $target;
    }

    uksort($groups, fn(string $a, string $b) => strnatcasecmp(
      $this->getEntityTypeLabel($a),
      $this->getEntityTypeLabel($b),
    ));

    $nav_list = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#attributes' => ['class' => ['annotations-explorer__nav-list']],
    ];

    foreach ($groups as $entity_type_id => $group_targets) {
      $nav_list[] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#value' => Html::escape($this->getEntityTypeLabel($entity_type_id)),
        '#attributes' => ['class' => ['annotations-explorer__nav-group-heading'], 'aria-hidden' => 'true'],
      ];

      foreach ($group_targets as $target) {
        $is_active = $active && $target->id() === $active->id();

        $details = [
          '#type' => 'html_tag',
          '#tag' => 'details',
          '#attributes' => $is_active ? ['open' => TRUE] : [],
          'summary' => [
            '#type' => 'html_tag',
            '#tag' => 'summary',
            'link' => [
              '#type' => 'link',
              '#title' => $target->label(),
              '#url' => Url::fromRoute('annotations_explorer.target', ['annotation_target' => $target->id()]),
              '#attributes' => ['class' => ['use-ajax']],
            ],
          ],
        ];

        if ($is_active) {
          $sections = $this->getVisibleSections($target, $types);
          if (!empty($sections)) {
            $fields_list = [
              '#type' => 'html_tag',
              '#tag' => 'ul',
              '#attributes' => ['class' => ['annotations-explorer__fields-list']],
            ];
            foreach ($sections as $section_key => $section_label) {
              $anchor = 'annotations-explorer-group-' . Html::cleanCssIdentifier($section_key);
              $fields_list[] = [
                '#type' => 'html_tag',
                '#tag' => 'li',
                'link' => [
                  '#type' => 'link',
                  '#title' => $section_label,
                  '#url' => Url::fromRoute('<none>', [], ['fragment' => $anchor]),
                ],
              ];
            }
            $details['fields'] = $fields_list;
          }
        }

        $nav_list[] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => ['class' => array_values(array_filter(['annotations-explorer__nav-item', $is_active ? 'is-active' : NULL]))],
          'details' => $details,
        ];
      }
    }

    return $nav_list;
  }

  /**
   * Returns the human-readable label for an entity type ID.
   *
   * Delegates to the target plugin so labels match the admin scope UI.
   * Falls back to the entity type definition label, then the machine name.
   */
  private function getEntityTypeLabel(string $entity_type_id): string {
    $plugins = $this->discoveryService->getPlugins();
    if (isset($plugins[$entity_type_id])) {
      return $plugins[$entity_type_id]->getLabel();
    }
    $definition = $this->entityTypeManager()->getDefinition($entity_type_id, FALSE);
    return $definition ? (string) $definition->getLabel() : $entity_type_id;
  }

  /**
   * Builds the main panel content for a target.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface|null $target
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   */
  private function buildMain(?AnnotationTargetInterface $target, array $types): array {
    if (!$target) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Select a target from the left panel.'),
        '#attributes' => ['class' => ['annotations-explorer__empty']],
      ];
    }

    $annotations = $this->storageService->getForTarget($target->id(), TRUE);
    $overview = $annotations[''] ?? [];
    $fields = array_filter($annotations, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-explorer__main-inner']],
    ];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-explorer__header']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => Html::escape($target->label()),
        '#attributes' => ['class' => ['annotations-explorer__target-heading']],
      ],
    ];

    if ($this->moduleHandler()->moduleExists('annotations_ui')) {
      $url = Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $target->id()]);
      if ($url->access($this->currentUser())) {
        $build['header']['edit'] = [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => $url,
          '#attributes' => ['class' => ['button', 'button--small']],
        ];
      }
    }

    if (!empty($overview)) {
      $group = $this->buildAnnotationGroup($this->t('Overview'), $overview, $types);
      if (!empty($group)) {
        $group['#attributes']['id'] = 'annotations-explorer-group-overview';
        $build['overview'] = $group;
      }
    }

    foreach ($fields as $field_name => $field_annotations) {
      $field_label = $this->getFieldLabel($target, $field_name);
      $group = $this->buildAnnotationGroup($field_label, $field_annotations, $types, $field_name);
      if (!empty($group)) {
        $group['#attributes']['id'] = 'annotations-explorer-group-' . Html::cleanCssIdentifier($field_name);
        $build['field_' . Html::cleanCssIdentifier($field_name)] = $group;
      }
    }

    if (count($build) === 2) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No annotations for this target.'),
        '#attributes' => ['class' => ['annotations-explorer__empty']],
      ];
    }

    return $build;
  }

  /**
   * Builds a group of annotations for one location (overview or field).
   *
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $label
   * @param array<string, string> $annotations  Keyed by type_id.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   * @param string $machine_name  Field machine name shown beneath the heading.
   */
  private function buildAnnotationGroup(mixed $label, array $annotations, array $types, string $machine_name = ''): array {
    $items = [];
    foreach ($annotations as $type_id => $value) {
      if ($value === '' || !isset($types[$type_id])) {
        continue;
      }
      $type_label = (string) $types[$type_id]->label();
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['annotations-explorer__annotation']],
        'type' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => Html::escape($type_label),
          '#attributes' => ['class' => ['annotations-explorer__type-label']],
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => Html::escape($value),
          '#attributes' => ['class' => ['annotations-explorer__annotation-value']],
        ],
      ];
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-explorer__group']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $machine_name
          ? Markup::create(Html::escape((string) $label) . ' <small class="annotations-explorer__machine-name">' . Html::escape($machine_name) . '</small>')
          : Html::escape((string) $label),
        '#attributes' => ['class' => ['annotations-explorer__group-label']],
      ],
      'items' => $items,
    ];
  }

  /**
   * Returns section keys and labels for the active target's visible content.
   *
   * Used to populate the fields collapsible in the nav. Keyed by section
   * identifier (for anchor href), value is the human-readable label.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface $target
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *
   * @return array<string, string>
   */
  private function getVisibleSections(AnnotationTargetInterface $target, array $types): array {
    $annotations = $this->storageService->getForTarget($target->id(), TRUE);
    $sections = [];

    foreach ($annotations as $field_name => $field_annotations) {
      foreach ($field_annotations as $type_id => $value) {
        if ($value !== '' && isset($types[$type_id])) {
          $key = $field_name === '' ? 'overview' : $field_name;
          $label = $field_name === '' ? (string) $this->t('Overview') : $this->getFieldLabel($target, $field_name);
          $sections[$key] = $label;
          break;
        }
      }
    }

    return $sections;
  }

  /**
   * Resolves a field machine name to its human-readable label.
   *
   * Falls back to the machine name if the field definition is unavailable.
   */
  private function getFieldLabel(AnnotationTargetInterface $target, string $field_name): string {
    $definitions = $this->fieldManager->getFieldDefinitions(
      $target->getTargetEntityTypeId(),
      $target->getBundle()
    );
    return isset($definitions[$field_name])
      ? (string) $definitions[$field_name]->getLabel()
      : $field_name;
  }

  /**
   * Loads annotation_target entities that have visible content for this user.
   *
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   */
  private function loadAccessibleTargets(array $types): array {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface[] $all */
    $all = $this->entityTypeManager()
      ->getStorage('annotation_target')
      ->loadMultiple();

    uasort($all, fn($a, $b) => strnatcasecmp((string) $a->label(), (string) $b->label()));

    return array_filter($all, fn($target) => !empty($this->getVisibleSections($target, $types)));
  }

  /**
   * Loads annotation types the current user has consume access to.
   *
   * @return \Drupal\annotations\Entity\AnnotationTypeInterface[]
   */
  private function loadAccessibleTypes(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all */
    $all = $this->entityTypeManager()
      ->getStorage('annotation_type')
      ->loadMultiple();

    $user = $this->currentUser();
    return array_filter($all, fn($type) => $user->hasPermission($type->getConsumePermission()));
  }

}
