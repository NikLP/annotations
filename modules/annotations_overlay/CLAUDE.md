# CLAUDE.md — annotations_overlay

Submodule of DOT. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Surfaces annotation content to users while they work. When a user edits an entity, field-level "?" triggers appear beside each annotated field; clicking opens the annotation text in a modal dialog. A toolbar button exposes site-wide documentation on any page.

This module has no dependency on `annotations_ui` or `annotations_context`. It reads annotation content directly via `AnnotationStorageService` and renders it without going through the full context assembler pipeline.

## What it owns

- `AnnotationsOverlayHooks` (`src/Hook/`) — all hook implementations:
  - `hook_theme` — registers `annotations_overlay_wrapper` and `annotations_overlay_item` theme hooks
  - `hook_form_alter` — injects field triggers, bundle trigger, and `<dialog>` elements into entity edit/add forms for opted-in targets, including inline paragraph subforms (see Paragraph support below)
- Templates: `annotations-overlay-wrapper.html.twig`, `annotations-overlay-item.html.twig`
- Library: `annotations_overlay/overlay` — `js/annotations-overlay.js` and `css/annotations-overlay.css`
- Permission: `view annotations overlay`

## JS architecture

`annotations-overlay.js` runs as a plain IIFE (no jQuery, no Drupal.behaviors). All annotation content is server-rendered inside `<dialog>` elements in the DOM at page load — no AJAX round-trips, no client-side content manipulation. Clicking a trigger calls `showModal()` on the matching `<dialog data-annotations-field="...">`. All event handling is delegated to `document` so dynamically injected dialogs (e.g. paragraph AJAX) work without re-initialisation.

## Annotation visibility

The form alter only injects overlays when:

1. The current user has `view annotations overlay`
2. The form is an entity form (`EntityFormInterface`)
3. A `annotation_target` exists for the entity type + bundle
4. At least one annotation is non-empty for a type the user has `consume {type} annotations` permission for

If none of those conditions are met the form is unchanged.

**Type heading suppression (`single_type`):** Each rendered item in a dialog carries a `single_type` boolean. When only one annotation type is expressed across *all* dialogs the user will see on the page — computed by `isSingleType()` across the full set of visible, filtered annotation maps — `single_type` is `TRUE` and the per-item type heading is suppressed. A user whose `consume` permissions cover only one type will never see a redundant "Editorial" label on every panel; a developer with all types sees headings wherever two or more types appear.

## Permission model

| Permission | What it gates |
| --- | --- |
| `view annotations overlay` | Seeing the "?" triggers and overlay panels. Grant to editor roles. The per-type `consume {type} annotations` permissions still filter which annotation types appear inside the panels. |

## Paragraph support

Overlays work in two paragraph contexts, both handled inside `hook_form_alter`.

### Inline paragraphs (Paragraphs widget)

When a parent entity form (e.g. a node) contains a `entity_reference_revisions` field using the Paragraphs widget, the paragraph fields are rendered as a subform inline within the parent form — not as a separate form. `hook_form_alter` fires once for the full parent form, and the paragraph subform is present in the render array at `$form[$field_name]['widget'][$delta]['subform']` with the bundle available at `$form[$field_name]['widget'][$delta]['#paragraph_type']`.

`injectParagraphSubformOverlays()` iterates over all fields on the parent form looking for this structure. For each delta with a matching `annotation_target`, it:

1. Injects `?` triggers into individual subform fields the same way as parent-form fields.
2. Injects a bundle trigger at the top of the subform.
3. Places the dialogs container **inside `$form[$field_name]['widget']`**, not in the global `annotations_overlay_dialogs` container.

The dialogs must be inside the Paragraphs field wrapper because clicking "Add [paragraph type]" fires an AJAX request that replaces only that wrapper in the DOM. Drupal rebuilds the whole form server-side on that AJAX request, so `hook_form_alter` runs again and the new subform is present — the resulting AJAX response includes the triggers and dialogs. If dialogs were in the global container instead, they would be absent from the DOM after the replacement.

**Field key prefixing:** Paragraph field keys are prefixed `para__{bundle}__` (e.g. `para__localgov_banner_primary__localgov_image`) to avoid collisions with parent-form fields that share the same machine name.

**No hard Paragraphs dependency:** The check is purely structural array inspection. If the `subform` / `#paragraph_type` keys are absent the loop is a no-op, so `annotations_overlay` does not need to declare a dependency on the Paragraphs module.

**Setup required:** The paragraph bundle must have an `annotation_target` config entity (e.g. `annotations.target.paragraph__localgov_banner_primary.yml`) with at least one field in scope and a non-empty annotation for a type the user can view. Without that the subform is unchanged.

### Layout Paragraphs (Mercury Editor / layout_paragraphs widget)

