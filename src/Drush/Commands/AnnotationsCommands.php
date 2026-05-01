<?php

declare(strict_types=1);

namespace Drupal\annotations\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for inspecting annotation targets, types, and stored content.
 */
final class AnnotationsCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * List all annotation_target config entities.
   */
  #[CLI\Command(name: 'annotations:targets', aliases: ['ann:targets'])]
  #[CLI\Argument(name: 'entity_type', description: 'Optional entity type filter (e.g. node, taxonomy_term)')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Usage(name: 'drush ann:targets', description: 'List all configured annotation targets')]
  #[CLI\Usage(name: 'drush ann:targets node', description: 'List targets for entity type "node"')]
  #[CLI\Usage(name: 'drush ann:targets --format=json', description: 'Output targets as JSON')]
  public function targets(string $entity_type = '', array $options = ['format' => 'table']): int {
    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();

    if ($entity_type !== '') {
      $targets = array_filter($targets, fn($t) => $t->getTargetEntityTypeId() === $entity_type);
    }

    if (empty($targets)) {
      $this->io()->warning($entity_type !== ''
        ? "No annotation targets found for entity type '$entity_type'."
        : 'No annotation targets configured.'
      );
      return self::EXIT_SUCCESS;
    }

    uasort($targets, fn($a, $b) => strcmp(
      $a->getTargetEntityTypeId() . '__' . $a->getBundle(),
      $b->getTargetEntityTypeId() . '__' . $b->getBundle(),
    ));

    $ann_storage = $this->entityTypeManager->getStorage('annotation');
    $data = [];

    foreach ($targets as $target) {
      $ann_count = (int) $ann_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('target_id', $target->id())
        ->count()
        ->execute();

      $data[] = [
        'id'          => $target->id(),
        'entity_type' => $target->getTargetEntityTypeId(),
        'bundle'      => $target->getBundle(),
        'label'       => $target->label(),
        'fields'      => count($target->getFields()),
        'annotations' => $ann_count,
      ];
    }

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($data, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($data, 4));
      return self::EXIT_SUCCESS;
    }

    $rows = array_map(
      fn($d) => [$d['id'], $d['entity_type'], $d['bundle'], $d['label'], $d['fields'], $d['annotations']],
      $data,
    );
    $this->io()->table(['Target', 'Entity type', 'Bundle', 'Label', 'Fields', 'Annotations'], $rows);

    return self::EXIT_SUCCESS;
  }

  /**
   * List all annotation_type config entities.
   */
  #[CLI\Command(name: 'annotations:types', aliases: ['ann:types'])]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Usage(name: 'drush ann:types', description: 'List all configured annotation types')]
  #[CLI\Usage(name: 'drush ann:types --format=json', description: 'Output types as JSON')]
  public function types(array $options = ['format' => 'table']): int {
    $types = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();

    if (empty($types)) {
      $this->io()->warning('No annotation types configured.');
      return self::EXIT_SUCCESS;
    }

    uasort($types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());

    $ann_storage = $this->entityTypeManager->getStorage('annotation');
    $data = [];

    foreach ($types as $type) {
      $ann_count = (int) $ann_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type_id', $type->id())
        ->count()
        ->execute();

      $data[] = [
        'id'          => $type->id(),
        'label'       => $type->label(),
        'weight'      => $type->getWeight(),
        'description' => $type->getDescription(),
        'annotations' => $ann_count,
      ];
    }

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($data, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($data, 4));
      return self::EXIT_SUCCESS;
    }

    $rows = array_map(fn($d) => [
      $d['id'],
      $d['label'],
      $d['weight'],
      mb_strimwidth($d['description'], 0, 60, '...'),
      $d['annotations'],
    ], $data);
    $this->io()->table(['ID', 'Label', 'Weight', 'Description', 'Annotations'], $rows);

    return self::EXIT_SUCCESS;
  }

  /**
   * Show stored annotation content, with optional filters.
   *
   * Pass --field= (empty) to show bundle-level annotations only.
   */
  #[CLI\Command(name: 'annotations:show', aliases: ['ann:show'])]
  #[CLI\Argument(name: 'target_id', description: 'Annotation target ID to inspect (e.g. node__article). Omit for all.')]
  #[CLI\Option(name: 'type', description: 'Filter by annotation type ID (e.g. editorial)')]
  #[CLI\Option(name: 'field', description: 'Filter by field name. Pass empty string (--field=) for bundle-level only.')]
  #[CLI\Option(name: 'entity-type', description: 'Filter by entity type when no target_id given (e.g. node)')]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to show (default 50)')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Usage(name: 'drush ann:show', description: 'Show all stored annotations (up to limit)')]
  #[CLI\Usage(name: 'drush ann:show node__article', description: 'Show all annotations for a single target')]
  #[CLI\Usage(name: 'drush ann:show node__article --type=editorial', description: 'Filter by annotation type')]
  #[CLI\Usage(name: 'drush ann:show --entity-type=node', description: 'Show all node-related annotations')]
  #[CLI\Usage(name: 'drush ann:show node__article --field=', description: 'Show only bundle-level annotations')]
  public function show(
    string $target_id = '',
    array $options = [
      'type'        => NULL,
      'field'       => NULL,
      'entity-type' => NULL,
      'limit'       => 50,
      'format'      => 'table',
    ],
  ): int {
    $storage = $this->entityTypeManager->getStorage('annotation');
    $query = $storage->getQuery()->accessCheck(FALSE);

    if ($target_id !== '') {
      $query->condition('target_id', $target_id);
    }
    elseif (!empty($options['entity-type'])) {
      $target_ids = $this->targetIdsForEntityType($options['entity-type']);
      if (empty($target_ids)) {
        $this->io()->warning("No annotation targets found for entity type '{$options['entity-type']}'.");
        return self::EXIT_SUCCESS;
      }
      $query->condition('target_id', $target_ids, 'IN');
    }

    if ($options['type'] !== NULL) {
      $query->condition('type_id', $options['type']);
    }

    if ($options['field'] !== NULL) {
      $options['field'] === ''
        ? $query->condition('field_name', NULL, 'IS NULL')
        : $query->condition('field_name', $options['field']);
    }

    $limit = max(1, (int) ($options['limit'] ?? 50));
    $query->range(0, $limit)->sort('target_id')->sort('type_id');
    $ids = $query->execute();

    if (empty($ids)) {
      $this->io()->warning('No annotations found matching the given filters.');
      return self::EXIT_SUCCESS;
    }

    $data = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $field_name = (string) ($entity->get('field_name')->value ?? '');
      $data[] = [
        'target_id' => (string) ($entity->get('target_id')->value ?? ''),
        'field'     => $field_name === '' ? '(bundle)' : $field_name,
        'type'      => (string) $entity->get('type_id')->value,
        'value'     => (string) $entity->get('value')->value,
      ];
    }

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($data, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($data, 4));
      return self::EXIT_SUCCESS;
    }

    $rows = array_map(fn($d) => [
      $d['target_id'],
      $d['field'],
      $d['type'],
      mb_strimwidth($d['value'], 0, 80, '...'),
    ], $data);
    $this->io()->table(['Target', 'Field', 'Type', 'Value'], $rows);

    if (count($ids) === $limit) {
      $this->io()->note(sprintf('Showing first %d result(s). Use --limit to adjust.', $limit));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Show annotation coverage statistics per target, broken down by type.
   */
  #[CLI\Command(name: 'annotations:stats', aliases: ['ann:stats'])]
  #[CLI\Option(name: 'entity-type', description: 'Limit to targets of this entity type (e.g. node)')]
  #[CLI\Option(name: 'format', description: 'Output format: table (default), json, yaml')]
  #[CLI\Usage(name: 'drush ann:stats', description: 'Show annotation counts per target and type')]
  #[CLI\Usage(name: 'drush ann:stats --entity-type=node', description: 'Scope stats to node targets only')]
  public function stats(array $options = ['entity-type' => NULL, 'format' => 'table']): int {
    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    $types   = $this->entityTypeManager->getStorage('annotation_type')->loadMultiple();

    if (!empty($options['entity-type'])) {
      $targets = array_filter($targets, fn($t) => $t->getTargetEntityTypeId() === $options['entity-type']);
    }

    if (empty($targets)) {
      $this->io()->warning('No annotation targets found.');
      return self::EXIT_SUCCESS;
    }

    uasort($targets, fn($a, $b) => strcmp(
      $a->getTargetEntityTypeId() . '__' . $a->getBundle(),
      $b->getTargetEntityTypeId() . '__' . $b->getBundle(),
    ));
    uasort($types, fn($a, $b) => $a->getWeight() <=> $b->getWeight());

    $ann_storage = $this->entityTypeManager->getStorage('annotation');
    $type_ids    = array_keys($types);
    $data        = [];

    foreach ($targets as $target) {
      $row = ['target' => $target->id(), 'label' => $target->label(), 'total' => 0];
      foreach ($type_ids as $type_id) {
        $count = (int) $ann_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('target_id', $target->id())
          ->condition('type_id', $type_id)
          ->count()
          ->execute();
        $row[$type_id] = $count;
        $row['total'] += $count;
      }
      $data[] = $row;
    }

    if ($options['format'] === 'json') {
      $this->io()->writeln(\json_encode($data, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($options['format'] === 'yaml') {
      $this->io()->writeln(Yaml::dump($data, 4));
      return self::EXIT_SUCCESS;
    }

    $headers = ['Target', 'Label'];
    foreach ($types as $type) {
      $headers[] = $type->label();
    }
    $headers[] = 'Total';

    $rows = [];
    foreach ($data as $row) {
      $cells = [$row['target'], $row['label']];
      foreach ($type_ids as $type_id) {
        $cells[] = $row[$type_id] > 0 ? (string) $row[$type_id] : '-';
      }
      $cells[] = $row['total'];
      $rows[] = $cells;
    }

    $this->io()->table($headers, $rows);

    $total_annotations = array_sum(array_column($data, 'total'));
    $empty_count       = count(array_filter($data, fn($d) => $d['total'] === 0));
    $this->io()->success(sprintf(
      '%d target(s), %d annotation(s) total%s',
      count($data),
      $total_annotations,
      $empty_count > 0 ? sprintf(', %d with no annotations', $empty_count) : '',
    ));

    return self::EXIT_SUCCESS;
  }

  /**
   * @return string[]
   */
  private function targetIdsForEntityType(string $entity_type): array {
    $targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
    return array_keys(array_filter($targets, fn($t) => $t->getTargetEntityTypeId() === $entity_type));
  }

}
