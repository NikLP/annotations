# CLAUDE.md — Annotations

Drupal module suite: reads site structure (content types, fields, roles, workflows) and exposes it via a structured annotation system driving in-context help overlays, documentation exports, and AI context. Audience: Drupal agencies for client/staff onboarding and AI context scoping.

## Repository Layout

```text
annotations/                ← root module (always required)
├── modules/
│   ├── annotations_ui/
│   ├── annotations_type_ui/
│   ├── annotations_audit/
│   ├── annotations_context/
│   ├── annotations_ai_context/
│   ├── annotations_overlay/
│   ├── annotations_workflows/
│   ├── annotations_webform/
│   ├── annotations_profile/
│   ├── annotations_explorer/
│   └── annotations_export/
└── recipes/
    ├── annotations_demo_types/     ← shared base: editorial/technical/rules annotation types; dependency of other demo recipes
    ├── annotations_demo_lgd/       ← bolt-on recipe targeting LocalGov Drupal content types
    ├── annotations_demo_umami/     ← bolt-on recipe for Umami demo profile; adds Cookbook type + 4 targets
    └── annotations_demo_webform/   ← bolt-on recipe: onboarding webform with per-field overlay triggers
```

Each submodule should have its own `CLAUDE.md` and `README.md`. These files cover the root module, cross-cutting conventions, the data model, and design decisions.

---

## Caching — cross-cutting requirement

- Every new controller page reading annotation data **must declare `#cache`** at build time: tags (`annotation_list`, `annotation_target_list`, `annotation_type_list`) and contexts (`languages:language_interface`, `user.permissions`, plus any query-arg/route contexts). Always include `languages:language_interface`. Guard `languages:language_content` with `$this->languageManager()->isMultilingual()` — it throws `RuntimeException` when the `language_content` type is not configured (i.e. content language negotiation is absent).
- `hook_entity_view_alter` must merge cache metadata via `CacheableMetadata::createFromRenderArray($build)->addCacheTags([...])->addCacheContexts([...])->applyTo($build)`. Never replace `$build['#cache']` directly.
- `EntityStorageBase` invalidates `{entity_type}_list` tags on every save/delete — no manual `Cache::invalidateTags()` calls needed.
- Extension point providers must contribute `CacheableMetadata` alongside their payload slice.

---

## Conventions

- **PHP:** `declare(strict_types=1)` everywhere. PHP 8.3+, Drupal 11.
- **Hooks:** All hook implementations in `src/Hook/{ModuleName}Hooks.php` using `#[Hook('hook_name')]`. `.module` files are empty stubs. Hook classes are services with constructor DI — no `\Drupal::service()` calls inside hook methods.
- **Render arrays:** `#type => 'html_tag'` for simple wrappers; `Markup::create()` with `Html::escape()` for complex HTML. Never string-concatenate variable content into `#markup`.
- **Icons:** `<span aria-hidden="true">&#x2714;</span><span class="visually-hidden">Label</span>`.
- **`DraggableListBuilder` cells:** `label` key must return a plain string (the builder wraps it in `#markup`). All other non-form cells must use only `#`-prefixed keys — `Element::children()` throws on non-`#` keys with non-array values.
- **Accessibility:** WCAG 2.1 AA. Never use a bare symbol as sole cell/label content. All inputs need visible or visually-hidden labels. Status conveyed via text, not colour alone. Use `$this->t()` for all user-facing strings.

---

## Core Data Model

### `AnnotationType` config entity — `annotations.annotation_type.*`

- `id`, `label`, `description`, `weight`
- `getPermission()` → `edit {id} annotations`; `getConsumePermission()` → `consume {id} annotations`
- Additional behaviors (`affects_coverage`, `in_ai_context`) are third-party settings owned by the submodule that uses them.
- Ships no default types — the `annotations_demo_types` recipe ships editorial/technical/rules.

### `annotation_target` config entity — `annotations.target.*`

- `id` = `{entity_type}__{bundle}` (e.g. `node__article`, `user_role__editor`)
- `entity_type`, `bundle`, `label`
- `fields` — map keyed by field machine name; presence = included in scope. Non-fieldable targets (roles, views) have no `fields` key.

### `annotation` content entity

Tables: `annotation` / `annotation_field_data`. **Never touched by config sync.**

Fields: `id`, `uuid`, `target_id` (string, `annotation_target` machine name), `field_name` (string; `''` = bundle-level), `type_id` (bundle key), `value` (`string_long`, plain text — **stored raw, escape on output**), `created` (set once on first save, never updated), `changed`, `uid` (original author — set on create via `defaultValueCallback`, never overwritten on subsequent saves; `revision_uid` tracks per-revision editor).

