# CLAUDE.md — annotations_profile

## What this module does

Injects annotation overlay triggers and dialogs into Profile fields embedded in the **user account edit** (`/user/{uid}/edit`) and **user registration** (`/user/register`) forms via `ProfileFormWidget`.

Standalone profile forms at `/profile/{profile}/edit` do **not** need this module — `annotations_overlay` already handles those because `ProfileForm` implements `EntityFormInterface`.

## What it owns

- `AnnotationsProfileHooks` (`src/Hook/`) — a single `hook_form_alter` that detects Profile sub-forms inside user entity forms and injects overlay triggers + dialogs.

## Detection strategy

`ProfileFormWidget::formElement()` stamps `#bundle` onto `$form['{bundle}_profiles']['widget'][$delta]['entity']` and stores the profile entity in form state at `['profiles', $bundle, $delta]`. Both are checked to confirm this is a profile sub-form before injecting. No coupling to field name conventions.

## Key prefix

Field keys are prefixed `profile__{bundle}__` (e.g. `profile__customer__field_bio`) to prevent collisions with user-entity fields sharing the same machine name.

## Dialogs placement

Dialogs go inside `$form['{bundle}_profiles']['widget']`, mirroring how `annotations_overlay` places paragraph dialogs inside the Paragraphs field wrapper.

## To demonstrate

1. Install `profile` and this module.
2. Create a profile type (e.g. "Customer") at `/admin/config/people/profile-types/add`. Enable "Show during registration".
3. Add fields to it (Field UI at `/admin/config/people/profile-types/manage/customer/fields`).
4. Create an `annotation_target` for `profile__customer` and add field-level annotations.
5. Visit `/user/register` or `/user/{uid}/edit` — annotation `?` triggers appear on the embedded profile fields.
