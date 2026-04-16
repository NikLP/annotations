# CLAUDE.md — Annotations

## Project Overview

Annotations is a contributed Drupal module suite that reads a Drupal site's structure — content types, fields, user roles, workflows — and exposes that information via a structured annotation system. The annotations drive in-context help overlays, documentation exports, and a chat interface all scoped to the specific site configuration.

**Primary audience:** Drupal agencies, using Annotations to reduce post-launch client support overhead.

---

## Repository Layout

```text
annotations/                ← root module (always required)
├── annotations.info.yml
├── annotations.module
├── CLAUDE.md               ← this file
├── README.md
└── modules/
    ├── annotations_scan/           ← CLAUDE.md, README.md
    ├── annotations_ui/             ← CLAUDE.md, README.md
    ├── annotations_type_ui/        ← CLAUDE.md, README.md
    ├── annotations_coverage/       ← CLAUDE.md, README.md
    ├── annotations_context/        ← CLAUDE.md, README.md
    ├── annotations_ai_context/     ← CLAUDE.md
    ├── annotations_overlay/        ← CLAUDE.md
    ├── annotations_workflows/      ← CLAUDE.md, README.md
    ├── annotations_demo/           ← ships default types, form displays, starter content
    └── annotations_delta/          ← not started
```

Each built submodule has its own `CLAUDE.md`. This file covers the root `annotations` module, cross-cutting conventions, the data model, and unbuilt modules.

---

## Caching — ongoing concern

Caching is an active requirement across this module suite, not a one-off task. Rules that apply everywhere:

- **Every new controller page that reads annotation data must declare `#cache`** — tags (`annotation_list`, `annotation_target_list`, `annotation_type_list`) and contexts (`languages:language_interface`, `user.permissions`, plus any query-arg or route contexts that affect output). Do this at build time, not retroactively. Always include `languages:language_interface` (always valid). Additionally include `languages:content` conditionally — `languages:content` requires the `language` module and throws a `RuntimeException` when it is not installed, but annotation content is translatable so the context is semantically required on multilingual sites. Use `$this->languageManager()->isMultilingual()` to guard it: `$this->languageManager()->isMultilingual() ? ['languages:content'] : []`.
- **`hook_entity_view_alter` implementations must merge cache metadata** using `CacheableMetadata::createFromRenderArray($build)->addCacheTags([...])->addCacheContexts([...])->applyTo($build)`. Never replace `$build['#cache']` directly — the entity's own cache tags (e.g. `node:1`) must not be lost.
- **Invalidation is automatic** — `EntityStorageBase` invalidates `{entity_type}_list` tags on every entity save and delete. No explicit `Cache::invalidateTags()` calls are needed for standard entity operations.
- **Extension point providers** (tagged context providers, alter hooks on the assembler payload) must contribute `CacheableMetadata` alongside their payload slice so the caller can merge it into the page cache.

See individual submodule `CLAUDE.md` files for feature-specific caching notes.

---

## Drupal.org Conventions

- Namespace: `annotations` (available on d.o)
- All module machine names are lowercase, no caps, no plurals, no participles where avoidable
- d.o standard tooling: DDEV, Composer, Drush
- PHP 8.3+, Drupal 11

---

## Accessibility

All UI output must target **WCAG 2.1 AA** as a minimum.

- Never use a bare symbol (e.g. `—`) as the sole content in a cell or label — use text (`N/A`, a visible label, or a visually-hidden screen-reader alternative)
- All form inputs must have visible labels or visually-hidden equivalents (`#title_display: invisible` is acceptable for table checkboxes where the column heading provides context, but the `#title` value must still be descriptive)
- Status/feedback text must be conveyed via text, not colour alone
- Links must have descriptive text — avoid "click here" or bare URLs
- Use Drupal's `$this->t()` for all user-facing strings
- Prefer Drupal's native form element types over raw HTML markup
- Heading hierarchy must be correct — `h2` for section groups, `h3` for subsections. `details/summary` elements are disclosure widgets (not headings) so heading levels inside them count from the nearest ancestor heading.