**Sentinel:** `field_name = ''` is bundle-level. Drupal's `StringItem::isEmpty()` treats `''` as NULL, so queries must use `IS NULL`.

**⚠️ Raw storage:** `value` is stored unfiltered. Any code rendering annotations outside the admin UI — including `annotations_context`, `annotations_ai_context`, and any end-user output — **must** escape with `Html::escape()` wrapped in `Markup::create()`, or use `#plain_text`.

---

## `annotations` root module — owns

- All three entity types above (schema, storage, interfaces)
- `AnnotationStorageService` (`annotations.annotation_storage`) — central CRUD
- `AnnotationsPermissions` — dynamic `edit {type}` + `consume {type}` permissions
- `AnnotationDiscoveryService` — collects tagged `annotations.target` plugins; auto-instantiates `GenericTarget` for unclaimed fieldable types
- Target plugins: `NodeTarget`, `TaxonomyTarget`, `UserTarget`, `MediaTarget`, `ParagraphTarget`, `GenericTarget` (fieldable); `RoleTarget`, `ViewTarget`, `MenuTarget`, `WorkflowTarget` (non-fieldable — always need a dedicated plugin)
- Scope management UI: `TargetOverviewForm`, `TargetFieldsForm`, `TargetDeleteConfirmForm`
- Views field plugins: `AnnotationTargetLabelField`, `AnnotationFieldLabelField`, `AnnotationTypeLabelField`
- Admin menu: Annotations top-level entry under Admin → Config
- `AnnotationsCommands` (`src/Drush/Commands/`) — inspection commands: `annotations:targets`, `annotations:types`, `annotations:show`, `annotations:stats` (aliases `ann:targets`, `ann:types`, `ann:show`, `ann:stats`)
- Config action plugins (`src/Plugin/ConfigAction/`) — Drupal recipe config actions for wiring up annotation scope declaratively:
  - `enableTargetType` — appends an entity type to `annotations.target_types.enabled_target_types`; idempotent
  - `enableTargetField` — appends fields to an `annotation_target` entity's fields list; creates the target from the bundle's existing Drupal label if it does not yet exist; idempotent

---

## Submodules

| Module | Purpose |
| --- | --- |
| `annotations_audit` | Site structure scanning (cron + on-demand) and annotation coverage reporting; `CoverageService` API; `--diff`/`--strict` Drush flags |
| `annotations_ui` | Annotation editing UI; per-target and site-wide forms; revisions; permission model |
| `annotations_type_ui` | Browser CRUD for annotation types |
| `annotations_context` | `ContextAssembler` payload API; markdown + HTML renderers; preview/export UI |
| `annotations_ai_context` | Bridges `annotations_context` into Context Control Center (CCC / `ai_context` module); injects assembled annotations documentation into agent system prompts via `BuildSystemPromptEvent` |
| `annotations_overlay` | In-context overlays on entity edit/add forms; field-level "?" triggers; modal + inline modes; toolbar button |
| `annotations_workflows` | Ships default content moderation workflow config |
| `annotations_webform` | Webform and WebformSubmission target plugins and overlay field label resolver |
| `annotations_profile` | Injects annotation overlay triggers into Profile fields embedded in user account edit and registration forms; requires the contributed `profile` module |
| `annotations_explorer` | Read-only two-panel browser at `/annotations/explorer`; consume-permission filtered; AJAX target switching |
| `annotations_export` | Drush-only export to markdown or Obsidian vault; no web UI; delegates to `annotations_context` for assembly |

---

## Key Design Decisions

- **Single config entity for scope:** `annotation_target` unifies what-to-scan and what-to-annotate.
- **Fields map = inclusion list:** Presence in `fields` = target is included.
- **Split storage:** Scope in Drupal config (cex/cim); annotation text in `annotation` content entity.
- **Plugin system in `annotations`:** Target plugins live in root module. `GenericTarget` only covers fieldable types — non-fieldable always need a dedicated plugin.
- **Annotation types as config entities:** Behaviors (e.g. `affects_coverage`, `in_ai_context`) are third-party settings owned by the submodule that consumes them.
- **`annotations_context` is the payload API:** `annotations_ai_context` is the AI Context consumer.
- **Permissions:** `edit {type} annotations` (write + create per type), `delete {type} annotations` (delete per type), `consume {type} annotations` (context output visibility per role). Static: `administer annotations`, `administer annotation targets`, `edit any annotation`, `delete any annotation`, `access annotation collection`, `view annotation revisions`, `view annotations context`, `administer annotation types`. Entity-level access is enforced by `AnnotationAccessControlHandler`; `hook_entity_access` in `annotations_ui` handles revision-only operations.
