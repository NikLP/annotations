<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\argument;

use Drupal\views\Attribute\ViewsArgument;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\query\Sql;

/**
 * Argument plugin filtering annotations by target_id.
 */
#[ViewsArgument(
  id: 'annotation_target_id',
)]
class AnnotationTargetArgument extends ArgumentPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE): void {
    if (!($this->query instanceof Sql)) {
      return;
    }
    $this->ensureMyTable();
    $field = "$this->tableAlias.$this->realField";
    $this->query->addWhereExpression(
      0,
      "$field = :annotation_target_id",
      [':annotation_target_id' => $this->argument],
    );
  }

}
