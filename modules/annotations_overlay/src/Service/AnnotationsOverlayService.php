<?php

declare(strict_types=1);

namespace Drupal\annotations_overlay\Service;

use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\AnnotationsGlyph;
use Drupal\annotations\Entity\Annotation;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;

/**
 * Builds overlay dialog render arrays for annotation targets.
 */
class AnnotationsOverlayService {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationStorageService $annotationStorage,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Loads annotation types visible to the current user, sorted by weight.
   *
   * @return \Drupal\annotations\Entity\AnnotationTypeInterface[]
   *   Annotation types keyed by ID.
   */
  public function loadVisibleAnnotationTypes(): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all_types */
    $all_types = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();
    uasort($all_types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());
    return array_filter($all_types, fn($t) => $this->currentUser->hasPermission($t->getConsumePermission()));
  }

  /**
   * Loads, filters, and builds dialog render arrays for a target.
   *
   * Covers both form and view attachment contexts. The returned arrays contain
   * everything the caller needs to inject triggers and dialogs without touching
   * the entity map or the annotation storage service directly.
   *
   * @param string $target_id
   *   Annotation target ID, e.g. 'node__article'.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Pre-loaded visible annotation types (from loadVisibleAnnotationTypes()).
   * @param string $annotation_view_mode
   *   View mode used to render annotation entities inside dialogs.
   * @param string[] $rendered_fields
   *   When non-empty, only fields in this list are included. Used by the view
   *   alter hook to restrict to fields visible in the active display mode.
   * @param string $key_prefix
   *   Prefix applied to all dialog array keys and data-annotations-field values.
   *   Used by paragraph subform injection to avoid key collisions with parent
   *   form fields that share the same machine name.
   *
   * @return array{
   *   target_label: string,
   *   bundle_annotations: array<string, \Drupal\annotations\Entity\Annotation>,
   *   fields_with_annotations: array<string, array<string, \Drupal\annotations\Entity\Annotation>>,
   *   dialogs: array<string, array>,
   * }|null
   *   NULL when no annotation_target exists for $target_id. Otherwise the
   *   annotation data and built dialog render arrays for this target.
   */
  public function buildDialogsForTarget(
    string $target_id,
    array $visible_types,
    string $annotation_view_mode = 'overlay',
    array $rendered_fields = [],
    string $key_prefix = '',
  ): ?array {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $target */
    $target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
    if ($target === NULL) {
      return NULL;
    }

    $entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
    $bundle_annotations = $this->filterAnnotationEntities($entity_map[''] ?? [], $visible_types);

    $fields_with_annotations = [];
    foreach ($target->getFields() as $field_name) {
      if (!empty($rendered_fields) && !in_array($field_name, $rendered_fields, TRUE)) {
        continue;
      }
      $field_annotations = $this->filterAnnotationEntities($entity_map[$field_name] ?? [], $visible_types);
      if (!empty($field_annotations)) {
        $fields_with_annotations[$field_name] = $field_annotations;
      }
    }

    $single_type = $this->isSingleType($bundle_annotations, ...array_values($fields_with_annotations));

    $entity_type_id = $target->getTargetEntityTypeId();
    $bundle = $target->getBundle();

    $dialogs = [];

    if (!empty($bundle_annotations)) {
      $bundle_key = $key_prefix . '_bundle';
      $dialogs[$bundle_key] = $this->buildDialog(
        $bundle_key,
        (string) $this->t('@label overview', ['@label' => $target->label()]),
        $bundle_annotations,
        $single_type,
        $annotation_view_mode,
      );
    }

    foreach ($fields_with_annotations as $field_name => $annotations) {
      $field_key = $key_prefix . $field_name;
      $dialogs[$field_key] = $this->buildDialog(
        $field_key,
        $this->resolveFieldLabel($entity_type_id, $bundle, $field_name),
        $annotations,
        $single_type,
        $annotation_view_mode,
      );
    }

    return [
      'target_label' => (string) $target->label(),
      'bundle_annotations' => $bundle_annotations,
      'fields_with_annotations' => $fields_with_annotations,
      'dialogs' => $dialogs,
    ];
  }

  /**
   * Builds a dialog render element for one field's annotations.
   *
   * The <dialog> element is natively hidden until JS calls showModal() on it.
   * Content is fully rendered server-side.
   *
   * @param string $field_key
   *   Field machine name, '_bundle', or a prefixed variant for paragraph
   *   subforms; used as the data-annotations-field value on the dialog.
   * @param string $label
   *   Human-readable heading shown at the top of the dialog.
   * @param array<string, \Drupal\annotations\Entity\Annotation> $annotation_entities
   *   Annotation entities keyed by type_id.
   * @param bool $single_type
   *   TRUE when only one annotation type is expressed across all dialogs on
   *   the page for the current user. Suppresses per-item type headings.
   * @param string $view_mode
   *   View mode used to render annotation entities inside the dialog.
   */
  public function buildDialog(
    string $field_key,
    string $label,
    array $annotation_entities,
    bool $single_type,
    string $view_mode = 'overlay',
  ): array {
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
   * Returns the human-readable label for a field from its config entity.
   */
  public function resolveFieldLabel(string $entity_type_id, string $bundle, string $field_name): string {
    /** @var \Drupal\field\FieldConfigInterface|null $config */
    $config = $this->entityTypeManager
      ->getStorage('field_config')
      ->load($entity_type_id . '.' . $bundle . '.' . $field_name);
    $label = $config !== NULL ? (string) $config->label() : $field_name;
    $context = ['entity_type_id' => $entity_type_id, 'bundle' => $bundle, 'field_name' => $field_name];
    $this->moduleHandler->alter('annotations_overlay_field_label', $label, $context);
    return $label;
  }

  /**
   * Filters annotation entities to only those the user can see.
   *
   * @param array<string, \Drupal\annotations\Entity\Annotation> $entities
   *   Annotation entities keyed by type_id.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $visible_types
   *   Annotation types visible to the current user.
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
   * Returns TRUE when exactly 1 annotation type appears across all given maps.
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
   * Builds an edit link for an annotation if the current user has permission.
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

}