---

## Coding conventions

- **Drupal 11 hook classes:** All hook implementations live in `src/Hook/{ModuleName}Hooks.php` using the `#[Hook('hook_name')]` attribute. `.module` files are kept as empty stubs with only the `@file` docblock. Hook classes are registered as services with proper constructor DI — no `\Drupal::service()` calls inside hook methods.
- **`declare(strict_types=1)`** at the top of every PHP file.
- **Render arrays over markup:** Use `#type => 'html_tag'` for simple wrappers; `Markup::create()` with `Html::escape()` for developer-controlled complex HTML (icons with aria attributes). Never build HTML by string concatenation into `#markup` with variable content.
- **Accessible icons:** Status/scope glyphs use `<span aria-hidden="true">&#x2714;</span><span class="visually-hidden">Label</span>` — visual glyph hidden from screen readers, text label hidden from sighted users.
- **⚠️ IMPORTANT — annotation values are stored raw:** Annotation text is stored unfiltered (sanitize on output, not input). This is safe for admin-only contexts. **Any code that renders annotation values outside the admin UI — including `annotations_context`, `annotations_ai_context`, and any end-user-facing output — MUST escape values using `Html::escape()` (`Drupal\Component\Utility\Html`) wrapped in `Markup::create()`, or `#plain_text`. Never pass annotation values directly into `#markup` or string-concatenated HTML.**
- **`DraggableListBuilder` cell formats:** `DraggableListBuilder::buildForm()` has a special case that wraps `$row['label']` in `['#markup' => $row['label']]` — so `label` must return a **plain string** from `buildRow()` or it will be double-wrapped and cause a `Html::escape()` TypeError. All other non-form-element cells must use only `#`-prefixed keys (e.g. `['#plain_text' => '...']`, `['#markup' => Markup::create(...)]`) — the table element calls `Element::children()` on each cell and throws if it finds a non-`#` key with a non-array value. Form element cells (`#type => 'weight'` etc.) are fine as-is.

---

## Core Data Model

The central concept is the **annotation target** — one `annotation_target` config entity per annotatable unit.

### `AnnotationType` config entity

Config prefix: `annotations.annotation_type.*`

Each type defines:

- `id` — machine name (e.g. `editorial`, `technical`, `rules`)
- `label` — human-readable label
- `description` — what this type is for
- `weight` — integer; controls form/UI ordering (lower = shown first)
- `in_view_context` — boolean (planned); whether this type appears in view-page overlays. Allows `technical` and `rules` types to be suppressed on view pages while `editorial` shows. Not yet implemented — gate on this flag when the view-page overlay is built.

Additional behavior flags (e.g. `affects_coverage`, `in_ai_context`) are owned by their respective submodules as third-party settings via `ThirdPartySettingsInterface`. See root README for the extension pattern.

Key methods:

- `getPermission()` — returns `edit {id} annotations` (e.g. `edit editorial annotations`)
- `getConsumePermission()` — returns `consume {id} annotations` (e.g. `consume editorial annotations`)

The three default types (editorial, technical, rules) ship via `annotations_demo`. The `annotations` module itself ships no annotation types — it is blank-slate by design. Sites add, rename, or remove types via config management. The `annotations_type_ui` submodule provides browser CRUD.

### `annotation_target` config entity

Config prefix: `annotations.target.*` — files named e.g. `annotations.target.node__article.yml`.

**Schema:**

- `id` — `{entity_type}__{bundle}`, e.g. `node__article`, `user_role__editor`, `view__frontpage`
- `label` — human-readable label
- `entity_type` — Drupal entity type ID
- `bundle` — bundle machine name (or entity ID for unbundled types like roles/views)
- `fields` — map keyed by field machine name; presence = included in scope

