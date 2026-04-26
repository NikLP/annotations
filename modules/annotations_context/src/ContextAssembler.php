<?php

declare(strict_types=1);

namespace Drupal\annotations_context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\annotations\AnnotationStorageService;
use Drupal\annotations\AnnotationDiscoveryService;
use Drupal\annotations\Entity\Annotation;
use Drupal\annotations\Entity\AnnotationTargetInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Assembles annotation_target annotation data into structured context payload.
 *
 * The payload is format-agnostic. Pass it to ContextRenderer for markdown,
 * or use it directly in AI consumers for prompt construction.
 *
 * Usage:
 * @code
 * $payload = $assembler->assemble(); // all targets
 * $payload = $assembler->assemble(['entity_type' => 'node']); // 1 type
 * $payload = $assembler->assemble(['target_id' => 'node__article']); // 1 targ.
 * $payload = $assembler->assemble(['types' => ['editorial']]); // 1 type only
 * $payload = $assembler->assemble(['ref_depth' => 1]); // follow ER links 1 hop
 * @endcode
 *
 * Payload structure:
 * @code
 * [
 *   'groups' => [
 *     'node' => [
 *       'entity_type' => 'node',
 *       'label'       => 'Content',
 *       'targets'     => [
 *         'node__article' => [
 *           'id'          => 'node__article',
 *           'label'       => 'Article',
 *           'entity_type' => 'node',
 *           'bundle'      => 'article',
 *           'annotations' => [ 'rules' => ['label' => ..., 'value' => ..., 'extra_fields' => [...]], ... ],
 *           'fields'      => [
 *             'title' => [
 *               'label'       => 'Title',
 *               'annotations' => [ 'editorial' => ['label' => ..., 'value' => ..., 'extra_fields' => [...]] ],
 *             ],
 *           ],
 *           'references'    => [...],  // present only when ref_depth > 0
 *           'incoming_refs' => [...],  // present only when include_incoming_refs = true
 *         ],
 *       ],
 *     ],
 *   ],
 *   'meta' => [ 'generated_at' => ..., 'ref_depth' => ..., 'include_incoming_refs' => ..., 'target_count' => ... ],
 * ]
 * @endcode
 */
class ContextAssembler {

  /**
   * Default entity-reference traversal depth (0 = no traversal).
   *
   * Override per-call via the 'ref_depth' option. Depth 1 follows entity
   * reference fields one hop; depth 2 follows references of references.
   * Values above 2 are rarely useful and can produce very large payloads.
   */
  const DEFAULT_REF_DEPTH = 0;

  /**
   * Per-request cache of field definitions keyed by "entity_type__bundle".
   *
   * @var array<string, array<string, \Drupal\Core\Field\FieldDefinitionInterface>>
   */
  private array $fieldDefinitionCache = [];

  /**
   * Whether to include field type, cardinality, and help text in the payload.
   *
   * Set by assemble() from the 'include_field_meta' option.
   */
  private bool $includeFieldMeta = FALSE;

  /**
   * Whether to include incoming entity-reference sources in the payload.
   *
   * Set by assemble() from the 'include_incoming_refs' option.
   */
  private bool $includeIncomingRefs = FALSE;

  /**
   * Reverse lookup idx built 1x per assemble() when include_incoming_refs=TRUE.
   *
   * Structure: [target_id =>
   * [source_target_id => ['label' => ..., 'via_fields' => [...]]]]
   *
   * @var array<string, array<string, array{label: string, via_fields: string[]}>>
   */
  private array $reverseIndex = [];

