# CLAUDE.md — annotations_overlay

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Surfaces annotation content to users while they work. When a user edits an entity, field-level "?" triggers appear beside each annotated field; clicking opens the annotation text in a modal dialog. A toolbar button exposes site-wide documentation on any page.

This module has no dependency on `annotations_ui` or `annotations_context`. It reads annotation content directly via `AnnotationStorageService` and renders it without going through the full context assembler pipeline.

## What it owns

- `AnnotationsOverlayHooks` (`src/Hook/`) — all hook implementations:
  - `hook_entity_base_field_info` — registers the `annotations_overlay` computed field on all fieldable entity types
  - `hook_theme` — registers `annotations_overlay_wrapper` and `annotations_overlay_item` theme hooks
  - `hook_form_alter` — injects field triggers, bundle trigger, and `<dialog>` elements into entity edit/add forms for opted-in targets, including inline paragraph subforms (see Paragraph support below)
  - `hook_entity_view_alter` — injects overlay when the `annotations_overlay` field is active in the current display
- `AnnotationsOverlayItem` (`src/Plugin/Field/FieldType/`) — computed field type (`no_ui: TRUE`)
- `AnnotationsOverlayFormatter` (`src/Plugin/Field/FieldFormatter/`) — formatter; surfaces `annotation_view_mode` setting in Manage Display UI; `viewElements()` returns empty (rendering is done in `entityViewAlter`)
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
| `view annotations overlay` | Seeing the "?" triggers and overlay panels. Grant to editor roles. The per-type `consume {type} annotations` permissions  filter which annotation types appear inside the panels. |

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

## View page overlay

View-page overlays fire on entity view pages (e.g. `/node/1`, `/taxonomy/term/4`). The same `?` trigger UX as forms — no separate UI to learn.

### Opt-in mechanism

Site builders opt in per view mode via **Manage Display**: drag the `Annotations overlay` field into a visible region on the entity's display. The field is registered on all fieldable entity types (except `annotation` itself) via `hook_entity_base_field_info`. `hook_entity_view_alter` fires when the field is present in the active display's components, so per-display-mode control falls out naturally from Drupal's standard display machinery.

The formatter's `annotation_view_mode` setting (exposed in the Manage Display UI) controls which annotation entity view mode is used to render content inside dialogs. Default: `overlay`.

### Design note

We considered storing `view_page_annotation_view_mode` and `view_page_display_mode` on the `AnnotationTarget` config entity and driving opt-in from there (no Manage Display setup needed). The problem: it replicated the same conceptual shape as the field approach — "pick a display mode, pick an annotation view mode" — just in a different UI. The field approach has Drupal's display machinery doing the heavy lifting and gives per-display-mode granularity for free. We kept the field.

### Display mode filtering for fields

Before injecting a trigger for a field, `entityViewAlter` checks whether the field appears in the active display's components. Fields in annotation scope but hidden in the current display mode are silently skipped — no orphaned `?` buttons.

### Paragraph guard

`entityViewAlter` skips `paragraph` entities unconditionally. Paragraphs have no standalone view page, but `hook_entity_view_alter` fires for every paragraph rendered inside a node view. Injecting triggers there would produce spurious overlays on every paragraph component within a node.

### Caching

Cache metadata is merged after the target and display-mode checks pass, before the permission check. Tags: `annotation_list`, `annotation_target_list`, `annotation_type_list`. Contexts: `user.permissions`, `languages:language_interface`, `languages:content` (conditional on multilingual). The entity's existing cache tags (e.g. `node:1`) are preserved via `CacheableMetadata::createFromRenderArray()` — `$build['#cache']` is never replaced directly.

## Bundle chooser pages

`hook_preprocess_node_add_list` and `hook_preprocess_entity_add_list` inject bundle-level annotation text as supplementary description text on entity type chooser pages (e.g. `/node/add`, `/block/add`, `/media/add`). Visibility is gated by `view annotations overlay` + per-type `consume {type} annotations` permissions. The `entity_add_list` hook derives the entity type from the `entity_type_id` route parameter, covering all entities using `EntityController::addPage()`.

