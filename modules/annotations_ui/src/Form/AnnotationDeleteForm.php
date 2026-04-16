<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Delete confirmation form for a single annotation entity.
 *
 * After deletion, redirects to the parent target's annotation form so the
 * editor can see the remaining annotations in context.
 */
class AnnotationDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete this annotation?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This action cannot be undone.');
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
    return $this->getEntity()->toUrl('edit-form');
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    $entity    = $this->getEntity();
    $target_id = (string) $entity->get('target_id')->value;

    if ($target_id === '') {
      return Url::fromRoute('annotations_ui.annotate.collection');
    }

    return Url::fromRoute('annotations_ui.target.collection', ['annotation_target' => $target_id]);
  }

}
