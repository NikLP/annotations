<?php

declare(strict_types=1);

namespace Drupal\annotations\Entity;

use Drupal\Core\Entity\Annotation\ContentEntityType;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the annotation content entity.
 *
 * Stores annotation text for annotation_target entities.
 * Never touched by config sync — survives deploys and is editable on production.
 *
 * Empty string sentinel:
 *   - field_name = '' → bundle-level annotation on the target
 *
 * @ContentEntityType(
 *   id = "annotation",
 *   label = @Translation("Annotation"),
 *   label_collection = @Translation("Annotations"),
 *   base_table = "annotation",
 *   data_table = "annotation_field_data",
 *   revision_table = "annotation_revision",
 *   revision_data_table = "annotation_field_revision",
 *   translatable = TRUE,
 *   show_revision_ui = TRUE,
 *   bundle_entity_type = "annotation_type",
 *   permission_granularity = "entity_type",
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_uid",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message",
 *     "revision_default" = "revision_default",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "revision" = "revision_id",
 *     "bundle" = "type_id",
 *     "published" = "status",
 *     "owner" = "uid",
 *     "langcode" = "langcode",
 *   },
 *   admin_permission = "administer annotations",
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/annotations/value/{annotation}",
 *     "edit-form" = "/admin/content/annotations/value/{annotation}/edit",
 *     "delete-form" = "/admin/content/annotations/value/{annotation}/delete",
 *     "version-history" = "/admin/content/annotations/value/{annotation}/revisions",
 *     "revision" = "/admin/content/annotations/value/{annotation}/revisions/{annotation_revision}/view",
 *   },
 * )
 */
class Annotation extends EditorialContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // NOTE: empty-string sentinels ('', '') are stored as NULL by Drupal's field
    // storage (StringItem::isEmpty() treats '' as empty). All entity queries
    // for these sentinel values must use IS NULL, not = ''. See
    // AnnotationStorageService for the correct query pattern.

    // status: managed by workflow or defaults to 1. Never show on the form.
    $fields['status']
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setDisplayConfigurable('view', FALSE);

    // langcode: hidden by default but configurable so content_translation can
    // surface it when multilingual is enabled.
    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setDisplayConfigurable('view', FALSE)
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE);

    $fields['target_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target ID'))
      ->setDescription(t('The target entity machine name.'))
      ->setSetting('max_length', 255)
      ->setDefaultValue('');

    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field name'))
      ->setDescription(t('The field machine name. NULL = bundle-level annotation.'))
      ->setSetting('max_length', 255)
      ->setDefaultValue('');

    $fields['type_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Annotation type'))
      ->setDescription(t('AnnotationType machine name.'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE)
      ->setDefaultValue('');

    $fields['value'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Value'))
      ->setDefaultValue('')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 0,
        'settings' => ['rows' => 8],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'basic_string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('Timestamp of the last save.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setDisplayConfigurable('view', FALSE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who last saved this annotation.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['region' => 'hidden'])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('view', ['region' => 'hidden'])
      ->setDisplayConfigurable('view', FALSE);

    // revision_log_message comes from EditorialContentEntityBase. Show it as a
    // compact textarea at the bottom of the form.
    $fields['revision_log_message']
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 10,
        'settings' => ['rows' => 2],
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * Computed from target/field/type because there is no single label field.
   */
  public function label(): string {
    $target_id  = $this->get('target_id')->value ?? '';
    $field_name = $this->get('field_name')->value ?? '';
    $type_id    = $this->get('type_id')->value ?? '';

    $etm    = \Drupal::entityTypeManager();
    $target = $target_id !== '' ? $etm->getStorage('annotation_target')->load($target_id) : NULL;
    $type   = $type_id !== '' ? $etm->getStorage('annotation_type')->load($type_id) : NULL;

    $target_label = $target ? (string) $target->label() : ($target_id ?: 'site');
    $type_label   = $type ? (string) $type->label() : ($type_id ?: 'unknown');

    if ($field_name === '') {
      $field_label = (string) t('Overview');
    }
    elseif ($target !== NULL) {
      $defs = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions($target->getTargetEntityTypeId(), $target->getBundle());
      $field_label = isset($defs[$field_name])
        ? (string) $defs[$field_name]->getLabel()
        : $field_name;
    }
    else {
      $field_label = $field_name;
    }

    return sprintf('%s › %s › %s ', $target_label, $field_label, $type_label);
  }

  /**
   * Default value callback for the uid field.
   */
  public static function getDefaultEntityOwner(): array {
    return [['target_id' => \Drupal::currentUser()->id()]];
  }

}