When a field uses the `layout_paragraphs` widget, each paragraph component opens in its own modal dialog powered by `ComponentFormBase`. This form object does not implement `EntityFormInterface` but exposes the paragraph via `getParagraph()`. The existing guard at the top of `formAlter()` handles this:

```php
elseif (method_exists($form_object, 'getParagraph')) {
  $entity = $form_object->getParagraph();
}
```

The component edit dialog is treated as a standalone entity form. Triggers and panels are injected into it exactly as they would be for any other entity form — no special paragraph-specific logic is needed. The `annotation_target` for the paragraph bundle is loaded, annotations are fetched, and panels go into the standard `annotations_overlay_panels` container on the dialog form (which is the full form in this context, not a subform inside a parent).

**Key difference from inline paragraphs:** In the layout_paragraphs case the AJAX concern does not apply — the dialog form is its own complete Drupal form and is fully rebuilt each time it opens. Panels do not need to live inside any wrapper.

## View page overlay — implementation plan

View-page overlays fire on entity view pages (e.g. `/node/1`, `/taxonomy/term/4`). Editors land here after saving, or browse to review content. The goal is the same `?` trigger UX as forms — no separate UI to learn.

### Prerequisites before writing any code

**1. `in_view_context` flag on `DotAnnotationType`**

Add boolean `in_view_context` to the config entity. Allows `editorial` to show on view pages while `technical` and `rules` stay admin-only. Steps:

- `config/schema/dot.schema.yml` — add `in_view_context: boolean` to the `dot.annotation_type.*` mapping
- `AnnotationTypeInterface` — add `includeInViewContext(): bool`
- `DotAnnotationType` — implement, read from `$this->in_view_context ?? FALSE`
- `AnnotationTypeForm` (in `dot_type_ui`) — add checkbox to the behavior fieldset via `hook_form_annotation_type_form_alter`, same pattern as `dot_coverage` uses for `affects_status` and `dot_ai_context` uses for `in_ai_context`
- Default config YML files — set `in_view_context: true` for `editorial`, `false` for `technical` and `rules`
- No update hooks — reinstall after schema changes

### 2. Display mode awareness

`hook_entity_view_alter` receives `$context['display_mode']` (e.g. `full`, `teaser`, `search_index`). Only fire on `full` by default. More importantly: a field in the `annotation_target` fields map may not be rendered in the current display mode. Before injecting a trigger for a field, check whether it actually appears in the active `EntityViewDisplay`:

```php
$display = $this->entityTypeManager
  ->getStorage('entity_view_display')
  ->load($entity_type_id . '.' . $bundle . '.' . $display_mode);
$rendered_fields = array_keys($display->getComponents());
// Only inject triggers for fields in both annotation_target->getFields() AND $rendered_fields.
```

If the field is in DOT scope but hidden in this display mode, skip its trigger. This prevents orphaned `?` buttons with no corresponding field visible on the page.

### Admin route guard

Only fire on admin routes. Check via `RouteMatchInterface`:

```php
$is_admin = (bool) $this->routeMatch->getRouteObject()?->getOption('_admin_route');
if (!$is_admin) {
  return;
}
```

View-page overlays must never appear on public-facing pages. This is the primary guard — `in_view_context` is secondary (controls which types show, not whether the overlay fires at all).

### `hook_entity_view_alter` implementation sketch

```php
#[Hook('entity_view_alter')]
public function entityViewAlter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display): void {
  // 1. Admin route guard.
  // 2. Permission check: view annotations overlay.
  // 3. Load annotation_target for entity_type + bundle.
  // 4. Load visible types filtered by in_view_context AND consume {type} annotations permission.
  // 5. Load annotations via AnnotationStorageService::getForTarget().
  // 6. Filter to fields that are both in annotation_target->getFields() and in $display->getComponents().
  // 7. Inject data-annotations-field attribute and annotations_overlay_trigger into $build[$field_name].
  // 8. Inject annotations_overlay_panels container into $build at weight 998.
  // 9. Attach library + drupalSettings.
}
```

The bundle trigger (`_bundle` / "About X") applies here too — inject it at weight -1000 in the build array, same as the form.

### What carries over from formAlter without changes

- `buildDialog()` private method — call with `in_view_context` filter on types; dialog renders the same way
- `filterAnnotationEntities()` private method — identical
- `loadVisibleAnnotationTypes()` — reuse, but add `in_view_context` filter
- `annotations_overlay/overlay` library — JS and CSS already work off `data-annotations-field` attributes; no JS changes needed

### Caching requirements for hook_entity_view_alter

`hook_entity_view_alter` receives a `$build` that already carries the entity's own cache tags (e.g. `node:1`, `taxonomy_term:4`). These must not be lost. Use `CacheableMetadata` to merge DOT's metadata in:

