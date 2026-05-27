# CLAUDE.md — annotations_explorer

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

## What this module does

Provides an explorer-style read-only viewer for annotations at `/annotations/explorer`. Two-panel layout: target navigation (left), annotation content (right). AJAX panel switching.

Consumer-facing: the page is gated on having at least one `consume {type} annotations` permission. Type visibility is further filtered per-type. Targets with no visible content for the current user are hidden from the nav.

## What it owns

- `ExplorerController` — full page (`page()`) and AJAX target panel (`targetPanel()`)
- `annotations_explorer` Twig theme hook via `AnnotationsExplorerHooks::theme()`
- `annotations-explorer.html.twig` — two-panel layout wrapper
- CSS and JS for layout and AJAX active-state management

## Routes

| Route | Path | Notes |
| --- | --- | --- |
| `annotations_explorer.page` | `/annotations/explorer` | Full page; defaults to first target |
| `annotations_explorer.target` | `/annotations/explorer/{annotation_target}` | AJAX endpoint; falls back to redirect with `?target=` if non-AJAX |

## Nav structure

Targets are grouped by entity type. Each group is introduced by a muted heading `<li>` (`aria-hidden`) showing the entity type label (e.g. "Content", "Taxonomy term"). Within each group, targets are `<details>` elements; the `<summary>` is the target name and the AJAX link. When a target is active (`open`), its sections (Overview + field names) appear as anchor links inside the details, pointing to the corresponding groups in the main panel.

Entity type labels are resolved via `EntityTypeManager::getDefinition()`, falling back to the machine name.

## Permissions

Custom access callback (`ExplorerController::access`) allows entry if the user has at least one `consume {type} annotations` permission. No admin permission required — this is a consumer-facing route.

## Deferred

- Active-state URL sync (browser back/forward)
