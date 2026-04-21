<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\annotations\DiscoveryService;
use Drupal\annotations_context\ContextHtmlRenderer;
use Drupal\annotations_context\ContextRenderer;
use Drupal\annotations_context\ContextAssembler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin preview and raw export for assembled context.
 *
 * Preview page (/admin/config/annotations/context):
 *   Renders assembled context as a browseable document using Drupal render
 *   arrays — one collapsible card per target, grouped by entity type, with
 *   a stats banner and gap indicators. A collapsed "Raw markdown" drawer at
 *   the bottom exposes the same content as plain text for copy/export.
 *
 * Filters: role simulation, specific target, ref depth, include field metadata.
 *
 * Export (/admin/config/annotations/context/export):
 *   Returns markdown as a text/markdown file download.
 */
class ContextPreviewController extends ControllerBase {

  public function __construct(
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $markdownRenderer,
    private readonly ContextHtmlRenderer $htmlRenderer,
    private readonly DiscoveryService $discoveryService,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_context.assembler'),
      $container->get('annotations_context.renderer'),
      $container->get('annotations_context.html_renderer'),
      $container->get('annotations.discovery'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Admin preview page.
   */
  public function page(Request $request): array|RedirectResponse {
    if ($request->query->get('op') === 'clear') {
      return new RedirectResponse(Url::fromRoute('annotations_context.preview')->toString());
    }

    $options = $this->optionsFromRequest($request);
    $payload = $this->assembler->assemble($options);

    $build = [];
    $build['#cache'] = [
      'tags'     => ['annotation_list', 'annotation_target_list', 'annotation_type_list'],
      'contexts' => array_merge(
        ['languages:language_interface', 'url.query_args', 'user.permissions'],
        $this->languageManager()->isMultilingual() ? ['languages:content'] : [],
      ),
    ];
    // Merge cache metadata contributed by hook_annotations_context_alter() implementations.
    $this->assembler->getLastCacheableMetadata()->applyTo($build);
    $build['#attached']['library'][] = 'annotations/annotations.admin';

    // Build export URL preserving current filters.
    $export_query = [];
    if (!empty($options['entity_type'])) {
      $export_query['target_id'] = 'et:' . $options['entity_type'];
    }
    elseif (!empty($options['target_id'])) {
      $export_query['target_id'] = $options['target_id'];
    }
    if (isset($options['ref_depth']) && $options['ref_depth'] !== ContextAssembler::DEFAULT_REF_DEPTH) {
      $export_query['ref_depth'] = $options['ref_depth'];
    }
    if (!empty($options['role'])) {
      $export_query['role'] = $options['role'];
    }
    if (!empty($options['include_field_meta'])) {
      $export_query['include_field_meta'] = '1';
    }

    $export_url = Url::fromRoute('annotations_context.export', [], ['query' => $export_query]);

    // Toolbar: filter form + download button side by side.
    $build['toolbar'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__toolbar']],
      'filters' => $this->buildFilterForm($options),
      'export'  => [
        '#type'       => 'link',
        '#title'      => $this->t('Download .md'),
        '#url'        => $export_url,
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
    ];

    if ($payload['meta']['target_count'] === 0) {
      $total_targets = $this->entityTypeManager
        ->getStorage('annotation_target')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      if ($total_targets === 0) {
        $targets_url = $this->moduleHandler()->moduleExists('annotations_ui')
          ? Url::fromRoute('annotations_ui.annotate.collection')->toString()
          : Url::fromRoute('entity.annotation_target.collection')->toString();

        $build['empty'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t(
            'No targets found. <a href=":targets">Select and annotate targets</a> first.',
            [':targets' => $targets_url],
          ),
          '#attributes' => ['class' => ['description']],
        ];
      }
      elseif (!empty($options['role'])) {
        $options_without_role = $options;
        unset($options_without_role['role']);
        $unfiltered = $this->assembler->assemble($options_without_role);
        if ($unfiltered['meta']['target_count'] > 0) {
          $role_entity = $this->entityTypeManager()->getStorage('user_role')->load($options['role']);
          $role_label  = $role_entity ? $role_entity->label() : $options['role'];
          $build['empty'] = [
            '#type'       => 'html_tag',
            '#tag'        => 'p',
            '#value'      => $this->t('Annotations exist but none are visible to the @role role.', ['@role' => $role_label]),
            '#attributes' => ['class' => ['description']],
          ];
        }
        else {
          $build['empty'] = [
            '#type'       => 'html_tag',
            '#tag'        => 'p',
            '#value'      => $this->t('No annotations match the current filters.'),
            '#attributes' => ['class' => ['description']],
          ];
        }
      }
      else {
        $build['empty'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t('No annotations match the current filters.'),
          '#attributes' => ['class' => ['description']],
        ];
      }

      return $build;
    }

    // Main rendered document.
    $build['content'] = $this->htmlRenderer->render($payload);

    // Raw markdown — collapsed, plain output for copy/export reference.
    $exclusive = (bool) $this->config('annotations.settings')->get('use_accordion_single');
    $markdown = $this->markdownRenderer->render($payload);
    if ($markdown !== '') {
      $build['raw'] = [
        '#type'       => 'details',
        '#title'      => $this->t('Raw markdown'),
        '#open'       => FALSE,
        '#attributes' => $exclusive ? ['name' => 'annotations-context-preview'] : [],
        'pre'         => [
          '#type'  => 'html_tag',
          '#tag'   => 'pre',
          '#value' => Html::escape($markdown),
        ],
      ];
    }

    return $build;
  }

  /**
   * Raw markdown download.
   */
  public function export(Request $request): Response {
    $options  = $this->optionsFromRequest($request);
    $payload  = $this->assembler->assemble($options);
    $markdown = $this->markdownRenderer->render($payload);

    $filename = 'annotations-context';
    if (!empty($options['target_id'])) {
      $filename .= '-' . str_replace('__', '-', $options['target_id']);
    }
    $filename .= '.md';

    $response = new Response($markdown);
    $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="' . $filename . '"',
    );

    return $response;
  }

  /**
   * Extracts assembler options from the current request query string.
   */
  private function optionsFromRequest(Request $request): array {
    $options = [];

    // Specific target or entity-type filter (encoded as "et:{entity_type}").
    $target_id = (string) $request->query->get('target_id', '');
    if (str_starts_with($target_id, 'et:')) {
      $options['entity_type'] = substr($target_id, 3);
    }
    elseif ($target_id !== '') {
      $options['target_id'] = $target_id;
    }

    $ref_depth_raw = $request->query->get('ref_depth');
    if ($ref_depth_raw !== NULL) {
      $options['ref_depth'] = max(0, (int) $ref_depth_raw);
    }

    if ($request->query->get('include_field_meta') === '1') {
      $options['include_field_meta'] = TRUE;
    }

    // Role simulation filter.
    $role = (string) $request->query->get('role', '');
    if ($role !== '') {
      $options['role'] = $role;
    }

    return $options;
  }

  /**
   * Filter form for the preview page.
   *
   * Controls: role (primary), specific target, ref depth, include field metadata.
   */
  private function buildFilterForm(array $current_options): array {
    // Role options — exclude admin roles (bypasses all permission checks).
    $role_options = ['' => $this->t('All roles (no filter)')];
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager()->getStorage('user_role')->loadMultiple();
    foreach ($roles as $role) {
      if ($role->isAdmin()) {
        continue;
      }
      $role_options[$role->id()] = $role->label();
    }

    // Target options — all annotation_targets grouped by entity type as optgroups.
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface[] $all_targets */
    $all_targets = $this->entityTypeManager()->getStorage('annotation_target')->loadMultiple();
    uasort($all_targets, fn($a, $b) =>
      $a->getTargetEntityTypeId() <=> $b->getTargetEntityTypeId()
      ?: strcmp((string) $a->label(), (string) $b->label())
    );

    $plugins = $this->discoveryService->getPlugins();
    $target_options = ['' => $this->t('All targets')];
    $grouped = [];
    foreach ($all_targets as $target) {
      $grouped[$target->getTargetEntityTypeId()][] = $target;
    }
    foreach ($grouped as $et_id => $targets) {
      $plugin      = $plugins[$et_id] ?? NULL;
      $group_label = $plugin ? (string) $plugin->getLabel() : ucfirst(str_replace('_', ' ', $et_id));
      // Selectable entity-type-level option for "all bundles of this type".
      $target_options['et:' . $et_id] = $this->t('All @label', ['@label' => $group_label]);
      $target_options[$group_label] = [];
      foreach ($targets as $target) {
        $target_options[$group_label][$target->id()] = (string) $target->label();
      }
    }

    $form = [
      '#type'       => 'html_tag',
      '#tag'        => 'form',
      '#attributes' => [
        'method' => 'get',
        'action' => Url::fromRoute('annotations_context.preview')->toString(),
        'class'  => ['annotations-context__filters', 'form--inline'],
      ],
    ];

    $form['role'] = [
      '#type'       => 'select',
      '#title'      => $this->t('View as role'),
      '#options'    => $role_options,
      '#value'      => $current_options['role'] ?? '',
      '#name'       => 'role',
      '#attributes' => ['id' => 'annotations-context-role'],
    ];

    $form['target_id'] = [
      '#type'       => 'select',
      '#title'      => $this->t('Target'),
      '#options'    => $target_options,
      '#value'      => isset($current_options['entity_type'])
        ? 'et:' . $current_options['entity_type']
        : ($current_options['target_id'] ?? ''),
      '#name'       => 'target_id',
      '#attributes' => ['id' => 'annotations-context-target-id'],
    ];

    $form['ref_depth'] = $this->buildRefDepthSelect($current_options);

    $form['include_field_meta'] = $this->buildIncludeFieldMetaCheckbox($current_options);

    $form['actions'] = $this->buildSubmitButton();
    $form['actions']['clear'] = $this->buildClearButton();

    return $form;
  }

  /**
   * Builds the "Include metadata" checkbox for GET forms.
   *
   * Off by default — field type/cardinality/help text adds useful context for
   * AI use but is noisy in the human-readable preview.
   */
  private function buildIncludeFieldMetaCheckbox(array $current_options): array {
    return [
      '#theme'   => 'annotations_context_checkbox',
      '#id'      => 'annotations-context-include-field-meta',
      '#name'    => 'include_field_meta',
      '#value'   => '1',
      '#checked' => !empty($current_options['include_field_meta']),
      '#label'   => $this->t('Include metadata'),
    ];
  }

  /**
   * Builds the References depth select.
   */
  private function buildRefDepthSelect(array $current_options): array {
    return [
      '#type'       => 'select',
      '#title'      => $this->t('References'),
      '#options'    => [
        0 => $this->t('None'),
        1 => $this->t('One hop'),
        2 => $this->t('Two hops'),
      ],
      '#value'      => $current_options['ref_depth'] ?? ContextAssembler::DEFAULT_REF_DEPTH,
      '#name'       => 'ref_depth',
      '#attributes' => ['id' => 'annotations-context-ref-depth'],
    ];
  }

  /**
   * Builds the filter form submit button.
   */
  private function buildSubmitButton(): array {
    return [
      '#type'       => 'html_tag',
      '#tag'        => 'div',
      '#attributes' => ['class' => ['annotations-context__filter-actions']],
      'submit'      => [
        '#type'       => 'html_tag',
        '#tag'        => 'button',
        '#value'      => (string) $this->t('Filter'),
        '#attributes' => ['type' => 'submit', 'class' => ['button']],
      ],
    ];
  }

  /**
   * Builds a "Clear" submit button that resets all filters to defaults.
   *
   * Submits the form with op=clear; the controller detects this and issues a
   * redirect to the bare route URL, stripping all query parameters.
   */
  private function buildClearButton(): array {
    return [
      '#type'       => 'html_tag',
      '#tag'        => 'button',
      '#value'      => (string) $this->t('Clear'),
      '#attributes' => ['type' => 'submit', 'name' => 'op', 'value' => 'clear', 'class' => ['button', 'button--secondary']],
    ];
  }

}
