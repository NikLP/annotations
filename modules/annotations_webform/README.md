# Annotations Webform

Integrates the [Annotations](../../README.md) suite with the [Webform](https://www.drupal.org/project/webform) module.

## Requirements

- Annotations (`annotations`)
- Annotations Overlay (`annotations_overlay`)
- Webform (`webform`)

## What you get

Two independent annotation targets:

**`webform__{id}` — Webform (bundle-level)**
Annotate the webform itself: what it is for, who should use it, workflow guidance. No per-element annotations. Enable in Annotations → Scope.

**`webform_submission__{id}` — Webform submission (per-element)**
Shows `?` overlay triggers next to individual form elements while someone is filling in the form. Uses the element's visible label as the dialog heading. Enable in Annotations → Scope, then configure which elements are in scope.

## Setup

1. Enable this module: `ddev drush en annotations_webform`
2. Go to **Admin → Config → Annotations → Scope**
3. Expand **Webforms** to opt in specific forms for bundle-level annotations
4. Expand **Webform submissions** to opt in specific forms and configure which elements get `?` triggers
5. Add annotation content via **Admin → Config → Annotations**

## Limitation

Overlay triggers only appear for top-level form elements. Elements nested inside Webform containers, fieldsets, or wizard pages are not yet supported.
