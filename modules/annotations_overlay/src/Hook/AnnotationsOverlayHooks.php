<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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
  ) {}

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
      // Single annotation item — type heading, entity content, optional edit link.
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

    // Consumer context: only show published annotations. When annotations_workflow is
    // not installed, entities have no moderation_state field and the 'published'
    // filter is a silent no-op — all non-empty values are returned.
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
            'class' => ['annotations-overlay-trigger', 'annotations-overlay-trigger--bundle', 'js-annotations-overlay-trigger'],
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
   * Implements hook_preprocess_HOOK() for node_add_list.
   *
   * Appends bundle-level annotation text to each content type's description on
   * the /node/add chooser page.
   */
  #[Hook('preprocess_node_add_list')]
  public function preprocessNodeAddList(array &$variables): void {
    if (!$this->currentUser->hasPermission('view annotations overlay')) {
      return;
    }

    $visible_types = $this->loadVisibleAnnotationTypes();
    if (empty($visible_types)) {
      return;
    }

    $modified = FALSE;
    foreach (array_keys($variables['types']) as $type_id) {
      $element = $this->buildBundleAnnotationElement('node__' . $type_id, $visible_types);
      if ($element !== NULL) {
        $variables['types'][$type_id]['description'] = [
          'original' => $variables['types'][$type_id]['description'],
          'annotation_overlay' => $element,
        ];
        $modified = TRUE;
      }
    }

    if ($modified) {
      $variables['#attached']['library'][] = 'annotations_overlay/overlay';
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for entity_add_list.
   *
   * Appends bundle-level annotation text on generic entity bundle chooser
   * pages (any entity type using EntityController::addPage()).
   */
  #[Hook('preprocess_entity_add_list')]
  public function preprocessEntityAddList(array &$variables): void {
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
    foreach (array_keys($variables['bundles']) as $bundle_id) {
      $element = $this->buildBundleAnnotationElement($entity_type_id . '__' . $bundle_id, $visible_types);
      if ($element !== NULL) {
        $variables['bundles'][$bundle_id]['description'] = [
          'original' => $variables['bundles'][$bundle_id]['description'],
          'annotation_overlay' => $element,
        ];
        $modified = TRUE;
      }
    }

    if ($modified) {
      $variables['#attached']['library'][] = 'annotations_overlay/overlay';
    }
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
   * Builds a render element containing bundle-level annotation text.
   *
   * Returns NULL if no annotation_target exists for $target_id, or if no
   * visible, non-empty bundle annotations exist.
   *
   * @param string $target_id
   *   e.g. 'node__article' or 'media__image'.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Pre-loaded visible annotation types.
   */
  private function buildBundleAnnotationElement(string $target_id, array $visible_types): ?array {
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
    $single_type = $this->isSingleType($bundle_annotations);
    $items = [];
    foreach ($bundle_annotations as $type_id => $entity) {
      $type = $this->entityTypeManager->getStorage('annotation_type')->load($type_id);
      $items[$type_id] = [
        '#theme' => 'annotations_overlay_item',
        '#type_id' => $type_id,
        '#type_label' => $type ? (string) $type->label() : $type_id,
        '#content' => $view_builder->view($entity, 'overlay'),
        '#edit_url' => $this->buildEditUrl($entity, $type_id),
        '#single_type' => $single_type,
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['annotations-bundle-annotation']],
      'items' => $items,
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
  private function buildDialog(string $field_key, string $label, array $annotation_entities, bool $single_type): array {
    $view_builder = $this->entityTypeManager->getViewBuilder('annotation');
    $items = [];
    foreach ($annotation_entities as $type_id => $entity) {
      $type = $this->entityTypeManager->getStorage('annotation_type')->load($type_id);
      $items[$type_id] = [
        '#theme' => 'annotations_overlay_item',
        '#type_id' => $type_id,
        '#type_label' => $type ? (string) $type->label() : $type_id,
        '#content' => $view_builder->view($entity, 'overlay'),
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

}