  /**
   * Cache metadata contrib by hook_annotations_context_alter() implementations.
   *
   * Populated on each assemble() call. Callers that produce cacheable output
   * (e.g. ContextPreviewController) must merge this into their build cache so
   * that pages invalidate when alter-contributed data changes.
   */
  private CacheableMetadata $lastCacheableMetadata;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnnotationStorageService $annotationStorage,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly AnnotationDiscoveryService $discoveryService,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    $this->lastCacheableMetadata = new CacheableMetadata();
  }

  /**
   * Returns cache metadata contributed by the last assemble() call's alters.
   *
   * Merge this into any cacheable output produced from the payload:
   * @code
   * $payload = $assembler->assemble($options);
   * $assembler->getLastCacheableMetadata()->applyTo($build);
   * @endcode
   */
  public function getLastCacheableMetadata(): CacheableMetadata {
    return $this->lastCacheableMetadata;
  }

  /**
   * Assembles the context payload.
   *
   * @param array $options
   *   Supported keys:
   *   - 'entity_type' (string|null): Limit to targets of this entity type.
   *   - 'target_id' (string|null): Limit to a single target by ID.
   *   - 'types' (string[]|null): Explicit list of annotation type IDs
   *     to include.
   *   - 'ref_depth' (int): Entity-reference traversal depth.
   *     Default: self::DEFAULT_REF_DEPTH.
   *   - 'role' (string|null): Simulate context as this user role ID. When
   *     set, only annotation types that role can consume are included.
   *     for are included. Combine freely with 'types', 'account', etc.
   *   - 'account' (\Drupal\Core\Session\AccountInterface|null): Filter to
   *     types the given account can view, using its actual combined permissions
   *     (i.e. the union of all its roles). Ignored if 'role' is also set.
   *     Accounts with 'administer annotations' bypass filtering.
   *     Use this for real current-user context; use 'role' for previews.
   *   - 'include_field_meta' (bool): If TRUE, each field entry in the payload
   *     gains a 'meta' key containing 'type', 'cardinality', and optionally
   *     'description' (the field's configured help text). Default FALSE.
   *   - 'include_incoming_refs' (bool): If TRUE, each target entry gains an
   *     'incoming_refs' key listing annotation targets that reference it via
   *     entity-reference fields in their annotation scope. Flat only — no
   *     recursive reverse traversal. Default FALSE.
   *
   * @return array
   *   The assembled payload. See class docblock for structure.
   */
  public function assemble(array $options = []): array {
    $this->fieldDefinitionCache = [];
    $this->includeFieldMeta     = (bool) ($options['include_field_meta'] ?? FALSE);
    $this->includeIncomingRefs  = (bool) ($options['include_incoming_refs'] ?? FALSE);

    $entity_type_filter = $options['entity_type'] ?? NULL;
    $target_id_filter   = $options['target_id'] ?? NULL;
    $explicit_types     = $options['types'] ?? NULL;
    $ref_depth          = (int) ($options['ref_depth'] ?? self::DEFAULT_REF_DEPTH);
    $role               = isset($options['role']) && $options['role'] !== '' ? (string) $options['role'] : NULL;
    $account            = ($options['account'] ?? NULL) instanceof AccountInterface ? $options['account'] : NULL;

    $types   = $this->loadAnnotationTypes($explicit_types, $role, $account);
    $targets = $this->loadTargets($entity_type_filter, $target_id_filter);

    if ($this->includeIncomingRefs) {
      $all_targets = $this->entityTypeManager->getStorage('annotation_target')->loadMultiple();
      $this->buildReverseIndex($all_targets);
    }
    else {
      $this->reverseIndex = [];
    }

    $groups = $this->assembleGroups($targets, $types, $ref_depth, $explicit_types !== NULL);

    $payload = [
      'groups' => $groups,
      'meta'   => [
        'generated_at'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'ref_depth'           => $ref_depth,
        'include_incoming_refs' => $this->includeIncomingRefs,
        'target_count'        => array_sum(array_map(
          fn($g) => count($g['targets']),
          $groups,
        )),
      ],
    ];

    // Allow other modules to append, remove, or reshape payload sections.
    // Implementations receive $payload by reference; $options for context; and
    // $cacheableMetadata by reference to contribute cache tags/contexts so that
    // pages derived from this payload invalidate correctly.
    //
    // Example implementation:
    // @code
    // function mymodule_annotations_context_alter(
    //   array &$payload, array $options,
    //   CacheableMetadata &$cacheableMetadata,
    // ): void {
    //   $payload['my_section'] = ['key' => 'value'];
    //   $cacheableMetadata->addCacheTags(['mymodule_data_list']);
    // }
    // @endcode
    $this->lastCacheableMetadata = new CacheableMetadata();
    $this->moduleHandler->alter('annotations_context', $payload, $options, $this->lastCacheableMetadata);

    return $payload;
  }

  /**
   * Loads annotation types, optionally filtered, sorted by weight.
   *
   * @param string[]|null $explicit_types
   *   If provided, restrict to these type IDs.
   * @param string|null $role
   *   If provided, restrict to types the given role can view (enforces the
   *   consume permission for that role. Takes precedence over
   *   $account when both are provided.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   If provided (and $role is not), restrict to types the account can view
   *   using its combined permissions. Accounts with 'administer annotations'
   *   bypass filtering entirely.
   *
   * @return array<string, \Drupal\annotations\Entity\AnnotationTypeInterface>
   *   Annotation types keyed by type ID, sorted by weight.
   */
  private function loadAnnotationTypes(?array $explicit_types, ?string $role, ?AccountInterface $account = NULL): array {
    /** @var \Drupal\annotations\Entity\AnnotationTypeInterface[] $all */
    $all = $this->entityTypeManager
      ->getStorage('annotation_type')
      ->loadMultiple();

    if ($explicit_types !== NULL) {
      $all = array_intersect_key($all, array_flip($explicit_types));
    }

    if ($role !== NULL) {
      /** @var \Drupal\user\RoleInterface|null $role_entity */
      $role_entity = $this->entityTypeManager->getStorage('user_role')->load($role);
      // Admin roles bypass all permission checks — filtering is meaningless.
      if ($role_entity !== NULL && !$role_entity->isAdmin()) {
        $all = array_filter($all, fn($t) => $role_entity->hasPermission($t->getConsumePermission()));
      }
    }
    elseif ($account !== NULL && !$account->hasPermission('administer annotations')) {
      $all = array_filter($all, fn($t) => $account->hasPermission($t->getConsumePermission()));
    }
    uasort($all, fn($a, $b) => $a->getWeight() <=> $b->getWeight());

    return $all;
  }

  /**
   * Loads annotation_target entities, filtered by entity type or target ID.
   *
   * @return \Drupal\annotations\Entity\AnnotationTargetInterface[]
   *   Loaded targets sorted by entity type then label.
   */
  private function loadTargets(?string $entity_type_filter, ?string $target_id_filter): array {
    $storage = $this->entityTypeManager->getStorage('annotation_target');

    if ($target_id_filter !== NULL) {
      $result = $storage->loadMultiple([$target_id_filter]);
    }
    elseif ($entity_type_filter !== NULL) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('entity_type', $entity_type_filter)
        ->execute();
      $result = $storage->loadMultiple($ids);
    }
    else {
      $result = $storage->loadMultiple();
    }

    // Sort by entity type then label for consistent output.
    uasort($result, fn($a, $b) =>
      $a->getTargetEntityTypeId() <=> $b->getTargetEntityTypeId()
      ?: strcmp((string) $a->label(), (string) $b->label())
    );

    return $result;
  }

  /**
   * Groups assembled targets by entity type.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $targets
   *   Loaded targets to group.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *   Types to include, already filtered and sorted by weight.
   * @param int $ref_depth
   *   Entity-reference traversal depth passed through to assembleTarget().
   * @param bool $skip_empty
   *   When TRUE, targets with no matching annotations are omitted. Use when
   *   the caller has filtered to specific types — showing empty targets in a
   *   type-filtered view is noise.
   *
   * @return array<string, array{entity_type: string, label: string, targets: array}>
   *   Groups keyed by entity type ID.
   */
  private function assembleGroups(array $targets, array $types, int $ref_depth, bool $skip_empty = FALSE): array {
    $plugins = $this->discoveryService->getPlugins();
    $groups  = [];
    $visited = [];

    foreach ($targets as $target) {
      $et_id     = $target->getTargetEntityTypeId();
      $assembled = $this->assembleTarget($target, $types, $ref_depth, 0, $visited);

      if ($skip_empty && empty($assembled['annotations']) && empty($assembled['fields'])) {
        continue;
      }

      if (!isset($groups[$et_id])) {
        $plugin = $plugins[$et_id] ?? NULL;
        $groups[$et_id] = [
          'entity_type' => $et_id,
          'label'       => $plugin ? (string) $plugin->getLabel() : $this->entityTypeLabel($et_id),
          'targets'     => [],
        ];
      }

      $groups[$et_id]['targets'][$target->id()] = $assembled;
    }

    return $groups;
  }

  /**
   * Assembles a single target into the payload structure.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface $target
   *   The annotation target to assemble.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *   Types to include, already filtered and sorted by weight.
   * @param int $max_depth
   *   Maximum entity-reference traversal depth.
   * @param int $current_depth
   *   Current traversal depth (0 at the top-level call).
   * @param string[] $visited
   *   Target IDs already assembled in this branch — prevents cycles.
   *
   * @return array
   *   The assembled target data.
   */
  private function assembleTarget(
    AnnotationTargetInterface $target,
    array $types,
    int $max_depth,
    int $current_depth,
    array &$visited,
  ): array {
    $visited[] = $target->id();

    $all_annotations = $this->annotationStorage->getEntityMapForTarget($target->id(), TRUE);
    $annotations = $this->extractAnnotations($all_annotations[''] ?? [], $types);

    $field_defs = $this->getFieldDefinitions(
      $target->getTargetEntityTypeId(),
      $target->getBundle(),
    );

    $fields = [];
    foreach (array_keys($target->getFields()) as $field_name) {
      $field_annotations = $this->extractAnnotations(
        $all_annotations[$field_name] ?? [],
        $types,
      );
      if (!empty($field_annotations)) {
        $def   = $field_defs[$field_name] ?? NULL;
        $entry = [
          'label'       => $def ? (string) $def->getLabel() : $field_name,
          'annotations' => $field_annotations,
        ];
        if ($this->includeFieldMeta && $def !== NULL) {
          $entry['meta'] = $this->buildFieldMeta($def);
        }
        $fields[$field_name] = $entry;
      }
    }

    $result = [
      'id'          => $target->id(),
      'label'       => (string) $target->label(),
      'entity_type' => $target->getTargetEntityTypeId(),
      'bundle'      => $target->getBundle(),
      'annotations' => $annotations,
      'fields'      => $fields,
    ];

    if ($max_depth > $current_depth) {
      $refs = $this->resolveReferences($target, $types, $max_depth, $current_depth, $visited);
      if (!empty($refs)) {
        $result['references'] = $refs;
      }
    }

    if ($this->includeIncomingRefs) {
      $incoming = $this->resolveIncomingRefs($target);
      if (!empty($incoming)) {
        $result['incoming_refs'] = $incoming;
      }
    }

    return $result;
  }

  /**
   * Assembles referenced targets by following entity-reference fields.
   *
   * Only follows fields that are in scope (listed in getFields()). Only
   * assembles referenced targets that have an annotation_target entry.
   * Skips targets already visited in this branch to prevent cycles.
   *
   * @return array<string, array>
   *   Keyed by field_name → target_id → assembled target.
   */
  private function resolveReferences(
    AnnotationTargetInterface $target,
    array $types,
    int $max_depth,
    int $current_depth,
    array &$visited,
  ): array {
    $et_id   = $target->getTargetEntityTypeId();
    $bundle  = $target->getBundle();
    $defs    = $this->getFieldDefinitions($et_id, $bundle);
    $storage = $this->entityTypeManager->getStorage('annotation_target');
    $refs    = [];

    foreach (array_keys($target->getFields()) as $field_name) {
      $def = $defs[$field_name] ?? NULL;
      if ($def === NULL) {
        continue;
      }

      $type = $def->getType();
      if (!in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        continue;
      }

      $target_type = $def->getFieldStorageDefinition()->getSetting('target_type');
      $bundles     = $def->getSetting('handler_settings')['target_bundles'] ?? [];

      foreach ((array) $bundles as $ref_bundle) {
        $ref_id = $target_type . '__' . $ref_bundle;

        // Skip self-references and already-visited targets.
        if ($ref_id === $target->id() || in_array($ref_id, $visited, TRUE)) {
          continue;
        }

        /** @var \Drupal\annotations\Entity\AnnotationTargetInterface|null $ref_target */
        $ref_target = $storage->load($ref_id);
        if ($ref_target === NULL) {
          continue;
        }

        $refs[$field_name][$ref_id] = $this->assembleTarget(
          $ref_target,
          $types,
          $max_depth,
          $current_depth + 1,
          $visited,
        );
      }
    }

    return $refs;
  }

  /**
   * Builds the reverse entity-reference index across all annotation targets.
   *
   * Only considers ER fields that are in each source target's annotation scope
   * (getFields()), matching the forward resolveReferences() behaviour.
   * Matches at bundle level — media__image and media__document are distinct.
   *
   * @param \Drupal\annotations\Entity\AnnotationTargetInterface[] $all_targets
   *   All loaded annotation target entities.
   */
  private function buildReverseIndex(array $all_targets): void {
    $this->reverseIndex = [];

    foreach ($all_targets as $source_target) {
      $source_id = $source_target->id();
      $defs = $this->getFieldDefinitions(
        $source_target->getTargetEntityTypeId(),
        $source_target->getBundle(),
      );

      foreach (array_keys($source_target->getFields()) as $field_name) {
        $def = $defs[$field_name] ?? NULL;
        if ($def === NULL) {
          continue;
        }

        $type = $def->getType();
        if (!in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
          continue;
        }

        $target_type = $def->getFieldStorageDefinition()->getSetting('target_type');
        $target_bundles = $def->getSetting('handler_settings')['target_bundles'] ?? [];

        foreach ((array) $target_bundles as $ref_bundle) {
          $ref_id = $target_type . '__' . $ref_bundle;
          if ($ref_id === $source_id) {
            continue;
          }
          $this->reverseIndex[$ref_id][$source_id]['label'] ??= (string) $source_target->label();
          $this->reverseIndex[$ref_id][$source_id]['via_fields'][] = $field_name;
        }
      }
    }
  }

  /**
   * Returns incoming reference entries for a target from the reverse index.
   *
   * @return array<string, array{label: string, via_fields: string[]}>
   *   Entries from the reverse index keyed by source target ID.
   */
  private function resolveIncomingRefs(AnnotationTargetInterface $target): array {
    return $this->reverseIndex[$target->id()] ?? [];
  }

  /**
   * Builds the field metadata block for a single field definition.
   *
   * @return array{type: string, cardinality: string, description?: string}
   *   Field metadata suitable for inclusion in the payload.
   */
  private function buildFieldMeta(FieldDefinitionInterface $def): array {
    $cardinality = $def->getFieldStorageDefinition()->getCardinality();
    $cardinality_label = match (TRUE) {
      $cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED => 'unlimited',
      $cardinality === 1 => 'single value',
      default => 'max ' . $cardinality . ' values',
    };

    $meta = [
      'type'        => $def->getType(),
      'cardinality' => $cardinality_label,
    ];

    $description = $this->flattenHtml(trim((string) ($def->getDescription() ?? '')));
    if ($description !== '') {
      $meta['description'] = $description;
    }

    return $meta;
  }

  /**
   * Extracts non-empty annotation values from an entity map.
   *
   * @param array<string, \Drupal\annotations\Entity\Annotation> $raw
   *   Annotation entities keyed by type ID.
   * @param \Drupal\annotations\Entity\AnnotationTypeInterface[] $types
   *   The types to include, already filtered and sorted.
   *
   * @return array<string, array{label: string, value: string, extra_fields?: array}>
   *   Non-empty annotations keyed by type ID, in weight order.
   */
  private function extractAnnotations(array $raw, array $types): array {
    $result = [];
    foreach ($types as $type_id => $type) {
      /** @var \Drupal\annotations\Entity\Annotation|null $entity */
      $entity = $raw[$type_id] ?? NULL;
      $value  = $entity !== NULL ? $this->flattenHtml(trim((string) $entity->get('value')->value)) : '';
      $extra  = $entity !== NULL ? $this->extractExtraFields($entity, $type_id) : [];

      if ($value === '' && empty($extra)) {
        continue;
      }

      $entry = [
        'label' => (string) $type->label(),
        'value' => $value,
      ];
      if (!empty($extra)) {
        $entry['extra_fields'] = $extra;
      }
      $result[$type_id] = $entry;
    }

    return $result;
  }

  /**
   * Extracts configurable field values from an Annotation entity.
   *
   * Base fields (value, target_id, status, uid, etc.) are intentionally
   * excluded — only fields added via the Field UI are relevant here.
   *
   * @return array<string, array{label: string, values: string[]}>
   *   Non-empty configurable fields keyed by field name.
   */
  private function extractExtraFields(Annotation $entity, string $type_id): array {
    $defs = array_filter(
      $this->getFieldDefinitions('annotation', $type_id),
      fn($def) => $def instanceof FieldConfigInterface,
    );

    $result = [];
    foreach ($defs as $field_name => $def) {
      $item_list = $entity->get($field_name);
      if ($item_list->isEmpty()) {
        continue;
      }

      $values = [];
      foreach ($item_list as $item) {
        $str = $this->flattenHtml($this->fieldItemToString($item, $def));
        if ($str !== '') {
          $values[] = $str;
        }
      }

      if (!empty($values)) {
        $result[$field_name] = [
          'label'  => (string) $def->getLabel(),
          'values' => $values,
        ];
      }
    }

    return $result;
  }

  /**
   * Converts a single field item to a plain string for context output.
   *
   * Handles the most common field types with human-readable output.
   * Falls back to ->value cast for unknown types.
   */
  private function fieldItemToString(FieldItemInterface $item, FieldDefinitionInterface $def): string {
    $type = $def->getType();

    if (in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $entity = $item->entity;
      return $entity !== NULL ? (string) $entity->label() : (string) ($item->target_id ?? '');
    }

    if ($type === 'boolean') {
      return $item->value ? 'Yes' : 'No';
    }

    if (in_array($type, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      $raw     = (string) ($item->value ?? '');
      $allowed = $def->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
      return $allowed[$raw] ?? $raw;
    }

    return trim((string) ($item->value ?? ''));
  }

  /**
   * Strips HTML tags from an annotation value and decodes any HTML entities.
   *
   * Annotation values are plain text but may contain markup if content was
   * pasted from a rich-text source. Strip tags so all consumers (preview,
   * markdown, MCP, JSON) receive clean text. No-op when no '<' is present.
   */
  private function flattenHtml(string $value): string {
    if ($value === '' || !str_contains($value, '<')) {
      return $value;
    }
    $value = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $value);
    $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return preg_replace('/\s+/', ' ', trim($decoded));
  }

  /**
   * Returns the human-readable label for a Drupal entity type.
   */
  private function entityTypeLabel(string $entity_type_id): string {
    $def = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);

    return $def ? (string) $def->getLabel() : $entity_type_id;
  }

  /**
   * Returns cached field definitions for an entity type and bundle.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field definitions keyed by field name.
   */
  private function getFieldDefinitions(string $entity_type_id, string $bundle): array {
    $cache_key = $entity_type_id . '__' . $bundle;
    if (!isset($this->fieldDefinitionCache[$cache_key])) {
      if (!$this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->entityClassImplements(FieldableEntityInterface::class)) {
        $this->fieldDefinitionCache[$cache_key] = [];
      }
      else {
        try {
          $this->fieldDefinitionCache[$cache_key] = $this->fieldManager
            ->getFieldDefinitions($entity_type_id, $bundle);
        }
        catch (\Throwable) {
          $this->fieldDefinitionCache[$cache_key] = [];
        }
      }
    }

    return $this->fieldDefinitionCache[$cache_key];
  }

}
