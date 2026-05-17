# Annotations Profile

Injects annotation overlay triggers and dialogs into [Profile](https://www.drupal.org/project/profile) fields embedded in the user account edit and registration forms.

## Requirements

- [Annotations](https://www.drupal.org/project/annotations) + `annotations_overlay`
- [Profile](https://www.drupal.org/project/profile)

## What it does

When a profile type has "Show during registration" enabled, its fields appear inside the user form via `ProfileFormWidget`. This module detects those embedded profile sub-forms and injects the same `?` trigger buttons that `annotations_overlay` provides on standalone entity forms.

Standalone profile forms at `/profile/{profile}/edit` do not need this module.

## Setup

1. Create a profile type and add fields to it.
2. Add an `annotation_target` for `profile__{machine_name}` and write field-level annotations.
3. Enable this module — triggers appear on the embedded profile fields automatically.