```php
use Drupal\Core\Cache\CacheableMetadata;

$cache = CacheableMetadata::createFromRenderArray($build);
$cache->addCacheTags(['annotation_list', 'annotation_target_list', 'annotation_type_list']);
$contexts = ['user.permissions', 'languages:language_interface'];
if (\Drupal::languageManager()->isMultilingual()) {
  $contexts[] = 'languages:content';
}
$cache->addCacheContexts($contexts);
$cache->applyTo($build);
```

Do not assign `$build['#cache']` directly — that would replace the entity's existing tags.

**Per-entity-type caching exposure (all currently guarded by the admin route check):**

- **NodeTarget** (`node`) — nodes have public canonical URLs (`/node/{nid}`); if the admin guard is ever relaxed, anonymous users could receive stale overlay content. Keep the guard unconditional.
- **TaxonomyTarget** (`taxonomy_term`) — term pages (`/taxonomy/term/{tid}`) are routinely public; same concern as node.
- **UserTarget** (`user`) — user profiles can be semi-public; same guard requirement.
- **MediaTarget** (`media`) — canonical media pages are site-dependent but can be public; same guard requirement.
- **ParagraphTarget** (`paragraph`) — no standalone view page, but `hook_entity_view_alter` fires for every paragraph rendered inside a parent entity's view. Add an explicit guard to skip paragraph entities in this hook, or the overlay will inject into every paragraph component within a node view.
- **GenericTarget** — catches any fieldable entity not claimed by a specific plugin; public view pages are possible for unknown entity types. Admin guard must apply unconditionally regardless of entity type.
- **RoleTarget, WorkflowTarget, MenuTarget, ViewTarget** — config entities with no canonical rendered view page; `hook_entity_view_alter` will not fire for these in practice.

### What is different from formAlter

- Field containers on view pages are rendered by field formatters and may have different wrapping structure. The `[data-annotations-field]` CSS rule positions triggers absolutely within the container — this will likely need CSS tweaks once tested against real output. Check with the admin theme (Gin) specifically.
- No `#weight` key in view build arrays — use `array_unshift` or `#weight` directly on `$build[$field_name]['annotations_overlay_trigger']` (render arrays support `#weight` anywhere).
- `EntityViewDisplayInterface` is already passed to `hook_entity_view_alter` — no extra load needed for the display mode components check.

### New services argument needed

`AnnotationsOverlayHooks` already has `RouteMatchInterface` injected. No new constructor args required for the hook itself. The `entity_view_display` storage load uses the already-injected `EntityTypeManagerInterface`.

### Display mode note

Only fire on `full` display mode initially. Do not fire on `teaser`, `search_index`, `rss`, `card`, or any other non-full mode. These are listing/aggregation contexts where the overlay would be intrusive and the full field set is not visible anyway. If per-display-mode configuration is ever needed, add it as a setting rather than hardcoding, but `full`-only is the right default.

### Layout Paragraphs / Mercury Editor testing note

When this is built, test that the view-page overlay does not fire on rendered paragraph entities embedded inside a node view. `ParagraphTarget` entities are fieldable and may have `annotation_target` entries — but a paragraph rendered inside a node view is not the primary editorial surface. Consider guarding against embedded/referenced entity contexts, or accepting that paragraph triggers appearing inside a node view is acceptable. Test first before deciding.

## Bundle chooser pages

`hook_preprocess_node_add_list` and `hook_preprocess_entity_add_list` inject bundle-level annotation text as supplementary description text on entity type chooser pages (e.g. `/node/add`, `/block/add`, `/media/add`). Visibility is gated by `view annotations overlay` + per-type `consume {type} annotations` permissions. The `entity_add_list` hook derives the entity type from the `entity_type_id` route parameter, covering all entities using `EntityController::addPage()`.

## Workflow integration (dot_workflow)

All `getForTarget()` calls in `AnnotationsOverlayHooks` pass `TRUE` as `$published_only`. When `dot_workflow` is installed only published annotations appear in overlays. When `dot_workflow` is not installed the filter is a no-op and all non-empty annotations appear as before.

## Current status

- [x] `AnnotationsOverlayHooks` — `hook_form_alter`, `hook_theme`
- [x] Field-level and bundle-level triggers
- [x] Modal display — `annotations_overlay_wrapper` renders `<dialog>` with content server-side; JS calls `showModal()` only
- [x] Per-type visibility filtering (`consume {type} annotations`)
- [x] Per-item edit link (pencil icon, opens annotation edit form in new tab; shown only when user has `edit {type} annotations` or `edit any annotation`)
- [x] Bundle annotation text on node/entity add chooser pages (`hook_preprocess_node_add_list`, `hook_preprocess_entity_add_list`)
- [x] Inline paragraph subform overlays (`injectParagraphSubformOverlays()`) — dialogs placed inside the Paragraphs field wrapper to survive AJAX replacement
- [x] Layout Paragraphs component dialog overlays — handled by the existing `getParagraph()` branch in `formAlter()`
- [ ] Display on entity view pages
