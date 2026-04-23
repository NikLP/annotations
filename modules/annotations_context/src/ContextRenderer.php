<?php

declare(strict_types=1);

namespace Drupal\annotations_context;

/**
 * Renders a ContextAssembler payload as a markdown document.
 *
 * The renderer is deliberately stateless and format-focused — it knows nothing
 * about Drupal services or entities. Pass it the array returned by
 * ContextAssembler::assemble() and it returns a markdown string.
 *
 * Output structure:
 * - One section per entity type group
 *   - One subsection per target
 *     - Bundle-level annotation text (paragraphs, in weight order)
 *     - Fields subsection (bold field name + annotation text)
 *     - Referenced targets (if ref_depth > 0, indented under the source field)
 */
class ContextRenderer {

  /**
   * Renders the full context payload as a markdown string.
   *
   * @param array $payload
   *   The payload returned by ContextAssembler::assemble().
   *
   * @return string
   *   A UTF-8 markdown document.
   */
  public function render(array $payload): string {
    $sections = [];

    foreach ($payload['groups'] as $group) {
      if (!empty($group['targets'])) {
        $rendered = $this->renderGroup($group);
        if ($rendered !== '') {
          $sections[] = $rendered;
        }
      }
    }

    return implode("\n\n---\n\n", $sections);
  }

  /**
   * Renders a single entity-type group.
   *
   * @param array{entity_type: string, label: string, targets: array} $group
   */
  private function renderGroup(array $group): string {
    $parts = ['# ' . $group['label']];
    foreach ($group['targets'] as $target_data) {
      $rendered = $this->renderTarget($target_data, 2);
      if ($rendered !== '') {
        $parts[] = $rendered;
      }
    }
    if (count($parts) === 1) {
      return '';
    }
    return implode("\n\n", $parts);
  }

  /**
   * Renders a single target at the given heading depth.
   *
   * @param array $target_data
   *   An assembled target from the payload.
   * @param int $heading_level
   *   The markdown heading level for the target name (2 = ##, 3 = ###, etc.).
   */
  private function renderTarget(array $target_data, int $heading_level): string {
    $h = str_repeat('#', $heading_level);
    $parts = [];

    // Bundle-level annotations, one paragraph per type (in weight order).
    foreach ($target_data['annotations'] as $annotation) {
      array_push($parts, ...$this->annotationLines($annotation));
    }

    // Field annotations.
    if (!empty($target_data['fields'])) {
      $fields_heading = str_repeat('#', min($heading_level + 1, 6));
      $field_lines = [$fields_heading . ' ' . 'Fields'];
      foreach ($target_data['fields'] as $field_name => $field_data) {
        $field_lines[] = $this->renderField($field_data, $heading_level + 2);
      }
      $parts[] = implode("\n\n", $field_lines);
    }

    // Referenced targets (entity reference traversal).
    if (!empty($target_data['references'])) {
      $parts[] = $this->renderReferences($target_data['references'], $heading_level);
    }

    if (empty($parts)) {
      return '';
    }

    array_unshift($parts, $h . ' ' . $target_data['label']);
    return implode("\n\n", $parts);
  }

  /**
   * Renders a single field's annotations as a markdown block.
   *
   * @param array{label: string, annotations: array} $field_data
   * @param int $heading_level
   *   The markdown heading level for the field name.
   */
  private function renderField(array $field_data, int $heading_level): string {
    $h = str_repeat('#', min($heading_level, 6));
    $lines = [$h . ' ' . $field_data['label']];

    if (!empty($field_data['meta'])) {
      $meta    = $field_data['meta'];
      $summary = '_' . $meta['type'] . ' · ' . $meta['cardinality'] . '_';
      if (!empty($meta['description'])) {
        $summary .= ' — ' . $meta['description'];
      }
      $lines[] = $summary;
    }

    foreach ($field_data['annotations'] as $annotation) {
      array_push($lines, ...$this->annotationLines($annotation));
    }
    return implode("\n\n", $lines);
  }

  /**
   * Returns markdown lines for one annotation entry (value + extra fields).
   *
   * Extra configurable fields are rendered as "**Label:** val1, val2" lines.
   * An empty value is omitted so that extra-field-only annotations don't leave
   * a blank paragraph.
   *
   * @param array{label: string, value: string, extra_fields?: array} $annotation
   *
   * @return string[]
   */
  private function annotationLines(array $annotation): array {
    $lines = [];
    if ($annotation['value'] !== '') {
      $lines[] = $annotation['value'];
    }
    foreach ($annotation['extra_fields'] ?? [] as $extra) {
      $lines[] = '**' . $extra['label'] . ':** ' . implode(', ', $extra['values']);
    }
    return $lines;
  }

  /**
   * Renders referenced targets with a heading one level deeper.
   *
   * References are grouped by the source field name so readers can see which
   * field links to which target.
   *
   * @param array<string, array<string, array>> $references
   *   Keyed by field_name → target_id → assembled target data.
   * @param int $parent_heading_level
   *   The heading level of the parent target.
   */
  private function renderReferences(array $references, int $parent_heading_level): string {
    // Cap depth so headings stay valid markdown (H6 max).
    $child_level = min($parent_heading_level + 1, 6);
    $refs_heading = str_repeat('#', min($parent_heading_level + 1, 6));
    $parts = [$refs_heading . ' References'];

    foreach ($references as $field_name => $ref_targets) {
      foreach ($ref_targets as $ref_data) {
        // Include the source field context in the heading.
        $ref_section = $this->renderTarget($ref_data, $child_level);
        // Prepend a note about where the reference comes from.
        $parts[] = '_via ' . $field_name . ':_' . "\n\n" . $ref_section;
      }
    }

    return implode("\n\n", $parts);
  }

}
