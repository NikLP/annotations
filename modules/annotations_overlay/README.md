# Annotations Overlay

Submodule of [Annotations](../../README.md). Surfaces annotation content to users while they work — field-level "?" triggers on entity forms and view pages, modal dialogs with server-rendered content, and annotation text injected into entity type chooser pages.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_overlay
ddev drush cr
```

---

## What it does

- **Field-level "?" modal triggers** on entity edit and add forms. Clicking a trigger opens a `<dialog>` showing annotation content for that field. A bundle-level trigger appears at the top of the form when a bundle-level annotation exists.
- **View page overlays** on entity view pages (e.g. `/node/1`). Same "?" UX as forms. Opt-in per view mode via Manage Display.
- **Bundle chooser descriptions** on entity type chooser pages (e.g. `/node/add`, `/media/add`). Bundle-level annotation text appears as supplementary description text alongside each content type.
- **Inline paragraph subform overlays** inside Paragraphs widget fields on parent entity forms. Dialogs are placed inside the Paragraphs field wrapper so they survive AJAX replacement when paragraphs are added.
- **Layout Paragraphs component overlays** inside component edit dialogs (Mercury Editor / layout_paragraphs widget).

---

## Permissions

| Permission | Notes |
| --- | --- |
| `view annotations overlay` | Seeing "?" triggers, dialog panels, and chooser descriptions. Grant to editor roles. |

Per-type `consume {type} annotations` permissions (defined by `annotations`) filter which annotation types appear inside panels. A user with `view annotations overlay` but only `consume editorial annotations` will only see editorial content in overlays.

---

## Opt-in for view page overlays

View page overlays require explicit opt-in per view mode. Go to **Manage Display** for the content type and drag the **Annotations overlay** field into a visible region. The formatter's **Annotation view mode** setting controls which annotation entity view mode is used to render content inside dialogs (default: `overlay`).

Form overlays are automatic — no Manage Display setup required.

---

## Templates

Four theme hooks, split into two pairs by context.

### Dialog overlays

| Hook | Template | Purpose |
|---|---|---|
| `annotations_overlay_wrapper` | `annotations-overlay-wrapper.html.twig` | Outer `<dialog>` with heading, close button, and items. |
| `annotations_overlay_item` | `annotations-overlay-item.html.twig` | Single annotation type inside a dialog. Variables: `type_id`, `type_label`, `content`, `edit_url`, `single_type`. |

### Chooser page descriptions

| Hook | Template | Purpose |
|---|---|---|
| `annotations_overlay_chooser` | `annotations-overlay-chooser.html.twig` | Wraps the original bundle description and annotation items on a chooser page. Variables: `description`, `items`. |
| `annotations_overlay_chooser_item` | `annotations-overlay-chooser-item.html.twig` | Single annotation type on a chooser page. Variables: `type_id`, `type_label`, `content`. |

**Why separate chooser hooks rather than theme suggestions on the dialog hooks?** The variable signatures genuinely differ: `annotations_overlay_chooser` has a `description` variable (the original entity description) with no equivalent in `annotations_overlay_wrapper`, which is dialog-shaped (`close_label`, `close_attributes`). For the item, `annotations_overlay_chooser_item` drops `edit_url` and `single_type`, which have no meaning in a chooser context. Two parallel pairs with minimal variable sets is clearer than suggestions that silently ignore half their variables.

---

## JS architecture

`annotations-overlay.js` is a plain IIFE with no jQuery or Drupal.behaviors. All annotation content is server-rendered inside `<dialog>` elements in the DOM at page load — no AJAX round-trips. Clicking a trigger calls `showModal()` on the matching `<dialog data-annotations-field="...">`. All event handling is delegated to `document` so dynamically injected dialogs (paragraph AJAX) work without re-initialisation.

---

## Bundle chooser pages

`hook_preprocess_node_add_list` and `hook_preprocess_entity_add_list` inject bundle-level annotation content. `buildBundleAnnotationRenderItems()` returns an `annotations_overlay_chooser` render array with individual `annotations_overlay_chooser_item` children (one per visible annotation type). Callers set `#description` to the original entity description before placing the array.

**Claro/Gin note:** Claro's `claro_preprocess_node_add_list` runs after all module preprocesses and rebuilds `$variables['bundles']` by reading `$type->getDescription()` directly, ignoring `$variables['types']`. The fix is to modify the entity description property in memory before Claro runs — the full chooser render array is rendered to a string via `renderInIsolation()` and set on the entity object. No entity save is triggered.

**Annotation view mode:** Chooser annotations are rendered via the `overlay` view mode. A dedicated `chooser` view mode would let site builders control what appears on chooser pages independently of dialog content — parked as complementary work.

**Architectural note:** The chooser feature has a different concern from the dialog/trigger overlay — no JS, no `<dialog>` chrome, just inline content injection. It lives here because the permission model is shared and the two features are almost always wanted together. If a use case emerges for chooser annotations without the dialog overlay (e.g. a site using Gin's description toggle for field help), this feature is self-contained enough to extract into its own submodule.

---

## Paragraph support

### Inline paragraphs (Paragraphs widget)

Paragraph subforms appear inline in the parent form at `$form[$field_name]['widget'][$delta]['subform']`. `injectParagraphSubformOverlays()` handles these: triggers go inside the subform; dialogs go inside `$form[$field_name]['widget']` (not the global dialogs container) so they survive AJAX replacement when a paragraph is added. Field keys are prefixed `para__{bundle}__` to avoid collisions with parent-form fields sharing the same machine name.

No hard dependency on the Paragraphs module — the check is purely structural array inspection.

### Layout Paragraphs (Mercury Editor)

`ComponentFormBase` component edit dialogs do not implement `EntityFormInterface` but expose the paragraph via `getParagraph()`. The existing guard in `formAlter()` handles this path. The dialog form is treated as a standalone entity form — no AJAX concern applies since the dialog fully rebuilds each time it opens.

---

## Parked / planned

- **`chooser` view mode** — dedicated annotation view mode for chooser pages, giving site builders independent control over what content appears there vs. in dialogs.
- **`in_view_context` flag on `AnnotationType`** — suppress specific types (e.g. `technical`, `rules`) from view page overlays while keeping them on forms. Schema field exists; gating logic not yet implemented.
- **Gin description toggle integration** — inject annotation text into `$form[$field_name]['#description']` with `#description_toggle: TRUE` and let Gin handle show/hide. Parked: ties the module to Gin as a dependency; only useful on Gin-themed sites.
- **Per-field display mode override** — allow the formatter to use a different annotation view mode per field rather than a single setting per display.
- **`buildDialogsForTarget()` service method** — the load → filter → build-dialog steps are duplicated across `hook_form_alter` and `hook_entity_view_alter`. Extracting them into a single service method would give third-party modules a clean entry point: call it, get back a ready-to-attach `dialogs` render array. Natural to add if a third attachment context (e.g. a standalone block or REST endpoint) is ever built.
- **Module split** — the edit-form attachment (`hook_form_alter`) and view-page attachment (`hook_entity_view_alter`) are independent concerns sharing only the data structure and dialog-building logic. A natural split would be: `annotations_overlay` (base library, service, templates) + `annotations_overlay_edit` (form alter, paragraph support) + `annotations_overlay_view` (view alter, Manage Display opt-in). The base module's service becomes the stable API. Parked until a concrete reason to split emerges.
