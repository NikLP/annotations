# CLAUDE.md — annotations_overlay

Submodule of Annotations. See [CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this module does

Surfaces annotation content while users work. Field-level `?` triggers on entity edit forms and view pages open annotation text in `<dialog>` modals. No dependency on `annotations_ui` or `annotations_context`.

## What it owns

- `AnnotationsOverlayService` (`src/Service/`) — shared dialog-building logic; injected into the hooks class
- `AnnotationsOverlayHooks` (`src/Hook/`) — `hook_entity_base_field_info`, `hook_theme`, `hook_form_alter`, `hook_entity_view_alter`, chooser preprocesses
- `AnnotationsOverlayItem` / `AnnotationsOverlayFormatter` (`src/Plugin/Field/`) — computed field (`no_ui: TRUE`); formatter exposes `annotation_view_mode` setting in Manage Display UI
- Templates: `annotations-overlay-wrapper.html.twig`, `annotations-overlay-item.html.twig`, `annotations-overlay-chooser.html.twig`, `annotations-overlay-chooser-item.html.twig`
- Library: `annotations_overlay/overlay` — `js/annotations-overlay.js`, `css/annotations-overlay.css`
- Permission: `view annotations overlay`

## Service: AnnotationsOverlayService

`annotations_overlay.service` — handles the shared load → filter → build pipeline used by both form and view attachment contexts.

**Public API:**

```php
// Load annotation types the current user can consume, sorted by weight.
$visible_types = $service->loadVisibleAnnotationTypes();

// Build dialog render arrays for a target. Returns NULL if no annotation_target
// exists for $target_id. Otherwise returns:
// [
//   'target_label'           => string,
//   'bundle_annotations'     => array<string, Annotation>,   // keyed by type_id
//   'fields_with_annotations'=> array<string, array<string, Annotation>>,
//   'dialogs'                => array<string, array>,        // keyed by field_key
// ]
$overlay_data = $service->buildDialogsForTarget(
  $target_id,          // e.g. 'node__article'
  $visible_types,
  $annotation_view_mode,  // default 'overlay'
  $rendered_fields,       // [] = all fields; non-empty = restrict to these (view alter)
  $key_prefix,            // '' = none; 'para__{bundle}__' for paragraph subforms
);

// Build a single dialog render array directly (used by annotationPreviewViewAlter).
$dialog = $service->buildDialog($field_key, $label, $annotation_entities, $single_type, $view_mode);

// Resolve a field's human-readable label from config.
$label = $service->resolveFieldLabel($entity_type_id, $bundle, $field_name);
```

**Not in the service:** chooser-page rendering (`buildBundleAnnotationHtml`, `buildBundleAnnotationRenderItems`) stays in the hooks class because it bypasses the render pipeline deliberately and uses a different output format.

## Data contract

```php
$entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
// Returns: array<string, array<string, Annotation>>
// ['field_name' => ['type_id' => Annotation], '' => ['type_id' => Annotation]]
// '' key = bundle-level annotation (field_name IS NULL in DB)
// TRUE = published-only filter (no-op when annotations_workflows absent)
```

Both `hook_form_alter` and `hook_entity_view_alter` call `$this->overlayService->buildDialogsForTarget()` and then inject triggers and dialogs from the returned data. The hooks own trigger markup; the service owns dialog markup.

## JS architecture

Plain IIFE, no jQuery, no Drupal.behaviors. All content is server-rendered inside `<dialog>` elements at page load — no AJAX. Triggers call `showModal()` on `<dialog data-annotations-field="...">`. Events are delegated to `document` so dynamically injected dialogs (e.g. Paragraphs AJAX) work without re-init.

## Annotation visibility

Overlays are injected when: (1) user has `view annotations overlay`, (2) form implements `EntityFormInterface` or has `getParagraph()`, (3) an `annotation_target` exists for the entity type + bundle, (4) at least one annotation is non-empty for a type the user can `consume`.

**`single_type`:** `isSingleType()` checks the full visible annotation set across the page — bundle annotations and field annotations. When only one type appears, `single_type = TRUE` suppresses per-item type headings.

## Permission model

| Permission | What it gates |
| --- | --- |
| `view annotations overlay` | `?` triggers and panels |
| `consume {type} annotations` | Which types appear inside panels |

## Paragraph support

**Inline paragraphs:** `injectParagraphSubformOverlays()` finds subforms at `$form[$field][$widget][$delta]['subform']`. Dialogs go **inside `$form[$field_name]['widget']`** — not the global container — so they survive AJAX replacement when a paragraph is added. Field keys are prefixed `para__{bundle}__` to avoid collision with parent fields. Check is structural; no hard Paragraphs dependency.

**Layout Paragraphs:** Component edit dialogs have `getParagraph()` on the form object. Caught by `elseif (method_exists($form_object, 'getParagraph'))` in `formAlter()`; treated as standalone entity forms — dialogs go into the standard container.

## View page overlay

Opt-in per view mode via Manage Display — drag `Annotations overlay` field into a region. `hook_entity_base_field_info` registers the field only on types with an `annotation_target`. `hook_entity_view_alter` fires when the field is in the active display's components.

`annotation_view_mode` formatter setting controls which annotation view mode renders inside dialogs (default: `overlay`).

Display mode filtering: fields hidden in the current display are skipped. Paragraph guard: `paragraph` entities skipped unconditionally (no standalone view page; `hook_entity_view_alter` fires for every embedded paragraph).

Caching: tags `annotation_list`, `annotation_target_list`, `annotation_type_list`; contexts `user.permissions`, `languages:language_interface`, `languages:content` (guarded with `isMultilingual()`). Merged via `CacheableMetadata::createFromRenderArray()` — never replace `$build['#cache']` directly.

## Bundle chooser pages

`hook_preprocess_node_add_list` / `hook_preprocess_entity_add_list` inject bundle-level annotation text on `/node/add` etc. as supplementary descriptions. Gated by `view annotations overlay` + `consume {type}`.

Themes: `annotations_overlay_chooser` (description + items) and `annotations_overlay_chooser_item` (type label + rendered entity). Separate hooks from dialog hooks because variable signatures differ.

**Claro/Gin quirk:** Claro's `claro_preprocess_node_add_list` (inherited by Gin) rebuilds `bundles` from `$type->getDescription()` after all module preprocesses, overwriting `$variables['types']`. Fix: mutate the entity description in memory (`$type->set('description', ...)`) before Claro/Gin runs; it then reads the modified value. Request-scoped only — no entity save. `$variables['types']` is also updated for themes that read it directly.

**Chooser rendering:** HTML is built by `buildBundleAnnotationHtml()` — direct string construction from the `value` base field rather than via the render pipeline or a view mode. This is intentional: calling `renderInIsolation()` from inside `preprocessNodeAddList` nests a PHP Fiber inside Drupal's existing render Fiber, which overflows the stack on cold Twig cache. `buildBundleAnnotationRenderItems()` exists as the view-mode-capable alternative but cannot be called from a preprocess hook for this reason. The chooser works correctly as long as annotation content lives in the `value` field (the default). Edge case: if an annotation type stores its content in a separate field (e.g. a wysiwyg), the chooser will show nothing for that type — the view-mode path would be needed.

## Parked work

- **Module split:** `AnnotationsOverlayService` is the natural shared dependency. The planned split is `annotations_overlay_edit` (form alter), `annotations_overlay_view` (view alter + Manage Display), and `annotations_field_group` (field_group bridge — see below). The service, library, and computed field would move to the base `annotations_overlay` module. Primary driver: edit overlays without view overlays (content editors only).
- **field_group compatibility:** field_group nests fields into group containers during the theme preprocess phase — after `hook_entity_view_alter` and `hook_form_alter` have already run. Trigger buttons added here as top-level build siblings are left at the build root while their associated fields move into groups, breaking CSS positioning. The fix is `hook_field_group_build_pre_render_alter` (view) and `hook_field_group_form_process_build_alter` (form), which fire after nesting and allow trigger reparenting into the correct group container. This belongs in a dedicated `annotations_field_group` submodule rather than here: it requires declaring `field_group` as a hard module dependency, and conditional `moduleExists()` guards around hook implementations are a code smell. The fix is self-contained — the field_group hooks expose `#fieldgroups` children lists, so no access to this module's internals is needed. Paragraphs support stays here (no hard dependency; structural duck-typing works and is already shipped).
- **Gin description toggle:** inject annotation text into `#description` + `#description_toggle: TRUE` to reuse Gin's reveal UX. Parked — ties module to Gin dependency.
- **Chooser view mode:** dedicated `chooser` annotation view mode separate from `overlay`. Requires replacing `buildBundleAnnotationHtml()` with `buildBundleAnnotationRenderItems()`, which needs the preprocess-to-render-pipeline restriction resolved first (e.g. pre-rendering via a `KernelEvents::VIEW` subscriber).
