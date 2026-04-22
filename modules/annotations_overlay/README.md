# Annotations Overlay

Submodule of [Annotations](../../README.md). Surfaces annotation content to users while they work — field-level "?" triggers on entity forms and view pages, modal dialogs with server-rendered content, and annotation text on entity type chooser pages (when "Show overviews on entity select screens" is enabled in module Settings, defaults to On).

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

- **Field-level "?" modal triggers** on entity edit and add forms. Clicking opens a `<dialog>` with annotation content for that field. A **bundle-level (overview) trigger** appears at the top when an overview annotation exists — this is the target's bundle-level slot (`field_name = ''` in storage), covering the entity type as a whole rather than any specific field.
- **View page overlays** on entity view pages (e.g. `/node/1`). Same "?" UX as forms. Opt-in per view mode via Manage Display.
- **Bundle chooser descriptions** on entity type chooser pages (e.g. `/node/add`, `/media/add`). Bundle-level annotation text appears alongside each content type. Requires "Show overviews on entity select screens" enabled in module settings.
- **Inline paragraph subform overlays** inside Paragraphs widget fields. Dialogs are placed inside the Paragraphs field wrapper to survive AJAX replacement.
- **Layout Paragraphs component overlays** inside component edit dialogs (Mercury Editor / layout_paragraphs widget).

---

## Permissions

| Permission | Notes |
| --- | --- |
| `view annotations overlay` | Seeing "?" triggers, dialog panels, and chooser descriptions. Grant to editor roles. |

Per-type `consume {type} annotations` permissions (defined by `annotations`) filter which annotation types appear inside consumer applications. A user with `view annotations overlay` but only `consume editorial annotations` will only see editorial content in e.g.overlays.

---

## Opt-in for view page overlays

View page overlays require explicit opt-in per view mode. Go to **Manage Display** for the content type and drag the **Annotations overlay** field into a visible region. The **Annotation view mode** formatter setting controls which annotation view mode is used inside dialogs (default: `overlay`).

The **Annotations overlay** field only appears in Manage Display for entity types that have at least one `annotation_target` configured. If the field is not yet visible after creating a new target, run `drush cr`.

Form overlays are automatic — no Manage Display setup required.

---

## Templates

Four theme hooks, split into two pairs by context.

### Dialog overlays

| Hook | Template | Purpose |
|---|---|---|
| `annotations_overlay_wrapper` | `annotations-overlay-wrapper.html.twig` | Outer `<dialog>` with heading, close button, and items. |
| `annotations_overlay_item` | `annotations-overlay-item.html.twig` | Single annotation type. Variables: `type_id`, `type_label`, `content`, `edit_url`, `single_type`. |

### Chooser page descriptions

| Hook | Template | Purpose |
|---|---|---|
| `annotations_overlay_chooser` | `annotations-overlay-chooser.html.twig` | Wraps the original bundle description and annotation items. Variables: `description`, `items`. |
| `annotations_overlay_chooser_item` | `annotations-overlay-chooser-item.html.twig` | Single annotation type. Variables: `type_id`, `type_label`, `content`. |

See CLAUDE.md for why these use separate hooks rather than theme suggestions on the dialog hooks.

---

## JS architecture

`annotations-overlay.js` is a plain IIFE with no jQuery or Drupal.behaviors. All annotation content is server-rendered inside `<dialog>` elements at page load — no AJAX round-trips. Clicking a trigger calls `showModal()` on the matching `<dialog data-annotations-field="...">`. Event handling is delegated to `document` so dynamically injected dialogs (paragraph AJAX) work without re-initialisation.

---

## Bundle chooser pages

`hook_preprocess_node_add_list` and `hook_preprocess_entity_add_list` inject bundle-level annotation content when `show_bundle_chooser_overview` is enabled. Claro/Gin compatibility: Claro's theme-level preprocess rebuilds `bundles` from the entity description directly, bypassing module-set variables. The fix sets the rendered annotation content on the entity object in memory before Claro runs.

---

## Paragraph support

Inline paragraph subform overlays place dialogs inside the Paragraphs field wrapper rather than the global container, so they survive AJAX replacement when paragraphs are added. Field keys are prefixed `para__{bundle}__` to avoid collisions with parent-form fields.

Layout Paragraphs component dialogs (Mercury Editor) are handled by the `getParagraph()` branch in `formAlter()` and treated as standalone entity forms — no AJAX concern applies.

No hard dependency on the Paragraphs module — detection is structural array inspection.

---

## Parked / planned

- **`chooser` view mode** — dedicated annotation view mode for chooser pages, independent of dialog content.
- **`in_view_context` behavior** — suppress specific types from view page overlays while keeping them on forms.
- **`buildDialogsForTarget()` service method** — shared entry point for third-party attachment contexts.
- **Module split** — base library + `annotations_overlay_edit` (form alter) + `annotations_overlay_view` (view alter). See CLAUDE.md for detail.