**Architectural note:** The chooser feature is a different concern from the dialog/trigger overlay — no JS, no `<dialog>` chrome, just inline content injection. It lives here because the permission model is shared and the two features are almost always wanted together. If a use case emerges for chooser annotations without the dialog overlay (e.g. a site using Gin's description toggle for field help), this feature is self-contained enough to extract into its own submodule.

Output is themed via two hooks: `annotations_overlay_chooser` (wraps the original description + annotation items) and `annotations_overlay_chooser_item` (type label + rendered annotation entity). Both have dedicated templates. `buildBundleAnnotationRenderItems()` returns an `annotations_overlay_chooser` render array with `#description => NULL`; callers set `#description` to the original entity description before placing it.

Annotation entities are rendered via the `overlay` view mode. A dedicated `chooser` view mode would allow site builders to control what content appears on chooser pages independently of the dialog overlay — parked as complementary work.

**Claro/Gin compatibility note — `node_add_list`:** Claro's `claro_preprocess_node_add_list` is a theme-level preprocess that runs *after* all module preprocesses. It rebuilds `$variables['bundles']` from scratch by reading `$type->getDescription()` on each entity object directly — it does not read `$variables['types']`. Module-level `hook_preprocess_node_add_list` implementations cannot modify `bundles` because Claro overwrites it afterward. The fix is to modify the entity description property in memory (`$type->set('description', ...)`) before Claro runs; Claro then reads the modified description when building `bundles`. The Claro path renders the full `annotations_overlay_chooser` render array to string via `renderInIsolation()`. The in-memory change is request-scoped only — no entity save is triggered.

## Gin description toggle — parked

The Gin admin theme has a "form description toggle" feature (`show_description_toggle` setting, or `#description_toggle: TRUE` per element). When enabled, Gin hides form field descriptions behind a `?` reveal icon, which could be used as an alternative/complement to our custom dialog approach: inject annotation text into `$form[$field_name]['#description']` and set `#description_toggle: TRUE`, letting Gin handle the show/hide UI.

Parked because: our dialog approach is already built and works independently of Gin. The toggle would tie this module to Gin as a dependency and would only work on Gin-themed sites. Worth revisiting if we want tighter Gin integration or want to retire the custom dialog JS in Gin contexts.

## Workflow integration (annotations_workflows)

All `getForTarget()` calls in `AnnotationsOverlayHooks` pass `TRUE` as `$published_only`. When `annotations_workflows` is installed only published annotations appear in overlays. When `annotations_workflows` is not installed the filter is a no-op and all non-empty annotations appear as before.

## Current status

- [x] `AnnotationsOverlayItem` + `AnnotationsOverlayFormatter` + `hook_entity_base_field_info`
- [x] `hook_form_alter`, `hook_theme`, `hook_entity_view_alter`
- [x] Field-level and bundle-level triggers (forms and view pages)
- [x] Modal display — `annotations_overlay_wrapper` renders `<dialog>` with content server-side; JS calls `showModal()` only
- [x] Per-type visibility filtering (`consume {type} annotations`)
- [x] Per-item edit link (pencil icon, opens annotation edit form in new tab; shown only when user has `edit {type} annotations` or `edit any annotation`)
- [x] Bundle annotation text on node/entity add chooser pages (`hook_preprocess_node_add_list`, `hook_preprocess_entity_add_list`)
- [x] Inline paragraph subform overlays (`injectParagraphSubformOverlays()`) — dialogs placed inside the Paragraphs field wrapper to survive AJAX replacement
- [x] Layout Paragraphs component dialog overlays — handled by the existing `getParagraph()` branch in `formAlter()`
- [x] View page overlay — `hook_entity_view_alter`; opt-in via Manage Display per content type per view mode
