<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\Entity\Annotation;
use Drupal\annotations\Entity\AnnotationTarget;
use Drupal\annotations_ui\AnnotationTitleTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for a single annotation entity.
 *
 * Primary editing interface for individual annotation values. Reached via
 * the inline edit link on the annotations_target Views page. Redirects back
 * to that view on save.
 */
class AnnotationEditForm extends ContentEntityForm {

  use AnnotationTitleTrait;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Title callback: resolves human-readable context labels from the entity.
   */
  public static function title(Annotation $annotation): TranslatableMarkup {
    $parts = static::resolveAnnotationTitleParts($annotation);
    return t('Edit annotation: <em>@target &rsaquo; @field &rsaquo; @type</em>', $parts);
  }

  /**
   * {@inheritdoc}
   *
   * Default the "Create new revision" checkbox to checked. AnnotationType
   * does not implement RevisionableEntityBundleInterface so the parent would
   * return FALSE; we override to TRUE so revision-on-save is the default.
   * Editors can uncheck for trivial fixes; content_moderation hides the
   * checkbox entirely when annotations_workflows is installed.
   */
  protected function getNewRevisionDefault(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Clear the revision log message so the widget is blank for each new edit,
   * matching the behaviour of NodeForm and other core content entity forms.
   */
  public function prepareEntity(): void {
    parent::prepareEntity();
    /** @var \Drupal\annotations\Entity\Annotation $annotation */
    $annotation = $this->entity;
    if (!$annotation->isNew()) {
      $annotation->set('revision_log_message', NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\annotations\Entity\Annotation $annotation */
    $annotation = $this->entity;

    // parent::form() creates 'advanced' as vertical_tabs (show_revision_ui is
    // TRUE on annotation). Gin converts it to the sidebar. Add the
    // entity-meta class to match NodeForm's pattern.
    $form['advanced']['#attributes']['class'][] = 'entity-meta';

    // Status metadata panel — mirrors NodeForm::form() meta section.
    $form['meta'] = [
      '#type' => 'details',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('Status'),
      '#attributes' => ['class' => ['entity-meta__header']],
      '#tree' => TRUE,
    ];

    $form['meta']['changed'] = [
      '#type' => 'item',
      '#title' => $this->t('Last saved'),
      '#markup' => !$annotation->isNew()
        ? $this->dateFormatter->format($annotation->getChangedTime(), 'short')
        : $this->t('Not saved yet'),
      '#wrapper_attributes' => ['class' => ['entity-meta__last-saved']],
    ];

    $owner = $annotation->get('uid')->entity;
    $form['meta']['author'] = [
      '#type' => 'item',
      '#title' => $this->t('Last edited by'),
      '#markup' => $owner?->getDisplayName() ?? $this->t('Unknown'),
      '#wrapper_attributes' => ['class' => ['entity-meta__author']],
    ];

    if ($this->config('annotations_ui.settings')->get('show_field_metadata')) {
      $field_name = (string) $annotation->get('field_name')->value;
      $target_id = (string) $annotation->get('target_id')->value;

      if ($field_name !== '') {
        /** @var \Drupal\annotations\Entity\AnnotationTarget|null $annotation_target */
        $annotation_target = $this->entityTypeManager->getStorage('annotation_target')->load($target_id);
        if ($annotation_target) {
          $panel = $this->buildFieldMetadataPanel($annotation_target, $field_name);
          if ($panel) {
            $form['field_metadata'] = $panel + ['#weight' => -50];
          }
        }
      }
    }

    return $form;
  }

  /**
   * Builds the collapsible field metadata panel for the annotation edit form.
   *
   * Shows field type, cardinality, required status, and description for the
   * specific field being annotated. Returns an empty array when the field
   * definition cannot be found (e.g. non-fieldable target or unknown field).
   *
   * @param \Drupal\annotations\Entity\AnnotationTarget $annotation_target
   *   The target.
   * @param string $field_name
   *   The field machine name.
   */
  private function buildFieldMetadataPanel(AnnotationTarget $annotation_target, string $field_name): array {
    $entity_type_id = $annotation_target->getTargetEntityTypeId();
    if (!$this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }

    $definitions = $this->fieldManager->getFieldDefinitions($entity_type_id, $annotation_target->getBundle());
    if (!isset($definitions[$field_name])) {
      return [];
    }

    $definition = $definitions[$field_name];
    $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
    $cardinality_label = $cardinality === -1 ? (string) $this->t('Unlimited') : (string) $cardinality;

    $rows = [
      [['data' => ['#plain_text' => (string) $this->t('Machine name')]], ['data' => ['#markup' => '<code>' . Html::escape($field_name) . '</code>']]],
      [['data' => ['#plain_text' => (string) $this->t('Type')]], ['data' => ['#plain_text' => $definition->getType()]]],
      [['data' => ['#plain_text' => (string) $this->t('Cardinality')]], ['data' => ['#plain_text' => $cardinality_label]]],
      [['data' => ['#plain_text' => (string) $this->t('Required')]], ['data' => ['#plain_text' => (string) ($definition->isRequired() ? $this->t('Yes') : $this->t('No'))]]],
    ];
    if ($description = (string) $definition->getDescription()) {
      $rows[] = [['data' => ['#plain_text' => (string) $this->t('Description')]], ['data' => ['#plain_text' => $description]]];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('Metadata'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#rows' => $rows,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    /** @var \Drupal\annotations\Entity\Annotation $annotation */
    $annotation = $this->entity;
    $target_id = (string) $annotation->get('target_id')->value;

    $destination = (string) $this->getRequest()->query->get('destination', '');
    $cancel_url = ($destination !== '' && str_starts_with($destination, '/'))
      ? Url::fromUserInput($destination)
      : Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $target_id]);

    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $cancel_url,
      '#attributes' => ['class' => ['button']],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\annotations\Entity\Annotation $annotation */
    $annotation = $this->entity;
    $annotation->set('uid', $this->currentUser()->id());
    $annotation->setRevisionUserId($this->currentUser()->id());
    $annotation->setRevisionCreationTime($this->time->getRequestTime());
    $result = $annotation->save();

    $target_id = (string) $annotation->get('target_id')->value;
    $destination = (string) $this->getRequest()->query->get('destination', '');
    if ($destination !== '' && str_starts_with($destination, '/')) {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
    }
    else {
      $form_state->setRedirectUrl(
        Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $target_id])
      );
    }

    return is_int($result) ? $result : SAVED_UPDATED;
  }

}
