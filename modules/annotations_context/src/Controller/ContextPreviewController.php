<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextHtmlRenderer;
use Drupal\annotations_context\ContextRenderer;
use Drupal\annotations_context\Form\ContextFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    private readonly AnnotationDiscoveryService $discoveryService,
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
  public function page(Request $request): array {
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
    $this->assembler->getLastCacheableMetadata()->applyTo($build);
    $build['#attached']['library'][] = 'annotations/annotations.admin';

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

    $build['toolbar'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['annotations-context__toolbar']],
      'filters'     => $this->formBuilder()->getForm(ContextFilterForm::class, $options),
      'export'      => [
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
          $role_label = $role_entity ? $role_entity->label() : $options['role'];
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

    $build['content'] = $this->htmlRenderer->render($payload);

    $exclusive = (bool) $this->config('annotations.settings')->get('use_accordion_single');
    $markdown  = $this->markdownRenderer->render($payload);
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

    $role = (string) $request->query->get('role', '');
    if ($role !== '') {
      $options['role'] = $role;
    }

    return $options;
  }

}
