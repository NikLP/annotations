<?php

declare(strict_types=1);

namespace Drupal\annotations\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a human-readable status for an annotation row.
 *
 * Shows the content moderation state label when annotations_workflow
 * (content_moderation) is active. Falls back to Published / Unpublished when
 * it is not. Use this field instead of both the moderation state relationship
 * field and the raw published status field.
 */
#[ViewsField('annotation_moderation_state')]
class AnnotationModerationStateField extends FieldPluginBase {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  public function usesGroupBy(): bool {
    return FALSE;
  }

  public function clickSortable(): bool {
    // Sorting is not supported: this field loads from a separate entity type
    // in render() and adds nothing to the SQL query.
    return FALSE;
  }

  public function query(): void {
    // No extra query needed; we load via entity type manager in render().
  }

  public function render(ResultRow $values): mixed {
    $annotation = $values->_entity ?? NULL;
    if ($annotation === NULL) {
      return '';
    }

    $state_id = $annotation->hasField('moderation_state')
      ? ($annotation->get('moderation_state')->value ?? '')
      : '';
    if ($state_id !== '') {
      foreach ($this->entityTypeManager->getStorage('workflow')->loadMultiple() as $workflow) {
        $type_plugin = $workflow->getTypePlugin();
        if ($type_plugin->hasState($state_id)) {
          return $type_plugin->getState($state_id)->label();
        }
      }
      return $state_id;
    }

    // Fallback: annotations_workflow not installed.
    return $annotation->isPublished()
      ? new TranslatableMarkup('Published')
      : new TranslatableMarkup('Unpublished');
  }

}