For non-fieldable targets (roles, views, workflows), there is no `fields` key. Annotation text is stored separately in `annotation` content entities — never in this config entity.

### `Annotation` content entity

Entity type: `annotation` (class `Annotation`, tables `annotation` / `annotation_field_data` etc.)

Stores all annotation text — target/field-level and site-wide. **Never touched by config sync; survives deploys; editable on production.**

**Fields:**

- `id` — serial integer PK
- `uuid` — required for content export/import
- `target_id` — string; `annotation_target` machine name.
- `field_name` — string; field machine name. Empty string `''` = bundle-level annotation.
- `type_id` — string; `AnnotationType` machine name (also the bundle key).
- `value` — `string_long` (plain text, no format). **Why not `body`/`text_long`:** switching to a formatted text field adds a `format` column per row, a text-format selector in every annotation form (requires per-bundle form display config to suppress), and a `check_markup()` call on render (cheap for `plain_text`, cached). More significantly, Drupal convention makes `body`-style fields deletable via the field UI — deletion would silently break `AnnotationStorageService` and every renderer; this is blockable via `hook_field_config_delete` but is extra work. The upside is real: WYSIWYG/markdown per annotation type, and renderers stop hand-rolling `Html::escape()`/`nl2br()`. Revisit if rich text becomes a genuine requirement.
- `changed` — timestamp (auto-updated on save)
- `uid` — entity reference to user (who last edited)

**Sentinel convention:**

