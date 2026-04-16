<?php

declare(strict_types=1);

namespace Drupal\annotations\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationStorageService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting AnnotationTarget entities that have annotation data.
 *
 * Reached automatically from TargetOverviewForm when an unticked target
 * has non-empty annotation text in annotation rows. Lists each target so the
 * admin can make an informed decision before permanent deletion.
 */
class TargetDeleteConfirmForm extends ConfirmFormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationStorageService $annotationStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('annotations.annotation_storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_target_delete_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete target configuration?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t(
      'The following targets have annotation data that will be permanently deleted. '
      . 'This action cannot be undone.'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.annotation_target.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $ids     = $this->getRequest()->query->all('ids');
    $targets = $this->loadTargetsById((array) $ids);

    if (empty($targets)) {
      $this->messenger()->addWarning($this->t('No targets pending deletion.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return [];
    }

    $form = parent::buildForm($form, $form_state);

    $form['pending_ids'] = [
      '#type'  => 'hidden',
      '#value' => implode(',', array_keys($targets)),
    ];

    $rows = [];
    foreach ($targets as $target) {
      $field_count = count($target->getFields());
      $rows[] = [
        $target->label(),
        $target->id(),
        $field_count ? $this->formatPlural($field_count, '1 field', '@count fields') : $this->t('None'),
      ];
    }

    $form['target_list'] = [
      '#type'    => 'table',
      '#caption' => $this->t('The following targets and their annotation data will be deleted:'),
      '#header'  => [
        $this->t('Label'),
        $this->t('ID'),
        $this->t('Fields included'),
      ],
      '#rows'    => $rows,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids     = array_filter(explode(',', $form_state->getValue('pending_ids', '')));
    $targets = $this->loadTargetsById($ids);

    foreach ($targets as $target) {
      $this->annotationStorage->deleteForTarget($target->id());
      $target->delete();
    }

    $this->messenger()->addStatus($this->t(
      '@count target(s) deleted.',
      ['@count' => count($targets)]
    ));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * @param string[] $ids
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   */
  protected function loadTargetsById(array $ids): array {
    if (empty($ids)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('annotation_target')->loadMultiple($ids) ?: [];
  }

}
