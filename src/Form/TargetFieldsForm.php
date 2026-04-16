<?php

declare(strict_types=1);

namespace Drupal\annotations\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\annotations\Plugin\Target\TargetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field inclusion sub-page for a single AnnotationTarget.
 *
 * Only fields listed in the AnnotationTarget's fields map are scanned.
 * Selecting a field adds it to the map (with empty annotation values).
 * Deselecting removes it — annotation data for removed fields is discarded.
 *
 * When a target is first created, all editorial fields are pre-populated so
 * this page starts with everything selected.
 */
class TargetFieldsForm extends EntityForm {

  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface $entity */
    $entity = $this->entity;

    $form['#title'] = $this->t(
      'Configure %label fields',
      [
        '@type' => $this->targetTypeLabel($entity->getTargetEntityTypeId()),
        '%label' => $entity->label(),
      ]
    );

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t(
        'Select the fields to include in annotation. All are selected by default.'
      ),
    ];

    $field_options = $this->getFieldOptions(
      $entity->getTargetEntityTypeId(),
      $entity->getBundle()
    );

    if ($field_options) {
      $in_scope = array_keys($entity->getFields());

      $form['fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Fields'),
        '#options' => $field_options,
        '#default_value' => array_combine($in_scope, $in_scope),
      ];
    }
    else {
      $form['no_fields'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t(
          'No configurable fields found for this target. The target itself can still be annotated.'
        ),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $destination = (string) $this->getRequest()->query->get('destination', '');
    try {
      $cancel_url = $destination !== '' ? Url::fromUserInput($destination) : NULL;
    }
    catch (\InvalidArgumentException) {
      $cancel_url = NULL;
    }
    $cancel_url ??= Url::fromRoute('entity.annotation_target.collection');
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
  protected function copyFormValuesToEntity(
    \Drupal\Core\Entity\EntityInterface $entity,
    array $form,
    FormStateInterface $form_state,
  ): void {
    /** @var \Drupal\annotations\Entity\AnnotationTargetInterface $entity */
    $checked_fields = array_keys(
      array_filter((array) $form_state->getValue('fields', []))
    );

    // Preserve existing annotation data for fields that remain selected.
    // Add an empty entry for newly selected fields.
    // Remove entries for deselected fields — their annotation data is discarded.
    $existing_fields = $entity->getFields();
    $new_fields = [];
    foreach ($checked_fields as $field_name) {
      $new_fields[$field_name] = $existing_fields[$field_name] ?? [];
    }

    $entity->setFields($new_fields);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t(
      'Saved target configuration for %label field(s).',
      ['%label' => $this->entity->label()]
    ));
    $destination = $this->getRequest()->query->get('destination', '');
    if ($destination) {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
    }
    else {
      $form_state->setRedirect('entity.annotation_target.collection');
    }
    return $result;
  }

  /**
   * Returns the singular bundle-type label for the given entity type ID.
   *
   * Uses getBundleLabel() for bundled types (e.g. "Content type") and falls
   * back to getSingularLabel() for unbundled types.
   */
  protected function targetTypeLabel(string $entity_type_id): string {
    $def = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$def) {
      return $entity_type_id;
    }
    if ($def->getBundleEntityType()) {
      return (string) $def->getBundleLabel();
    }
    return (string) ($def->getSingularLabel() ?: $def->getLabel());
  }

  /**
   * Returns fields for the given entity type + bundle.
   *
   * Only configurable fields (field_*) and notable base fields (title, body,
   * name, description) are returned. System and computed fields are excluded.
   *
   * @return array<string, string>
   *   Field machine name to "Label (machine_name)" display string.
   */
  protected function getFieldOptions(
    string $entity_type_id,
    string $bundle_id,
  ): array {
    $options = [];

    if (!$this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
      return $options;
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle_id);
    foreach ($definitions as $field_name => $definition) {
      if (
        !($definition instanceof FieldConfigInterface)
        && !in_array($field_name, TargetBase::NOTABLE_BASE_FIELDS, TRUE)
      ) {
        continue;
      }
      $options[$field_name] = sprintf(
        '%s (%s)',
        $definition->getLabel(),
        $field_name
      );
    }

    ksort($options);
    return $options;
  }

}