- `field_name = ''` → bundle-level (the target's overview annotation). Drupal's field storage treats `''` as NULL (`StringItem::isEmpty()`), so queries for this sentinel must use `IS NULL`.

**Shipping default annotations:** In Drupal 11.3+, export `annotation` entities as YAML using the built-in command and place files in a module's `content/annotation/` directory — no contrib module needed:

```bash
php core/scripts/drupal content:export annotation <id> --with-dependencies
# or Drush 13+:
drush content:export annotation <id>
```

Drupal auto-imports `content/annotation/*.yml` files when the module is enabled. See `annotations_demo` for the reference implementation. The exported YAML format is core's own normalisation — not interchangeable with `default_content` 1.x HAL+JSON files.

---

## Module List

### `annotations` (always required)

Core module. All other Annotations modules depend on it.

**Owns:**

- `annotation_target` config entity (schema, storage, interface)
- `Annotation` content entity (schema, storage) — entity type ID `annotation`
- `AnnotationType` config entity (schema, storage, interface)
- `AnnotationStorageService` (`annotations.annotation_storage`) — central CRUD service for all annotation text
- `AnnotationsHooks` — `hook_entity_type_alter` registers `TargetFieldsForm` as the `fields` form handler
- `administer annotations` permission; dynamic per-type `edit {type} annotations` + `consume {type} annotations` via `AnnotationsPermissions::permissions()`
- Admin menu: "Annotations" top-level entry under Admin → Config
- `Target` plugin framework: `TargetInterface`, `TargetBase`
- Plugins: `NodeTarget`, `TaxonomyTarget`, `UserTarget`, `RoleTarget`, `MediaTarget`, `ParagraphTarget`, `WorkflowTarget`, `ViewTarget`, `MenuTarget`, `GenericTarget`
- `DiscoveryService` — collects all tagged plugins + auto-instantiates `GenericTarget` for any fieldable entity type not claimed by a specific plugin
- Scope management UI: `TargetOverviewForm`, `TargetFieldsForm`, `TargetDeleteConfirmForm`
- Views field plugins: `AnnotationTargetLabelField`, `AnnotationFieldLabelField`, `AnnotationTypeLabelField`

**Plugin system:** Tag a service `annotations.target` to register a plugin. `GenericTarget` auto-fills for any **fieldable** entity type with no dedicated plugin — it does not cover non-fieldable config entities. No changes to `annotations` or `annotations_scan` needed.

**What it discovers:**

Fieldable content entities — `GenericTarget` catches any not listed:

| Plugin | Entity type |
| --- | --- |
| `NodeTarget` | `node` |
| `TaxonomyTarget` | `taxonomy_term` |
| `UserTarget` | `user` |
| `MediaTarget` | `media` |
| `ParagraphTarget` | `paragraph` |
| `GenericTarget` | any other fieldable entity type |

Non-fieldable config entities — always need a dedicated plugin:

| Plugin | Entity type |
| --- | --- |
| `RoleTarget` | `user_role` (excluding anonymous, authenticated) |
| `ViewTarget` | `view` |
| `MenuTarget` | `menu` |
| `WorkflowTarget` | `workflow` |

**Scope config UI:** Accordion page at `/admin/config/annotations/targets`. Entity types as accordion sections. Each row is one bundle/target with a checkbox. On create, all available editorial fields are pre-populated. Selected rows with configurable fields show a "Configure" link + coverage summary. Non-fieldable rows show "N/A". Deselecting a target with annotation data routes through a confirmation form.

**Planned setting — views admin-path filter:** Add a boolean setting (stored in `annotations.settings`) — "Expose only views with admin paths as targets". When enabled, `ViewTarget` only surfaces views whose base path starts with `/admin` (or whose route has `_admin_route: true`) as annotatable targets on the scope page. Rationale: annotations on front-end views (news listings, search results) are rarely useful and clutter the target list. Admin views (content overviews, user lists, media library) are where editors need guidance. The setting defaults to `true`; sites with genuinely useful front-end view annotations can disable it. The filter applies at discovery time in `ViewTarget` — views that don't pass the filter simply don't appear as rows on the targets page and cannot have `annotation_target` config entities created for them. Implement as: read the setting in `ViewTarget::getTargets()` (or equivalent discovery method), check `$view->get('base_path')` against the admin path condition.

---

### Built submodules

Each has its own `CLAUDE.md` with full detail.

| Module | Purpose | CLAUDE.md |
| --- | --- | --- |
| `annotations_scan` | Crawls opted-in targets; provides manual trigger and cron integration | [modules/annotations_scan/CLAUDE.md](modules/annotations_scan/CLAUDE.md) |
| `annotations_ui` | Annotation editing UI; per-target and site-wide forms; individual entity edit with revisions; permission model | [modules/annotations_ui/CLAUDE.md](modules/annotations_ui/CLAUDE.md) |
| `annotations_type_ui` | Browser CRUD for annotation types and site sections; site-building only | [modules/annotations_type_ui/CLAUDE.md](modules/annotations_type_ui/CLAUDE.md) |
| `annotations_coverage` | Coverage tracking; `CoverageService` public API; status rollup (complete/partial/empty); report UI | [modules/annotations_coverage/CLAUDE.md](modules/annotations_coverage/CLAUDE.md) |
| `annotations_context` | `ContextAssembler` payload API; markdown + HTML renderers; preview/export UI | [modules/annotations_context/CLAUDE.md](modules/annotations_context/CLAUDE.md) |
| `annotations_ai_context` | AI chat integration via `ai` module suite; `GetSiteContext` function call plugin; `AnnotationsChatBlock` | [modules/annotations_ai_context/CLAUDE.md](modules/annotations_ai_context/CLAUDE.md) |
| `annotations_overlay` | In-context annotation overlays on entity edit/add forms; field-level "?" triggers; modal + inline modes; toolbar site-wide docs button | [modules/annotations_overlay/CLAUDE.md](modules/annotations_overlay/CLAUDE.md) |
| `annotations_workflows` | Ships the default annotations content moderation workflow | [modules/annotations_workflows/CLAUDE.md](modules/annotations_workflows/CLAUDE.md) |
| `annotations_demo` | Editorial/technical/rules annotation types, form displays, a sample node target, and starter annotation values. Install for dev/eval; omit for blank-slate production. | — |

---

### Unbuilt modules

#### `annotations_delta` — not started

Change detection for developers. Drush command comparing current scanner output against the last stored snapshot. Outputs a structured diff (new/removed fields, changed types, new bundles). Designed to run as a pre-commit hook with an optional `--strict` flag. Depends on `annotations_scan` snapshot storage being built first (currently parked). Will use `annotations:scan` Drush commands.

---

## Key Design Decisions

- **Single config entity for scope:** `annotation_target` unifies what-to-scan and what-to-annotate. No separate scan scope entity.
- **Fields map = inclusion list:** Presence in the `fields` map = included. No separate `excluded_fields` list.
- **Split storage model:** Scope config in Drupal config (deployed via `drush cex`/`drush cim`). Annotation text in `annotation` content entity (never touched by config sync). Different lifecycles, different owners (developer vs editor), must not share storage.
- **Plugin system in `annotations`:** `Target` plugins live in `annotations`, not `annotations_scan`. `GenericTarget` auto-fills for unclaimed **fieldable** types only — non-fieldable config entities always need a dedicated plugin.
- **Annotation types as config entities:** Sites rename, remove, or add types via config management. The type definition is the identity contract; behavioural flags (`affects_coverage`, `in_ai_context`) are owned by the submodules that use them as third-party settings.
- **`annotations_context` is the non-AI output path:** AI integration (`annotations_ai_context`) is a second consumer of the same assembler payload. Sites that never use AI get full value via `annotations_context` export.
- **Permission model:** `edit {type} annotations` gates write access per type (generated dynamically). `consume {type} annotations` gates per-role context output visibility. Static: `administer annotations` (restrict access), `edit any annotation` (restrict access), `access annotation overview`, `view annotations context`, `view annotation revisions` (view revision history; revert/delete still require `edit any annotation`), `administer annotation types` (`annotations_type_ui` CRUD routes).
- **No multivalue annotation types:** All values are plain strings. The `rules` type uses markdown in a textarea rather than discrete stored entries.

---

## Current Status

### `annotations` — complete

- [x] All config/content entities, storage service, permissions, plugin framework
- [x] Plugins: `NodeTarget`, `TaxonomyTarget`, `UserTarget`, `RoleTarget`, `MediaTarget`, `ParagraphTarget`, `WorkflowTarget`, `ViewTarget`, `MenuTarget`, `GenericTarget`
- [x] `DiscoveryService`
- [x] `TargetOverviewForm`, `TargetFieldsForm`, `TargetDeleteConfirmForm`
- [x] Views field plugins: `AnnotationTargetLabelField`, `AnnotationFieldLabelField`, `AnnotationTypeLabelField`
- [x] `AnnotationsHooks`, `AnnotationsPermissions`

### Built submodules — see individual CLAUDE.md files

- [`annotations_scan`](modules/annotations_scan/CLAUDE.md) — complete (snapshot storage + Drush commands parked)
- [`annotations_ui`](modules/annotations_ui/CLAUDE.md) — complete
- [`annotations_type_ui`](modules/annotations_type_ui/CLAUDE.md) — complete
- [`annotations_coverage`](modules/annotations_coverage/CLAUDE.md) — complete (cron caching deferred)
- [`annotations_context`](modules/annotations_context/CLAUDE.md) — largely complete (`drush annotations:export` parked)
- [`annotations_ai_context`](modules/annotations_ai_context/CLAUDE.md) — largely complete
- [`annotations_overlay`](modules/annotations_overlay/CLAUDE.md) — largely complete (view-page display and per-field display mode override deferred)
- [`annotations_workflows`](modules/annotations_workflows/CLAUDE.md) — complete; ships default workflow config; no custom form altering
- `annotations_demo` — complete; annotation types + form displays + demo hook_install content; YAML content export migration deferred (see install file)

### `annotations_delta`
