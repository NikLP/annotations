# CLAUDE.md — annotations_webform

Submodule of Annotations. See [CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this module does

Integrates the Annotations suite with the Webform module. Provides two target plugins and a field label resolver so annotation overlays work correctly on webform submission forms.

## What it owns

- `WebformTarget` (`src/Plugin/Target/`) — annotate webform config entities at the bundle level; no field-level annotations. Scope key: `webform__{webform_id}`.
- `WebformSubmissionTarget` (`src/Plugin/Target/`) — annotate webform submission forms with per-element `?` triggers. Enumerates webform input elements (via `getElementsInitializedFlattenedAndHasValue()`) as the fields list. Scope key: `webform_submission__{webform_id}`.
- `AnnotationsWebformHooks` (`src/Hook/`) — implements `hook_annotations_overlay_field_label_alter` to resolve webform element `#title` values so dialog headings match the visible form label rather than the element machine name.

## Two targets, two purposes

| Target | Scope key | Use |
| --- | --- | --- |
| `WebformTarget` | `webform__{id}` | "What is this form for, who should fill it in, workflow notes" — bundle-level only |
| `WebformSubmissionTarget` | `webform_submission__{id}` | Per-element `?` overlays on the submission form — contextual help for people filling it in |

These are independent. You can opt in to either or both.

## How the overlay works

`WebformSubmissionForm` extends `ContentEntityForm` and implements `EntityFormInterface`, so `hook_form_alter` in `annotations_overlay` fires for webform submission forms without any additional code. The `$entity` returned by `getEntity()` is an unsaved `webform_submission` with `bundle()` = the webform ID.

Once a `webform_submission__{id}` annotation target is configured, the overlay module injects `?` triggers and dialogs exactly as it does for node/media/etc. edit forms.

## Field label resolution

`AnnotationsOverlayService::resolveFieldLabel()` tries `field_config` and falls back to the machine name. Webform elements have no `field_config` entity, so without the alter hook they would fall back to `first_name` rather than "First Name". `AnnotationsWebformHooks` implements `hook_annotations_overlay_field_label_alter` to substitute the element `#title` (what the user sees on the form). `#admin_title` is used as a secondary fallback if `#title` is empty; if both are NULL the machine name stands.

## Nested element limitation

`getElementsInitializedFlattenedAndHasValue()` returns a flat list of all input elements regardless of nesting. However, `hook_form_alter` trigger injection checks `isset($form[$key])` — elements nested inside containers or fieldsets are not top-level in the form render array and are silently skipped. Flat webforms work fully. This is the same limitation as field_group (documented in annotations_overlay CLAUDE.md) and has the same resolution path: a dedicated hook that fires after the nesting is applied.

## Parked work

- **Nested element triggers:** requires a `hook_webform_element_alter` or walking the form tree to find nested elements. Low priority — most annotation-worthy elements in simple LMS/onboarding forms are flat.
