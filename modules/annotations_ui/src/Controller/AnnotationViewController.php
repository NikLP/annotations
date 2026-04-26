<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\annotations\Entity\Annotation;
use Drupal\annotations_ui\AnnotationTitleTrait;

/**
 * Renders the canonical (read-only) view of an annotation entity.
 */
class AnnotationViewController extends ControllerBase {

  use AnnotationTitleTrait;

  /**
   * Renders the default view mode of an annotation entity.
   */
  public function view(Annotation $annotation): array {
    return $this->entityTypeManager()
      ->getViewBuilder('annotation')
      ->view($annotation, 'full');
  }

  /**
   * Title callback for the canonical view page.
   */
  public static function title(Annotation $annotation): TranslatableMarkup {
    $parts = static::resolveAnnotationTitleParts($annotation);
    return t('Annotation for %target &rsaquo; %field &rsaquo; %type</em>', $parts);
  }

}
