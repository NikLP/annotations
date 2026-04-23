<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\AnnotationsGlyph;
use Drupal\annotations\Entity\Annotation;

/**
 * Hook implementations for the annotations_overlay module.
 */
class AnnotationsOverlayHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationStorageService $annotationStorage,
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RendererInterface $renderer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * Registers the annotations_overlay computed base field on all fieldable
   * content entity types (except annotation itself). Site builders opt in per
   * view mode by dragging the field into a visible region on Manage Display;
   * the formatter settings expose the annotation view mode to use.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)
      || $entity_type->id() === 'annotation') {
      return [];
    }
    $has_target = (bool) $this->entityTypeManager
      ->getStorage('annotation_target')
      ->loadByProperties(['entity_type' => $entity_type->id()]);
    if (!$has_target) {
      return [];
    }
    return [
      'annotations_overlay' => BaseFieldDefinition::create('annotations_overlay')
        ->setLabel(new TranslatableMarkup('Annotations overlay'))
        ->setDescription(new TranslatableMarkup('Displays annotation overlays on entity views. Enable per view mode via Manage Display.'))
        ->setComputed(TRUE)
        ->setDisplayOptions('view', [
          'label' => 'hidden',
          'type' => 'annotations_overlay',
          'region' => 'hidden',
          'weight' => 100,
        ])
        ->setDisplayConfigurable('view', TRUE),
    ];
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      // Outer wrapper — default template renders a <dialog>. Other modules may
      // add suggestions (e.g. annotations_overlay_wrapper__details) to swap in
      // a different container without touching item rendering.
      'annotations_overlay_wrapper' => [
        'variables' => [
          'attributes' => NULL,
          'close_attributes' => NULL,
          'close_label' => '',
          'heading' => '',
          'items' => [],
        ],
      ],
      // Single annotation item: type heading,entity content,optional edit link.
      'annotations_overlay_item' => [
        'variables' => [
          'type_id' => NULL,
          'type_label' => NULL,
          'content' => NULL,
          'edit_url' => NULL,
          // Set TRUE when only one annotation type is expressed across all
          // annotations the current user can see on the page.
          'single_type' => FALSE,
        ],
      ],
      // Chooser description wrapper — original description + annotation items.
      'annotations_overlay_chooser' => [
        'variables' => [
          'description' => NULL,
          'items' => [],
        ],
      ],
      // Single annotation item on chooser page — no dialog chrome / edit link.
      'annotations_overlay_chooser_item' => [
        'variables' => [
          'type_id' => NULL,
          'type_label' => NULL,
          'content' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_form_alter().
   *
   * Injects field-level "?" triggers and annotation dialogs into entity edit
   * and add forms. Dialog content is embedded in the DOM at page load; no
   * round-trip needed.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface) {
      $entity = $form_object->getEntity();
    }
    elseif (method_exists($form_object, 'getParagraph')) {
      // layout_paragraphs component forms (ComponentFormBase) expose the
      // paragraph via getParagraph() but do not implement EntityFormInterface.
      $entity = $form_object->getParagraph();
    }
    else {
      return;
    }

    if ($entity === NULL) {
      return;
    }
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $target_id = $entity_type_id . '__' . $bundle;

    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $target */
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if ($target === NULL) {
      return;
    }

    $visible_types = $this->loadVisibleAnnotationTypes();

    if (empty($visible_types)) {
      return;
    }

    /**
     * Consumer context: only show published annotations. When
     * annotations_workflow is not installed, entities have no moderation_state
     * field and the 'published' filter is a silent no-op — all non-empty
     * values are returned.
     */
    $entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
    $bundle_annotations = $this->filterAnnotationEntities($entity_map[''] ?? [], $visible_types);

    $fields_with_annotations = [];
    foreach (array_keys($target->getFields()) as $field_name) {
      $field_annotations = $this->filterAnnotationEntities($entity_map[$field_name] ?? [], $visible_types);
      if (!empty($field_annotations)) {
        $fields_with_annotations[$field_name] = $field_annotations;
      }
    }

    $has_main_overlays = !empty($bundle_annotations) || !empty($fields_with_annotations);

    if ($has_main_overlays) {
      // Bundle trigger — floated right so it sits at the top of the form.
      if (!empty($bundle_annotations)) {
        $form['annotations_bundle_trigger'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => [
              'annotations-overlay-trigger',
              'annotations-overlay-trigger--bundle',
              'js-annotations-overlay-trigger',
            ],
            'data-annotations-field' => '_bundle',
            'aria-label' => (string) $this->t('About @label', ['@label' => $target->label()]),
          ],
          '#value' => Markup::create('<span aria-hidden="true">?</span>'),
          '#weight' => -1000,
        ];
      }

      // Field triggers — injected as the first child of each field container.
      // CSS positions them top-right within the container, near the label.
      foreach (array_keys($fields_with_annotations) as $field_name) {
        if (!isset($form[$field_name])) {
          continue;
        }
        $field_label = $this->resolveFieldLabel($entity_type_id, $bundle, $field_name);
        $form[$field_name]['#attributes']['data-annotations-field'] = $field_name;
        $form[$field_name]['annotations_overlay_trigger'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => ['annotations-overlay-trigger', 'js-annotations-overlay-trigger'],
            'data-annotations-field' => $field_name,
            'aria-label' => (string) $this->t('Documentation for @field', ['@field' => $field_label]),
          ],
          '#value' => Markup::create('<span aria-hidden="true">?</span>'),
          '#weight' => -100,
        ];
      }

      $single_type = $this->isSingleType($bundle_annotations, ...$fields_with_annotations);

      $dialogs = [];

      if (!empty($bundle_annotations)) {
        $dialogs['_bundle'] = $this->buildDialog('_bundle', (string) $target->label(), $bundle_annotations, $single_type);
      }

      foreach ($fields_with_annotations as $field_name => $annotations) {
        $label = $this->resolveFieldLabel($entity_type_id, $bundle, $field_name);
        $dialogs[$field_name] = $this->buildDialog($field_name, $label, $annotations, $single_type);
      }

      $form['annotations_overlay_dialogs'] = [
        '#type' => 'container',
        '#weight' => 998,
        'dialogs' => $dialogs,
      ];
    }

    // Inject overlays into inline paragraph subforms. Dialogs go inside the
    // Paragraphs field wrapper so they survive AJAX replacement when a new
    // paragraph is added via the "Add …" button.
    $has_para_overlays = $this->injectParagraphSubformOverlays($form, $visible_types);

    if ($has_main_overlays || $has_para_overlays) {
      $form['#attached']['library'][] = 'annotations_overlay/overlay';
      $form['#attributes']['class'][] = 'annotations-has-overlay';
    }
  }

  /**
   * Implements hook_entity_view_alter().
   *
   * Injects field-level "?" triggers and annotation dialogs into entity view
   * pages. Only fires when the annotations_overlay field is placed in an
   * active display region — site builders opt in per view mode via Manage
   * Display. The formatter's annotation_view_mode setting controls which view
   * mode is used to render annotation entities inside dialogs.
   *
   * Triggers are added as top-level build siblings (not nested inside field
   * render elements) because field.html.twig does not render arbitrary
   * children. CSS positions them relative to the entity view output.
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    // Paragraphs have no standalone view page; skip to avoid decorating every
    // paragraph rendered inside a node view.
    if ($entity->getEntityTypeId() === 'paragraph') {
      return;
    }

    $components = $display->getComponents();

    // Only fire when the annotations_overlay field is enabled in this display.
    if (!isset($components['annotations_overlay'])) {
      return;
    }

    $annotation_view_mode = $components['annotations_overlay']['settings']['annotation_view_mode'] ?? 'overlay';

    // Merge cache metadata so permission/annotation changes correctly invalidate
    // cached view pages.
    $this->mergeCacheMetadata($build);

    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $target_id = $entity_type_id . '__' . $bundle;

    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $target */
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if ($target === NULL) {
      return;
    }

    $visible_types = $this->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
    $bundle_annotations = $this->filterAnnotationEntities($entity_map[''] ?? [], $visible_types);

    // Only inject triggers for fields that are both in annotation scope and
    // rendered in this display mode.
    $rendered_fields = array_keys($components);
    $fields_with_annotations = [];
    foreach (array_keys($target->getFields()) as $field_name) {
      if (!in_array($field_name, $rendered_fields, TRUE)) {
        continue;
      }
      $field_annotations = $this->filterAnnotationEntities($entity_map[$field_name] ?? [], $visible_types);
      if (!empty($field_annotations)) {
        $fields_with_annotations[$field_name] = $field_annotations;
      }
    }

    if (empty($bundle_annotations) && empty($fields_with_annotations)) {
      return;
    }

    // Bundle trigger at top of entity view.
    if (!empty($bundle_annotations)) {
      $build['annotations_bundle_trigger'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => [
            'annotations-overlay-trigger',
            'annotations-overlay-trigger--bundle',
            'js-annotations-overlay-trigger',
          ],
          'data-annotations-field' => '_bundle',
          'aria-label' => (string) $this->t('About @label', ['@label' => $target->label()]),
        ],
        '#value' => Markup::create('<span aria-hidden="true">?</span>'),
        '#weight' => -1000,
      ];
    }

    /**
     * Field triggers — added as top-level build siblings because
     * field.html.twig does not render arbitrary children.
     * data-annotations-field on the field element itself allows CSS to
     * associate the trigger with its field.
     */
    foreach (array_keys($fields_with_annotations) as $field_name) {
      $field_label = $this->resolveFieldLabel($entity_type_id, $bundle, $field_name);
      if (isset($build[$field_name])) {
        $build[$field_name]['#attributes']['data-annotations-field'] = $field_name;
      }
      $build['annotations_trigger__' . $field_name] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['annotations-overlay-trigger', 'js-annotations-overlay-trigger'],
          'data-annotations-field' => $field_name,
          'aria-label' => (string) $this->t('Documentation for @field', ['@field' => $field_label]),
        ],
        '#value' => Markup::create('<span aria-hidden="true">?</span>'),
        '#weight' => -100,
      ];
    }

    $single_type = $this->isSingleType($bundle_annotations, ...$fields_with_annotations);
    $dialogs = [];

    if (!empty($bundle_annotations)) {
      $dialogs['_bundle'] = $this->buildDialog('_bundle', (string) $target->label(), $bundle_annotations, $single_type, $annotation_view_mode);
    }
    foreach ($fields_with_annotations as $field_name => $annotations) {
      $label = $this->resolveFieldLabel($entity_type_id, $bundle, $field_name);
      $dialogs[$field_name] = $this->buildDialog($field_name, $label, $annotations, $single_type, $annotation_view_mode);
    }

    $build['annotations_overlay_dialogs'] = [
      '#type' => 'container',
      '#weight' => 998,
      'dialogs' => $dialogs,
    ];

    $build['#attached']['library'][] = 'annotations_overlay/overlay';
    $build['#attributes']['class'][] = 'annotations-has-overlay';
  }

  /**
   * Implements hook_form_FORM_ID_alter() for annotations_settings.
   *
   * Adds the bundle chooser overview setting to the general Annotations
   * settings form. The setting lives in annotations_overlay.settings and is
   * saved via a submit closure so the root annotations module stays unaware
   * of overlay-specific config.
   */
  #[Hook('form_annotations_settings_alter')]
  public function formAnnotationsSettingsAlter(array &$form, FormStateInterface $form_state): void {
    $form['overlay'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Overlay'),
    ];
    $form['overlay']['show_bundle_chooser_overview'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show overviews on entity select screens'),
      '#description' => $this->t('Appends bundle-level annotation overviews as supplementary description text on entity type chooser pages (e.g. <em>/node/add</em>, <em>/media/add</em>).'),
      '#default_value' => $this->configFactory->get('annotations_overlay.settings')->get('show_bundle_chooser_overview'),
    ];

    $config_factory = $this->configFactory;
    $form['#submit'][] = static function (array &$form, FormStateInterface $form_state) use ($config_factory): void {
      $config_factory->getEditable('annotations_overlay.settings')
        ->set('show_bundle_chooser_overview', (bool) $form_state->getValue('show_bundle_chooser_overview'))
        ->save();
    };
  }

  /**
   * Implements hook_preprocess_HOOK() for node_add_list.
   *
   * Appends bundle-level annotation content to each content type's description
   * on the /node/add chooser page. Annotation entities are rendered via the
   * 'overlay' view mode, so Manage Display controls what appears.
   */
  #[Hook('preprocess_node_add_list')]
  public function preprocessNodeAddList(array &$variables): void {
    if (!$this->configFactory->get('annotations_overlay.settings')->get('show_bundle_chooser_overview')) {
      return;
    }
    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $visible_types = $this->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $modified = FALSE;

    // Claro/Gin theme preprocess (claro_preprocess_node_add_list) runs after all
    // module preprocesses and rebuilds $variables['bundles'] directly from
    // $variables['content'] entity objects using $type->getDescription(). It
    // ignores $variables['types'] entirely. Modifying the entity description in
    // memory here ensures Claro/Gin reads our annotation text. No save is triggered.
    foreach ($variables['content'] ?? [] as $type) {
      $chooser = $this->buildBundleAnnotationRenderItems('node__' . $type->id(), $visible_types);
      if ($chooser === NULL) {
        continue;
      }
      $chooser['#description'] = $type->getDescription() ?? '';
      $type->set('description', Markup::create(
        (string) $this->renderer->renderInIsolation($chooser)
      ));
      $modified = TRUE;
    }

    // Also update $variables['types'] for themes (non-Claro/Gin) that read the
    // 'types' key directly from the core node_add_list preprocess output.
    foreach (array_keys($variables['types'] ?? []) as $type_id) {
      $chooser = $this->buildBundleAnnotationRenderItems('node__' . $type_id, $visible_types);
      if ($chooser === NULL) {
        continue;
      }
      $chooser['#description'] = $variables['types'][$type_id]['description'] ?? [];
      $variables['types'][$type_id]['description'] = $chooser;
      $modified = TRUE;
    }

    if ($modified) {
      $variables['#attached']['library'][] = 'annotations_overlay/overlay';
      $variables['#cache']['tags'] = array_unique(array_merge(
        $variables['#cache']['tags'] ?? [],
        ['annotation_list', 'annotation_target_list', 'annotation_type_list'],
      ));
      $variables['#cache']['contexts'] = array_unique(array_merge(
        $variables['#cache']['contexts'] ?? [],
        ['user.permissions', 'languages:language_interface'],
      ));
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for entity_add_list.
   *
   * Appends bundle-level annotation content on generic entity bundle chooser
   * pages (any entity type using EntityController::addPage()).
   */
  #[Hook('preprocess_entity_add_list')]
  public function preprocessEntityAddList(array &$variables): void {
    if (!$this->configFactory->get('annotations_overlay.settings')->get('show_bundle_chooser_overview')) {
      return;
    }
    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $entity_type_id = $this->routeMatch->getRawParameter('entity_type_id');
    if (!$entity_type_id) {
      return;
    }

    $visible_types = $this->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $modified = FALSE;
    foreach (array_keys($variables['bundles'] ?? []) as $bundle_id) {
      $chooser = $this->buildBundleAnnotationRenderItems($entity_type_id . '__' . $bundle_id, $visible_types);
      if ($chooser === NULL) {
        continue;
      }
      $chooser['#description'] = $variables['bundles'][$bundle_id]['description'] ?? [];
      $variables['bundles'][$bundle_id]['description'] = $chooser;
      $modified = TRUE;
    }

    if ($modified) {
      $variables['#attached']['library'][] = 'annotations_overlay/overlay';
      $variables['#cache']['tags'] = array_unique(array_merge(
        $variables['#cache']['tags'] ?? [],
        ['annotation_list', 'annotation_target_list', 'annotation_type_list'],
      ));
      $variables['#cache']['contexts'] = array_unique(array_merge(
        $variables['#cache']['contexts'] ?? [],
        ['user.permissions', 'languages:language_interface'],
      ));
    }
  }

  /**
   * Merges annotation cache metadata into a render array.
   *
   * Must be called in hook_entity_view_alter for all paths past the structural
   * guards (admin route, display mode, entity type) so that permission changes
   * and annotation saves correctly invalidate cached admin view pages.
   */
  private function mergeCacheMetadata(array &$build): void {
    $cache = CacheableMetadata::createFromRenderArray($build);
    $cache->addCacheTags(['annotation_list', 'annotation_target_list', 'annotation_type_list']);
    $contexts = ['user.permissions', 'languages:language_interface'];
    if ($this->languageManager->isMultilingual()) {
      $contexts[] = 'languages:content';
    }
    $cache->addCacheContexts($contexts);
    $cache->applyTo($build);
  }

  /**
   * Loads annotation types visible to the current user, sorted by weight.
   *
   * @return \Drupal\annotations\Entity\AnnotationTypeInterface[]
   */
  private function loadVisibleAnnotationTypes(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all_types */
    $all_types = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
    uasort($all_types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    return array_filter($all_types, fn($t) => $this->currentUser->hasPermission($t->getConsumePermission()));
  }

  /**
   * Builds a render array of bundle-level annotation entities for chooser pages.
   *
   * Annotation entities are rendered via the 'overlay' view mode so that
   * Manage Display controls which fields appear. Returns NULL when no target
   * exists or no visible, published bundle annotations are found.
   *
   * @param string $target_id
   *   e.g. 'node__article' or 'media__image'.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Pre-loaded visible annotation types.
   *
   * @return array|null
   *   Render array wrapping one rendered entity per visible type, or NULL.
   */
  private function buildBundleAnnotationRenderItems(string $target_id, array $visible_types): ?array {
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if ($target === NULL) {
      return NULL;
    }

    $entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
    $bundle_annotations = $this->filterAnnotationEntities($entity_map[''] ?? [], $visible_types);
    if (empty($bundle_annotations)) {
      return NULL;
    }

    $view_builder = $this->entityTypeManager->getViewBuilder('annotation');
    $items = [];
    foreach ($bundle_annotations as $type_id => $entity) {
      $type = $this->entityTypeManager->getStorage('annotation_type')->load($type_id);
      $items[$type_id] = [
        '#theme' => 'annotations_overlay_chooser_item',
        '#type_id' => $type_id,
        '#type_label' => $type ? (string) $type->label() : $type_id,
        '#content' => $view_builder->view($entity, 'overlay'),
      ];
    }

    return [
      '#theme' => 'annotations_overlay_chooser',
      '#description' => NULL,
      '#items' => $items,
    ];
  }

  /**
   * Filters annotation entities to only those the user can see.
   *
   * @param array<string, \Drupal\annotations\Entity\Annotation> $entities
   *   Annotation entities keyed by type_id.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *
   * @return array<string, \Drupal\annotations\Entity\Annotation>
   */
  private function filterAnnotationEntities(array $entities, array $visible_types): array {
    $result = [];
    foreach ($visible_types as $type_id => $type) {
      if (isset($entities[$type_id])) {
        $result[$type_id] = $entities[$type_id];
      }
    }
    return $result;
  }

  /**
   * Returns TRUE when exactly one annotation type appears across all given maps.
   *
   * Each argument is an annotation entity map keyed by type_id. Accepts
   * variadic maps so callers can spread a fields array directly:
   *
   * @code
   *   $this->isSingleType($bundle_annotations, ...$fields_with_annotations);
   * @endcode
   *
   * @param array<string, \Drupal\annotations\Entity\Annotation> ...$annotation_maps
   *   One or more annotation entity maps keyed by type_id.
   */
  private function isSingleType(array ...$annotation_maps): bool {
    $type_ids = [];
    foreach ($annotation_maps as $map) {
      array_push($type_ids, ...array_keys($map));
    }
    return count(array_unique($type_ids)) === 1;
  }

  /**
   * Builds a dialog render element for one field's annotations.
   *
   * The <dialog> element is natively hidden until JS calls showModal() on it.
   * Content is fully rendered server-side.
   *
   * @param array<string, \Drupal\annotations\Entity\Annotation> $annotation_entities
   *   Annotation entities keyed by type_id.
   * @param bool $single_type
   *   TRUE when only one annotation type is expressed across all dialogs on the
   *   page for the current user. When TRUE the type heading is suppressed.
   */
  private function buildDialog(string $field_key, string $label, array $annotation_entities, bool $single_type, string $view_mode = 'overlay'): array {
    $view_builder = $this->entityTypeManager->getViewBuilder('annotation');
    $items = [];
    foreach ($annotation_entities as $type_id => $entity) {
      $type = $this->entityTypeManager->getStorage('annotation_type')->load($type_id);
      $items[$type_id] = [
        '#theme' => 'annotations_overlay_item',
        '#type_id' => $type_id,
        '#type_label' => $type ? (string) $type->label() : $type_id,
        '#content' => $view_builder->view($entity, $view_mode),
        '#edit_url' => $this->buildEditUrl($entity, $type_id),
        '#single_type' => $single_type,
      ];
    }

    return [
      '#theme' => 'annotations_overlay_wrapper',
      '#heading' => $label,
      '#items' => $items,
      '#close_label' => $this->t('Close'),
      '#attributes' => new Attribute([
        'class' => ['annotations-overlay-dialog'],
        'data-annotations-field' => $field_key,
        'aria-modal' => 'true',
        'aria-label' => $label,
      ]),
      '#close_attributes' => new Attribute([
        'type' => 'button',
        'class' => ['button', 'button--extrasmall', 'annotations-overlay-close'],
      ]),
    ];
  }

  /**
   * Builds an edit link for an annotation if the current user has permission.
   *
   * Returns NULL if the user cannot edit the annotation.
   *
   * @param \Drupal\annotations\Entity\Annotation $entity
   *   The annotation entity.
   * @param string $type_id
   *   The annotation type machine name.
   */
  private function buildEditUrl(Annotation $entity, string $type_id): ?array {
    if (!$this->currentUser->hasPermission('edit ' . $type_id . ' annotations')
      && !$this->currentUser->hasPermission('edit any annotation')) {
      return NULL;
    }
    return [
      '#type' => 'link',
      '#title' => Markup::create(
        '<span aria-hidden="true">' . AnnotationsGlyph::PENCIL . '</span>'
        . '<span class="visually-hidden">' . $this->t('Edit') . '</span>'
      ),
      '#url' => $entity->toUrl('edit-form'),
      '#attributes' => [
        'class' => ['annotations-overlay-edit-link'],
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];
  }

  /**
   * Returns the human-readable label for a field from its config entity.
   */
  private function resolveFieldLabel(string $entity_type_id, string $bundle, string $field_name): string {
    /** @var \Drupal\field\FieldConfigInterface|null $config */
    $config = $this->entityTypeManager
      ->getStorage('field_config')
      ->load($entity_type_id . '.' . $bundle . '.' . $field_name);
    return $config !== NULL ? (string) $config->label() : $field_name;
  }

  /**
   * Injects overlay triggers and dialogs into inline paragraph subforms.
   *
   * Called from hook_form_alter() after main-entity overlay injection. Dialogs
   * are placed inside the Paragraphs field wrapper element rather than the
   * top-level dialogs container, so they are included in the AJAX replacement
   * response when a paragraph is added via the "Add …" button.
   *
   * Field keys for paragraph fields are prefixed with "para__{bundle}__" to
   * prevent collisions with parent-form field names sharing the same machine
   * name (e.g. both node and paragraph having a "localgov_image" field).
   *
   * Works without a hard dependency on the Paragraphs module: the check is
   * purely structural — if the expected array keys are absent the loop is
   * a no-op.
   *
   * @param array $form
   *   The form render array, passed by reference.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Pre-loaded annotation types visible to the current user.
   *
   * @return bool
   *   TRUE if at least one paragraph subform received overlays.
   */
  private function injectParagraphSubformOverlays(array &$form, array $visible_types): bool {
    $injected = FALSE;

    foreach (array_keys($form) as $field_name) {
      if (str_starts_with((string) $field_name, '#')) {
        continue;
      }
      if (!isset($form[$field_name]['widget']) || !is_array($form[$field_name]['widget'])) {
        continue;
      }

      foreach (array_keys($form[$field_name]['widget']) as $delta) {
        if (!is_numeric($delta)) {
          continue;
        }
        $item = &$form[$field_name]['widget'][$delta];
        if (!isset($item['subform'], $item['#paragraph_type'])) {
          continue;
        }

        $para_bundle = $item['#paragraph_type'];
        $para_target_id = 'paragraph__' . $para_bundle;

        /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $para_target */
        $para_target = $this->entityTypeManager->getStorage('annotation_target')->load($para_target_id);
        if ($para_target === NULL) {
          continue;
        }

        $entity_map = $this->annotationStorage->getEntityMapForTarget($para_target_id, TRUE);
        $para_bundle_annotations = $this->filterAnnotationEntities($entity_map[''] ?? [], $visible_types);

        $para_fields_with_annotations = [];
        foreach (array_keys($para_target->getFields()) as $para_field_name) {
          $field_annotations = $this->filterAnnotationEntities($entity_map[$para_field_name] ?? [], $visible_types);
          if (!empty($field_annotations)) {
            $para_fields_with_annotations[$para_field_name] = $field_annotations;
          }
        }

        if (empty($para_bundle_annotations) && empty($para_fields_with_annotations)) {
          continue;
        }

        $prefix = 'para__' . $para_bundle . '__';

        // Bundle trigger at the top of the subform.
        if (!empty($para_bundle_annotations)) {
          $item['subform']['annotations_bundle_trigger'] = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => ['annotations-overlay-trigger', 'annotations-overlay-trigger--bundle', 'js-annotations-overlay-trigger'],
              'data-annotations-field' => $prefix . '_bundle',
              'aria-label' => (string) $this->t('About @label', ['@label' => $para_target->label()]),
            ],
            '#value' => Markup::create('<span aria-hidden="true">?</span>'),
            '#weight' => -1000,
          ];
        }

        // Field triggers inside the paragraph subform.
        foreach (array_keys($para_fields_with_annotations) as $para_field_name) {
          if (!isset($item['subform'][$para_field_name])) {
            continue;
          }
          $item['subform'][$para_field_name]['#attributes']['data-annotations-field'] = $prefix . $para_field_name;
          $item['subform'][$para_field_name]['annotations_overlay_trigger'] = [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'type' => 'button',
              'class' => ['annotations-overlay-trigger', 'js-annotations-overlay-trigger'],
              'data-annotations-field' => $prefix . $para_field_name,
              'aria-label' => (string) $this->t('Documentation for @field', ['@field' => $this->resolveFieldLabel('paragraph', $para_bundle, $para_field_name)]),
            ],
            '#value' => Markup::create('<span aria-hidden="true">?</span>'),
            '#weight' => -100,
          ];
        }

        $para_single_type = $this->isSingleType($para_bundle_annotations, ...$para_fields_with_annotations);

        // Dialogs inside the Paragraphs field wrapper (not the global dialogs
        // container) so they are present after the AJAX replacement.
        $dialogs = [];
        if (!empty($para_bundle_annotations)) {
          $dialogs[$prefix . '_bundle'] = $this->buildDialog(
            $prefix . '_bundle',
            (string) $para_target->label(),
            $para_bundle_annotations,
            $para_single_type,
          );
        }
        foreach ($para_fields_with_annotations as $para_field_name => $annotations) {
          $label = $this->resolveFieldLabel('paragraph', $para_bundle, $para_field_name);
          $dialogs[$prefix . $para_field_name] = $this->buildDialog(
            $prefix . $para_field_name,
            $label,
            $annotations,
            $para_single_type,
          );
        }

        $form[$field_name]['widget']['annotations_para_dialogs__' . $delta] = [
          '#type' => 'container',
          '#weight' => 999,
          'dialogs' => $dialogs,
        ];

        $injected = TRUE;
      }
    }

    return $injected;
  }

  /**
   * Implements hook_entity_view_alter() for annotation entities.
   *
   * Adds a "Preview overlay" button and dialog to the annotation View page,
   * showing exactly how this annotation appears in the overlay modal context.
   * Only fires on the full (canonical) display mode so the overlay view mode
   * itself is unaffected.
   */
  #[Hook('entity_view_alter')]
  public function annotationPreviewViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
    if ($entity->getEntityTypeId() !== 'annotation') {
      return;
    }
    // The canonical view page uses the 'default' display (no explicit 'full'
    // display is configured). Skip the overlay view mode to avoid recursion.
    if (!in_array($display->getMode(), ['full', 'default'], TRUE)) {
      return;
    }

    $build['#cache']['contexts'][] = 'user.permissions';

    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    /** @var \Drupal\annotations\Entity\Annotation $annotation */
    $annotation = $entity;
    $type_id    = $annotation->get('type_id')->value ?? '';
    $field_name = $annotation->get('field_name')->value ?? '';
    $target_id  = $annotation->get('target_id')->value ?? '';

    if ($type_id === '') {
      return;
    }

    if ($field_name === '') {
      $heading = (string) $this->t('Overview');
    }
    else {
      /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $target */
      $target = $target_id !== ''
        ? $this->entityTypeManager->getStorage('annotation_target')->load($target_id)
        : NULL;
      $heading = $target !== NULL
        ? $this->resolveFieldLabel($target->getTargetEntityTypeId(), $target->getBundle(), $field_name)
        : $field_name;
    }

    $roles = array_filter(
      $this->currentUser->getRoles(),
      fn($r) => $r !== 'authenticated',
    );
    $role_labels = array_map(function (string $role_id): string {
      $role = $this->entityTypeManager->getStorage('user_role')->load($role_id);
      return $role ? (string) $role->label() : $role_id;
    }, $roles);

    $build['annotations_preview_trigger'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'js-annotations-overlay-trigger'],
        'data-annotations-field' => '_preview',
        'title' => $role_labels
          ? (string) $this->t('Previewing as: @roles', ['@roles' => implode(', ', $role_labels)])
          : (string) $this->t('Previewing as: authenticated user'),
      ],
      '#value' => Markup::create(
        '<span aria-hidden="true">?</span> ' . $this->t('Overlay preview')
      ),
      '#weight' => -500,
    ];

    $build['annotations_preview_dialog'] = [
      '#type' => 'container',
      '#weight' => 999,
      'dialog' => $this->buildDialog('_preview', $heading, [$type_id => $annotation], TRUE),
    ];

    $build['#attached']['library'][] = 'annotations_overlay/overlay';
    $build['#attributes']['class'][] = 'annotations-has-overlay';
  }

}
