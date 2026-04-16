<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\Entity\AnnotationTarget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting all annotations for a single annotation_target.
 */
class TargetAnnotationsDeleteForm extends ConfirmFormBase {

  protected AnnotationTarget $target;

  public function __construct(
    private readonly AnnotationStorageService $annotationStorage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations.annotation_storage'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'annotations_ui_target_delete_all';
  }

  /**
   * Sets the target entity from the route and builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AnnotationTarget $annotation_target = NULL): array {
    $this->target = $annotation_target;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete annotations for %label?', ['%label' => $this->target->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This will permanently delete every annotation record for this target, including all revisions. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete annotations');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $this->target->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->annotationStorage->deleteForTarget($this->target->id());
    $this->messenger()->addStatus($this->t('All annotations for %label have been deleted.', ['%label' => $this->target->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('annotations_ui.annotate.collection'));
  }

}
