<?php

declare(strict_types=1);

namespace Drupal\annotations_context\Drush\Commands;

use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the annotations_context module.
 */
final class AnnotationsContextCommands extends DrushCommands {

  public function __construct(
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
  ) {
    parent::__construct();
  }

  /**
   * Export assembled annotations context as markdown.
   */
  #[CLI\Command(name: 'annotations:context:export', aliases: ['ann:ctx'])]
  #[CLI\Option(name: 'target', description: 'Limit to a single annotation_target ID (e.g. node__article)')]
  #[CLI\Option(name: 'type', description: 'Limit to all targets of this entity type (e.g. node)')]
  #[CLI\Option(name: 'types', description: 'Comma-separated annotation type IDs to include (e.g. editorial,rules)')]
  #[CLI\Option(name: 'ref-depth', description: 'Entity-reference traversal depth (0–2, default 0)')]
  #[CLI\Option(name: 'field-meta', description: 'Include field type, cardinality, and description')]
  #[CLI\Option(name: 'strip-headings', description: 'Remove markdown heading markers for plain-text terminal output')]
  #[CLI\Usage(name: 'drush annotations:context:export', description: 'Full site context as markdown')]
  #[CLI\Usage(name: 'drush ann:ctx --target=node__article', description: 'One target')]
  #[CLI\Usage(name: 'drush ann:ctx --type=node --ref-depth=1', description: 'All node targets, follow ER fields one hop')]
  #[CLI\Usage(name: 'drush ann:ctx --strip-headings', description: 'Plain-text output without heading markers')]
  #[CLI\Usage(name: 'drush ann:ctx > context.md', description: 'Export to file')]
  public function export(
    array $options = [
      'target'         => NULL,
      'type'           => NULL,
      'types'          => NULL,
      'ref-depth'      => 0,
      'field-meta'     => FALSE,
      'strip-headings' => FALSE,
    ],
  ): void {
    $assemblerOptions = [];

    if (!empty($options['target'])) {
      $assemblerOptions['target_id'] = $options['target'];
    }
    if (!empty($options['type'])) {
      $assemblerOptions['entity_type'] = $options['type'];
    }
    if (!empty($options['types'])) {
      $assemblerOptions['types'] = array_map('trim', explode(',', $options['types']));
    }
    if ($options['ref-depth'] > 0) {
      $assemblerOptions['ref_depth'] = (int) $options['ref-depth'];
    }
    if ($options['field-meta']) {
      $assemblerOptions['include_field_meta'] = TRUE;
    }

    $payload = $this->assembler->assemble($assemblerOptions);

    if ($payload['meta']['target_count'] === 0) {
      $this->io()->warning('No annotations found matching the given filters.');
      return;
    }

    $this->io()->definitionList(
      ['Targets' => $payload['meta']['target_count']],
      ['Reference depth' => $payload['meta']['ref_depth']],
      ['Generated' => $payload['meta']['generated_at']],
    );

    $output = $this->renderer->render($payload);

    if ($options['strip-headings']) {
      $output = preg_replace('/^#{1,6}\s+/m', '', $output);
    }

    $this->io()->writeln($output);
    $this->io()->success(sprintf('Exported %d annotation target(s).', $payload['meta']['target_count']));
  }

}
