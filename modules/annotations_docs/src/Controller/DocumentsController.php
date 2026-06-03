<?php

declare(strict_types=1);

namespace Drupal\annotations_docs\Controller;

use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Entity\AnnotationTargetInterface;
use Drupal\annotations_docs\DocumentGeneratorService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Two-panel browser for annotation documents.
 */
class DocumentsController extends ControllerBase {

  public function __construct(
    protected DocumentGeneratorService $generator,
    protected AnnotationDiscoveryService $discoveryService,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations_docs.generator'),
      $container->get('annotations.discovery'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Builds the full two-panel documents page.
   */
  public function page(Request $request): array {
    $targets = $this->loadAllTargets();
    $doc_map = $this->buildDocMap($targets);

    $first_with_doc = NULL;
    foreach ($targets as $target) {
      if ($doc_map[$target->id()] !== NULL) {
        $first_with_doc = $target;
        break;
      }
    }

    $active_id = (string) $request->query->get('target', $first_with_doc?->id() ?? '');
    $active_target = isset($targets[$active_id]) ? $targets[$active_id] : $first_with_doc;

    // Only allow a target with a document to be the active target.
    if ($active_target && $doc_map[$active_target->id()] === NULL) {
      $active_target = $first_with_doc;
    }

    return [
      '#attached' => ['library' => ['annotations_docs/annotations_docs.page']],
      '#cache' => [
        'tags' => ['annotation_target_list', 'node_list:annotations_document'],
        'contexts' => array_merge(
          ['languages:language_interface', 'url.query_args', 'user.permissions'],
          $this->languageManager()->isMultilingual() ? ['languages:language_content'] : [],
        ),
      ],
      '#theme' => 'annotations_documents',
      '#nav' => $this->buildNav($targets, $doc_map, $active_target),
      '#main' => $this->buildMain($active_target, $doc_map),
    ];
  }

  /**
   * Returns an AJAX response replacing both panels, or redirects if non-AJAX.
   */
  public function targetPanel(AnnotationTargetInterface $annotation_target, Request $request): AjaxResponse|RedirectResponse|array {
    if (!$request->isXmlHttpRequest()) {
      return $this->redirect('annotations_docs.page', [], ['query' => ['target' => $annotation_target->id()]]);
    }

    $targets = $this->loadAllTargets();
    $doc_map = $this->buildDocMap($targets);

    $response = new AjaxResponse();

    $nav = $this->buildNav($targets, $doc_map, $annotation_target);
    $nav['#prefix'] = '<div id="annotations-documents-nav">';
    $nav['#suffix'] = '</div>';
    $response->addCommand(new ReplaceCommand('#annotations-documents-nav', $nav));

    $main = $this->buildMain($annotation_target, $doc_map);
    $main['#prefix'] = '<div id="annotations-documents-main">';
    $main['#suffix'] = '</div>';
    $response->addCommand(new ReplaceCommand('#annotations-documents-main', $main));

    return $response;
  }

  /**
   * Builds the left-panel navigation.
   *
   * Targets with a document are clickable AJAX links. Targets without a
   * document are non-clickable but show a "Generate" link.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $targets
   * @param array<string, \Drupal\node\NodeInterface|null> $doc_map
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface|null $active
   */
  private function buildNav(array $targets, array $doc_map, ?AnnotationTargetInterface $active): array {
    if (empty($targets)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No annotation targets configured.'),
        '#attributes' => ['class' => ['annotations-documents__empty']],
      ];
    }

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
      '#attributes' => ['class' => ['annotations-documents__nav-list']],
    ];

    foreach ($groups as $entity_type_id => $group_targets) {
      $nav_list[] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#value' => Html::escape($this->getEntityTypeLabel($entity_type_id)),
        '#attributes' => ['class' => ['annotations-documents__nav-group-heading'], 'aria-hidden' => 'true'],
      ];

      foreach ($group_targets as $target) {
        $doc = $doc_map[$target->id()] ?? NULL;
        $is_active = $active && $target->id() === $active->id();
        $has_doc = $doc !== NULL;

        $status_label = $this->getStatusLabel($doc);
        $status_class = $this->getStatusClass($doc);

        $item_classes = ['annotations-documents__nav-item'];
        if (!$has_doc) {
          $item_classes[] = 'annotations-documents__nav-item--no-doc';
        }
        if ($is_active) {
          $item_classes[] = 'is-active';
        }

        $label_inner = Markup::create(
          Html::escape((string) $target->label())
          . ' <span class="annotations-documents__status-chip annotations-documents__status-chip--' . Html::escape($status_class) . '">'
          . Html::escape($status_label)
          . '</span>',
        );

        if ($has_doc) {
          // Clickable AJAX link.
          $label_element = [
            '#type' => 'link',
            '#title' => $label_inner,
            '#url' => Url::fromRoute('annotations_docs.target', ['annotation_target' => $target->id()]),
            '#attributes' => ['class' => ['use-ajax', 'annotations-documents__nav-link']],
          ];
        }
        else {
          // Non-clickable span; Generate link alongside.
          $label_element = [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $label_inner,
            '#attributes' => ['class' => ['annotations-documents__nav-label']],
          ];
        }

        $item = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => ['class' => $item_classes],
          'label' => $label_element,
        ];

        if (!$has_doc && $this->currentUser()->hasPermission('generate annotation documents')) {
          $item['generate'] = [
            '#type' => 'link',
            '#title' => $this->t('Generate'),
            '#url' => Url::fromRoute('annotations_docs.generate', ['annotation_target' => $target->id()]),
            '#attributes' => ['class' => ['annotations-documents__generate-link', 'button', 'button--extrasmall']],
          ];
        }

        $nav_list[] = $item;
      }
    }

    return $nav_list;
  }

  /**
   * Builds the main panel for the selected target.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface|null $target
   * @param array<string, \Drupal\node\NodeInterface|null> $doc_map
   */
  private function buildMain(?AnnotationTargetInterface $target, array $doc_map): array {
    if ($target === NULL) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Select a target from the left panel.'),
        '#attributes' => ['class' => ['annotations-documents__empty']],
      ];
    }

    $doc = $doc_map[$target->id()] ?? NULL;
    if ($doc === NULL) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No document for this target.'),
        '#attributes' => ['class' => ['annotations-documents__empty']],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-documents__main-inner']],
    ];

    // Header: title + action buttons.
    $header = [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-documents__header']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => Html::escape((string) $target->label()),
        '#attributes' => ['class' => ['annotations-documents__target-heading']],
      ],
    ];

    $status_label = $this->getStatusLabel($doc);
    $status_class = $this->getStatusClass($doc);
    $header['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => Html::escape($status_label),
      '#attributes' => ['class' => ['annotations-documents__status-chip', 'annotations-documents__status-chip--' . $status_class]],
    ];

    $ts = $this->generator->getLastGeneratedTimestamp($target->id());
    if ($ts !== NULL) {
      $age = $this->t('@days days ago', ['@days' => (int) round(($this->time->getRequestTime() - $ts) / 86400)]);
      $header['generated'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Last generated: @age', ['@age' => $age]),
        '#attributes' => ['class' => ['annotations-documents__generated-time']],
      ];
    }

    if ($this->currentUser()->hasPermission('generate annotation documents')) {
      $header['regenerate'] = [
        '#type' => 'link',
        '#title' => $this->t('Regenerate'),
        '#url' => Url::fromRoute('annotations_docs.generate', ['annotation_target' => $target->id()]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    $edit_url = $doc->toUrl('edit-form');
    if ($edit_url->access($this->currentUser())) {
      $header['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => $edit_url,
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    $build['header'] = $header;

    // Document body.
    $body = $doc->get('annotations_doc_body')->first();
    if ($body && $body->value !== '') {
      $build['body'] = [
        '#type' => 'processed_text',
        '#text' => $body->value,
        '#format' => $body->format ?? 'plain_text',
        '#cache' => ['tags' => $doc->getCacheTags()],
      ];
    }
    else {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No content in this document.'),
        '#attributes' => ['class' => ['annotations-documents__empty']],
      ];
    }

    return $build;
  }

  /**
   * Returns a human-readable status label for a target's document state.
   */
  private function getStatusLabel(?NodeInterface $doc): string {
    if ($doc === NULL) {
      return (string) $this->t('No document');
    }
    return $doc->isPublished() ? (string) $this->t('Published') : (string) $this->t('Draft');
  }

  /**
   * Returns a CSS modifier class for a target's document state.
   */
  private function getStatusClass(?NodeInterface $doc): string {
    if ($doc === NULL) {
      return 'none';
    }
    return $doc->isPublished() ? 'published' : 'draft';
  }

  /**
   * Builds a map of target_id to annotations_document node (or NULL).
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $targets
   *
   * @return array<string, \Drupal\node\NodeInterface|null>
   */
  private function buildDocMap(array $targets): array {
    if (empty($targets)) {
      return [];
    }

    $target_ids = array_keys($targets);

    $nids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'annotations_document')
      ->condition('annotations_doc_target', $target_ids, 'IN')
      ->execute();

    $map = array_fill_keys($target_ids, NULL);

    if (!empty($nids)) {
      /** @var \Drupal\node\NodeInterface[] $nodes */
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);
      foreach ($nodes as $node) {
        $tid = (string) $node->get('annotations_doc_target')->value;
        if (array_key_exists($tid, $map)) {
          $map[$tid] = $node;
        }
      }
    }

    return $map;
  }

  /**
   * Loads all annotation_target entities sorted by entity type then label.
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   */
  private function loadAllTargets(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface[] $all */
    $all = $this->entityTypeManager()->getStorage('annotation_target')->loadMultiple();

    uasort($all, fn($a, $b) =>
      strnatcasecmp($this->getEntityTypeLabel($a->getTargetEntityTypeId()), $this->getEntityTypeLabel($b->getTargetEntityTypeId()))
      ?: strnatcasecmp((string) $a->label(), (string) $b->label())
    );

    return $all;
  }

  /**
   * Returns the human-readable label for an entity type ID.
   */
  private function getEntityTypeLabel(string $entity_type_id): string {
    $plugins = $this->discoveryService->getPlugins();
    if (isset($plugins[$entity_type_id])) {
      return $plugins[$entity_type_id]->getLabel();
    }
    $definition = $this->entityTypeManager()->getDefinition($entity_type_id, FALSE);
    return $definition ? (string) $definition->getLabel() : $entity_type_id;
  }

}
