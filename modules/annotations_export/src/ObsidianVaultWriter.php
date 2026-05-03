<?php

declare(strict_types=1);

namespace Drupal\annotations_export;

/**
 * Writes a ContextAssembler payload as an Obsidian markdown vault.
 *
 * One .md file per annotation target. Wikilinks between targets use the
 * target ID as the link text, which Obsidian resolves to the corresponding
 * file in the vault directory.
 */
final class ObsidianVaultWriter {

  /**
   * Writes the vault to disk and returns the number of files written.
   *
   * @param array $payload
   *   The payload returned by ContextAssembler::assemble().
   * @param string $outputDir
   *   Absolute path to the output directory. Created if it does not exist.
   *
   * @return int
   *   The number of target files written.
   *
   * @throws \RuntimeException
   *   When the output directory cannot be created or a file cannot be written.
   */
  public function write(array $payload, string $outputDir): int {
    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, TRUE)) {
      throw new \RuntimeException(sprintf('Cannot create output directory: %s', $outputDir));
    }

    $count = 0;
    foreach ($payload['groups'] as $group) {
      foreach ($group['targets'] as $targetData) {
        $this->writeTarget($targetData, $outputDir);
        $count++;
      }
    }

    return $count;
  }

  private function writeTarget(array $targetData, string $outputDir): void {
    $filename = $outputDir . '/' . $targetData['id'] . '.md';
    $content  = $this->buildFrontmatter($targetData) . "\n" . $this->buildBody($targetData);

    if (file_put_contents($filename, $content) === FALSE) {
      throw new \RuntimeException(sprintf('Cannot write file: %s', $filename));
    }
  }

  private function buildFrontmatter(array $targetData): string {
    $tags = [];
    if (!empty($targetData['annotations']) || !empty($targetData['fields'])) {
      $tags[] = 'annotated';
    }
    $fieldCount = count($targetData['fields']);
    if ($fieldCount > 0) {
      $tags[] = $fieldCount . '-fields';
    }

    $lines = [
      '---',
      'target: ' . $targetData['id'],
      'entity_type: ' . $targetData['entity_type'],
      'bundle: ' . $targetData['bundle'],
      'aliases: [' . $targetData['label'] . ']',
    ];

    if (!empty($tags)) {
      $lines[] = 'tags: [' . implode(', ', $tags) . ']';
    }

    $lines[] = '---';

    return implode("\n", $lines) . "\n";
  }

  private function buildBody(array $targetData): string {
    $parts = ['# ' . $targetData['label']];

    foreach ($targetData['annotations'] as $annotation) {
      $text = $this->annotationText($annotation);
      if ($text !== '') {
        $parts[] = $text;
      }
    }

    foreach ($targetData['fields'] as $fieldData) {
      $lines = ['## ' . $fieldData['label']];
      foreach ($fieldData['annotations'] as $annotation) {
        $text = $this->annotationText($annotation);
        if ($text !== '') {
          $lines[] = $text;
        }
      }
      $parts[] = implode("\n\n", $lines);
    }

    if (!empty($targetData['references'])) {
      $parts[] = $this->buildRelationships($targetData['references']);
    }

    return implode("\n\n", $parts) . "\n";
  }

  /**
   * Returns the text content for one annotation entry.
   */
  private function annotationText(array $annotation): string {
    $lines = [];
    if ($annotation['value'] !== '') {
      $lines[] = $annotation['value'];
    }
    foreach ($annotation['extra_fields'] ?? [] as $extra) {
      $lines[] = '**' . $extra['label'] . ':** ' . implode(', ', $extra['values']);
    }
    return implode("\n\n", $lines);
  }

  /**
   * Builds the Relationships section from entity-reference traversal data.
   *
   * Each bullet: `- [[dest_target_id]] via `field_name``
   * Wikilinks resolve to the corresponding .md file in the vault.
   */
  private function buildRelationships(array $references): string {
    $lines = ['## Relationships'];
    foreach ($references as $fieldName => $refTargets) {
      foreach ($refTargets as $refId => $refData) {
        $lines[] = sprintf('- [[%s]] via `%s`', $refId, $fieldName);
        // Edge annotations appear as indented sub-bullets under the wikilink.
        foreach ($refData['edge_annotations'] ?? [] as $annotation) {
          if ($annotation['value'] !== '') {
            $lines[] = '  - ' . $annotation['value'];
          }
          foreach ($annotation['extra_fields'] ?? [] as $extra) {
            $lines[] = '  - **' . $extra['label'] . ':** ' . implode(', ', $extra['values']);
          }
        }
      }
    }
    return implode("\n", $lines);
  }

}
