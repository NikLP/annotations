<?php

declare(strict_types=1);

namespace Drupal\annotations_type_ui\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\annotations\AnnotationStorageService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete confirmation form for AnnotationType config entities.
 */
class AnnotationTypeDeleteForm extends EntityDeleteForm {

  public function __construct(
    private readonly AnnotationStorageService $annotationStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('annotations.annotation_storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete annotation type %label?', [
      '%label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $count = $this->annotationStorage->countForType($this->entity->id());
    if ($count === 0) {
      return $this->t(
        'No annotations are stored under this type. The permissions <em>edit %id annotations</em> and <em>consume %id annotations</em> will be removed.',
        ['%id' => $this->entity->id()],
      );
    }
    return $this->formatPlural(
      $count,
      '1 annotation stored under this type will be permanently deleted. The permissions <em>edit %id annotations</em> and <em>consume %id annotations</em> will also be removed.',
      '@count annotations stored under this type will be permanently deleted. The permissions <em>edit %id annotations</em> and <em>consume %id annotations</em> will also be removed.',
      ['%id' => $this->entity->id()],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->annotationStorage->deleteForType($this->entity->id());
    parent::submitForm($form, $form_state);
  }

}
