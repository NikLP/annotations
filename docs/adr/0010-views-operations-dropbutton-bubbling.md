# ADR 0010 — Views operations links and asset bubbling

## Status

Under investigation

## Context

The `dot_annotations` View (replacing the `AnnotateController` annotation overview) needs per-row operation links (Annotate, Edit, Delete). Using the standard Views "Operations links" field causes a 500 during AJAX preview:

```php
AssertionError: Bubbling failed.
core/lib/Drupal/Core/Render/Renderer.php:659
```

The dropbutton rendered by `#type => 'operations'` attaches the `dropbutton` library via `#attached`. When Views renders the field inside a sealed AJAX render context, Drupal's renderer asserts that no assets can escape it — the assertion fails.

## Options considered

**A — Plain link fields (no dropbutton)**
Add individual link fields in the View pointing directly to the annotate/edit/delete routes. No library attachment, no bubbling problem. Adequate for a fixed small set of operations.

**B — Custom Views field plugin with `executeInRenderContext`**
Wrap the operations render array in `Renderer::executeInRenderContext()` and manually merge bubbled metadata onto `$this->view->element`. Silences the assertion correctly. More code; warranted if a real dropbutton with variable operations is needed.

## Decision

Pending. Current workaround: leave the existing `AnnotateController` at `/admin/config/dot/annotate` in place until this is resolved.

## Consequences

If Option A: simpler, no custom plugin needed, but no dropbutton.
If Option B: proper dropbutton behaviour, but requires a custom field plugin for operations.
