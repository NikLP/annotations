<?php

declare(strict_types=1);

namespace Drupal\annotations_ui\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\annotations_ui\Controller\AnnotationVersionHistoryController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters routes for annotation entities.
 *
 * Replaces the controller on entity.annotation.version_history with
 * AnnotationVersionHistoryController, which extends core's
 * VersionHistoryController to add diff comparison operation links when the
 * diff module is present.
 */
class AnnotationsUiRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.annotation.version_history');
    if ($route) {
      $route->setDefault('_controller', AnnotationVersionHistoryController::class . '::__invoke');
    }
  }

}
