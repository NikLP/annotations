# CLAUDE.md — annotations_overlay

Submodule of Annotations. See [CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this module does

Surfaces annotation content while users work. Field-level `?` triggers on entity edit forms and view pages open annotation text in `<dialog>` modals. No dependency on `annotations_ui` or `annotations_context`.

## What it owns

- `AnnotationsOverlayHooks` (`src/Hook/`) — `hook_entity_base_field_info`, `hook_theme`, `hook_form_alter`, `hook_entity_view_alter`, chooser preprocesses
- `AnnotationsOverlayItem` / `AnnotationsOverlayFormatter` (`src/Plugin/Field/`) — computed field (`no_ui: TRUE`); formatter exposes `annotation_view_mode` setting in Manage Display UI
- Templates: `annotations-overlay-wrapper.html.twig`, `annotations-overlay-item.html.twig`, `annotations-overlay-chooser.html.twig`, `annotations-overlay-chooser-item.html.twig`
- Library: `annotations_overlay/overlay` — `js/annotations-overlay.js`, `css/annotations-overlay.css`
- Permission: `view annotations overlay`

## Data contract

```php
$entity_map = $this->annotationStorage->getEntityMapForTarget($target_id, TRUE);
// Returns: array<string, array<string, Annotation>>
// ['field_name' => ['type_id' => Annotation], '' => ['type_id' => Annotation]]
// '' key = bundle-level annotation (field_name IS NULL in DB)
// TRUE = published-only filter (no-op when annotations_workflows absent)
```

Both `hook_form_alter` and `hook_entity_view_alter` follow: `getEntityMapForTarget()` → `filterAnnotationEntities()` → `buildDialog()`. If a third attachment context is added, extract to a `buildDialogsForTarget(string $target_id, array $visible_types): array` service method.

## JS architecture

Plain IIFE, no jQuery, no Drupal.behaviors. All content is server-rendered inside `<dialog>` elements at page load — no AJAX. Triggers call `showModal()` on `<dialog data-annotations-field="...">`. Events are delegated to `document` so dynamically injected dialogs (e.g. Paragraphs AJAX) work without re-init.

## Annotation visibility

Overlays are injected when: (1) user has `view annotations overlay`, (2) form implements `EntityFormInterface` or has `getParagraph()`, (3) an `annotation_target` exists for the entity type + bundle, (4) at least one annotation is non-empty for a type the user can `consume`.

**`single_type`:** `isSingleType()` checks the full visible annotation set across the page. When only one type appears, `single_type = TRUE` suppresses per-item type headings.

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

**Claro quirk:** Claro's `claro_preprocess_node_add_list` rebuilds `bundles` from `$type->getDescription()` after all module preprocesses, overwriting `$variables['types']`. Fix: mutate the entity description in memory (`$type->set('description', ...)`) before Claro runs; Claro then reads the modified value. Render via `renderInIsolation()`. Request-scoped only — no entity save.

## Parked work

- **Service extraction:** `buildDialogsForTarget()` to DRY up form/view attachment duplication (load → filter → build).
- **Module split:** base (library, `buildDialog()`, computed field) / `annotations_overlay_edit` (form alter) / `annotations_overlay_view` (view alter + Manage Display). The two attachment contexts have opposite caching concerns and independent opt-in mechanisms.
- **Per-type view-page suppression:** `show_on_view_pages` third-party setting on `AnnotationType`, owned here. (1) Schema entry in `annotations_overlay.schema.yml`. (2) Checkbox via `hook_form_alter` on `annotation_type_edit_form`. (3) Skip types in `entityViewAlter` where setting is `FALSE`. Needed when a role legitimately has `consume {type}` for edit forms but should not see that type on view pages.
- **Gin description toggle:** inject annotation text into `#description` + `#description_toggle: TRUE` to reuse Gin's reveal UX. Parked — ties module to Gin dependency.
- **Chooser view mode:** dedicated `chooser` annotation view mode separate from `overlay`.
