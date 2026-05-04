<?php

declare(strict_types=1);

namespace Drupal\annotations_export\Drush\Commands;

use Drupal\annotations_context\ContextAssembler;
use Drupal\annotations_context\ContextRenderer;
use Drupal\annotations_export\ObsidianVaultWriter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the annotations_export module.
 */
final class AnnotationsExportCommands extends DrushCommands {

  public function __construct(
    private readonly ContextAssembler $assembler,
    private readonly ContextRenderer $renderer,
    private readonly ObsidianVaultWriter $obsidianWriter,
  ) {
    parent::__construct();
  }

  /**
   * Export assembled annotations context.
   *
   * Runs without an account filter: all annotation types are included
   * regardless of consume permissions. This is intentional — Drush runs as a
   * privileged caller. Use --types to limit output when needed.
   */
  #[CLI\Command(name: 'annotations:export', aliases: ['ann:ex'])]
  #[CLI\Option(name: 'format', description: 'Output format: markdown or obsidian')]
  #[CLI\Option(name: 'output', description: 'Output path. File path for markdown; directory for obsidian vault. Defaults to stdout for markdown.')]
  #[CLI\Option(name: 'target', description: 'Limit to a single annotation_target ID (e.g. node__article)')]
  #[CLI\Option(name: 'type', description: 'Limit to all targets of this entity type (e.g. node)')]
  #[CLI\Option(name: 'types', description: 'Comma-separated annotation type IDs to include (e.g. editorial,rules)')]
  #[CLI\Option(name: 'ref-depth', description: 'Entity-reference traversal depth (0–2, default 0)')]
  #[CLI\Option(name: 'field-meta', description: 'Include field type, cardinality, and description')]
  #[CLI\Option(name: 'strip-headings', description: 'Remove markdown heading markers (plain-text terminal output)')]
  #[CLI\Usage(name: 'drush ann:ex', description: 'Full site context as markdown to stdout')]
  #[CLI\Usage(name: 'drush ann:ex --output=context.md', description: 'Write markdown to file')]
  #[CLI\Usage(name: 'drush ann:ex --format=obsidian --output=/var/www/html/tmp/vault', description: 'Write Obsidian vault (use absolute container path to control where files land)')]
  #[CLI\Usage(name: 'drush ann:ex --target=node__article --ref-depth=1', description: 'Single target with ER traversal')]
  #[CLI\Usage(name: 'drush ann:ex --strip-headings', description: 'Plain-text output without heading markers')]
  public function export(
    array $options = [
      'format'         => 'markdown',
      'output'         => NULL,
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

    $format = strtolower($options['format']);

    match ($format) {
      'markdown' => $this->writeMarkdown($payload, $options['output'], (bool) $options['strip-headings']),
      'obsidian' => $this->writeObsidian($payload, $options['output']),
      default    => $this->io()->error(sprintf('Unknown format "%s". Supported: markdown, obsidian.', $format)),
    };
  }

  private function writeMarkdown(array $payload, ?string $output, bool $stripHeadings): void {
    $content = $this->renderer->render($payload);

    if ($stripHeadings) {
      $content = preg_replace('/^#{1,6}\s+/m', '', $content);
    }

    if ($output === NULL) {
      $this->io()->definitionList(
        ['Targets' => $payload['meta']['target_count']],
        ['Reference depth' => $payload['meta']['ref_depth']],
        ['Generated' => $payload['meta']['generated_at']],
      );
      $this->io()->writeln($content);
      return;
    }

    if (file_put_contents($output, $content) === FALSE) {
      $this->io()->error(sprintf('Could not write to %s.', $output));
      return;
    }

    $resolved = realpath($output) ?: $output;
    $this->io()->success(sprintf('Exported %d target(s) to %s', $payload['meta']['target_count'], $resolved));
  }

  private function writeObsidian(array $payload, ?string $output): void {
    if ($output === NULL) {
      $this->io()->error('--output is required for obsidian format.');
      return;
    }

    try {
      $count = $this->obsidianWriter->write($payload, $output);
    }
    catch (\RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $resolved = realpath($output) ?: $output;
    $this->io()->success(sprintf('Exported %d target(s) to %s', $count, $resolved));
  }

}
