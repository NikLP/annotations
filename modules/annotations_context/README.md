# Annotations Context

Submodule of [Annotations](../../README.md). Assembles annotation data into a structured PHP array payload and ships two renderers (markdown, HTML render arrays) plus an admin preview UI.

The payload is the stable API surface: format-agnostic, permission-aware, and extensible via alter hook. `annotations_ai_context` consumes it to build LLM context; the preview page and markdown export are human-readable views of the same data.

---

## Requirements

- `annotations` (core Annotations module)

---

## Installation

```bash
ddev drush en annotations_context
```

---

## What it provides

- **Context preview** at `/admin/config/annotations/context` — live HTML preview of assembled context, filterable by role, target, reference depth, and field metadata.
- **Role simulation** — preview context as any Drupal role sees it (respects `consume {type} annotations` permissions).
- **Markdown export** at `/admin/config/annotations/context/export` — downloads context as a `.md` file.
- **JSON API endpoint** at `/api/annotations/{target_id}` — returns the assembled payload for a single target as JSON, for headless consumers (Canvas, React, Mercury).

---

## Permissions

| Permission | Notes |
| --- | --- |
| `view annotations context` | Access the preview page and markdown export. Not `restrict access: true` — can be granted to non-admin roles (e.g. project managers reviewing documentation). |

`administer annotations` also grants access (OR logic in routing).

---

## Developer API

### ContextAssembler (`annotations_context.assembler`)

The central service. Builds a structured PHP array from annotation data. All other features in this module and its consumers derive from this payload.

```php
use Drupal\annotations_context\ContextAssembler;

$payload = $assembler->assemble();                                 // all targets, all types
$payload = $assembler->assemble(['entity_type' => 'node']);        // one entity type
$payload = $assembler->assemble(['target_id' => 'node__article']); // one target
$payload = $assembler->assemble(['types' => ['editorial']]);       // explicit type filter
$payload = $assembler->assemble(['ref_depth' => 1]);               // follow ER fields one hop
$payload = $assembler->assemble(['account' => $currentUser]);      // filter by user permissions
$payload = $assembler->assemble(['role' => 'editor']);             // simulate a role
$payload = $assembler->assemble(['inc_refs' => TRUE]); // add reverse ER sources
```

All options are optional and combine freely.

#### Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `entity_type` | `string\|null` | `null` | Limit to targets of this entity type. |
| `target_id` | `string\|null` | `null` | Limit to a single target by machine name (e.g. `node__article`). |
| `types` | `string[]\|null` | `null` (all) | Explicit list of annotation type IDs to include. |
| `ref_depth` | `int` | `0` | Entity-reference traversal depth. `0` = no traversal; `1`–`2` follows linked targets. |
| `role` | `string\|null` | `null` | Simulate context as this Drupal role — only types that role can `consume` are included. Takes precedence over `account`. |
| `account` | `AccountInterface\|null` | `null` | Filter to types the given account can view via its combined role permissions. Accounts with `administer annotations` bypass filtering. |
| `inc_meta` | `bool` | `false` | Add `type`, `cardinality`, and `description` to each field entry. Useful for AI context; noisy for human review. |
| `inc_refs` | `bool` | `false` | Add an `incoming_refs` key to each target listing annotation targets that reference it via entity-reference fields. Flat only — no recursive reverse traversal. |

#### Role and account filtering

Use `role` to simulate what a role sees without impersonating a user — useful for previews and testing:

```php
$payload = $assembler->assemble(['role' => 'content_editor']);
```

Use `account` for real current-user context in live features. Accounts with `administer annotations` bypass all type filtering:

```php
$payload = $assembler->assemble(['account' => $this->currentUser]);
```

`role` takes precedence when both are supplied.

#### Payload structure

```php
[
  'groups' => [
    'node' => [
      'entity_type' => 'node',
      'label'       => 'Content types',
      'targets'     => [
        'node__article' => [
          'id'          => 'node__article',
          'label'       => 'Article',
          'entity_type' => 'node',
          'bundle'      => 'article',
          'annotations' => [
            'editorial' => ['label' => 'Editorial', 'value' => '...'],
            'rules'     => ['label' => 'Rules',     'value' => '...'],
          ],
          'fields' => [
            'body' => [
              'label'       => 'Body',
              'annotations' => ['editorial' => ['label' => 'Editorial', 'value' => '...']],
              // 'meta' key present when inc_meta = TRUE:
              'meta' => ['type' => 'text_long', 'cardinality' => 'single value', 'description' => '...'],
            ],
          ],
          'references'    => [...], // only present when ref_depth > 0
          'incoming_refs' => [      // only present when inc_refs = TRUE
            'media__image' => [
              'label'      => 'Image',
              'via_fields' => ['field_featured_image'],
            ],
          ],
        ],
      ],
    ],
  ],
  'meta' => [
    'generated_at'        => '2026-04-20T12:00:00+00:00',
    'ref_depth'           => 0,
    'inc_refs'            => FALSE,
    'target_count'        => 12,
  ],
]
```

Only non-empty annotation values are included. Targets with no matching annotations are omitted when type-filtering is active.

**HTML normalisation:** All string values in the payload (annotation `value`, configurable extra fields, and field help text) are passed through `flattenHtml()` before being added to the payload. This strips markup, preserves links as `text (url)`, decodes HTML entities, and collapses whitespace. Annotation storage is plain text but values may contain markup if content was pasted from a rich-text source; normalisation happens at read time so all consumers — HTML preview, markdown, JSON API, MCP — receive clean text.

#### Cache metadata from alter implementations

If your code produces a cacheable page from an assembled payload, merge alter-contributed cache requirements:

```php
$payload = $assembler->assemble($options);
$assembler->getLastCacheableMetadata()->applyTo($build);
```

`ContextPreviewController` does this automatically.

---

### ContextRenderer (`annotations_context.renderer`)

Renders the payload to a UTF-8 markdown string. Stateless — no Drupal services involved. Safe for file download; values are not HTML-escaped (markdown is plain text).

```php
$markdown = $renderer->render($payload);
```

---

### ContextHtmlRenderer (`annotations_context.html_renderer`)

Renders the payload to a Drupal render array. All annotation values are escaped via `Html::escape()`. Uses `details/summary` collapsible cards.

```php
$build = $htmlRenderer->render($payload);
// Return directly from a controller.
```

---

### Writing a custom renderer

A renderer just consumes the plain PHP array — no base class required.

```php
class MyJsonRenderer {
  public function render(array $payload): string {
    $output = [];
    foreach ($payload['groups'] as $group) {
      foreach ($group['targets'] as $target) {
        $output[] = [
          'id'          => $target['id'],
          'annotations' => $target['annotations'],
          'fields'      => $target['fields'],
        ];
      }
    }
    return json_encode($output, JSON_PRETTY_PRINT);
  }
}
```

**Security:** Always escape annotation values when producing HTML output. Although `ContextAssembler` strips HTML markup from values at read time, the resulting plain text must still be escaped (via `Html::escape()` or `#plain_text`) before insertion into HTML.

---

## Entity reference traversal

Set `ref_depth` to follow entity reference fields into referenced targets:

- `0` (default) — no traversal; only the directly annotated target
- `1` — one hop (e.g. Article → referenced Media)
- `2` — two hops (recommended maximum; depth 3+ rarely adds useful signal and can produce very large payloads)

Each referenced target is assembled in full and nested under `references` → field name → target ID. Cycle detection prevents the same target appearing twice in a payload.

### Incoming references

Set `inc_refs => TRUE` (or `?inc_refs=1` on HTTP endpoints) to surface **reverse** relationships — which annotation targets reference a given target, rather than which targets it references. Useful for leaf entities: when assembling context for `media__image`, you can see that `node__article` and `node__landing_page` both reference it.

```php
$payload = $assembler->assemble([
  'target_id' => 'media__image',
  'inc_refs'  => TRUE,
]);
```

Each target entry gains an `incoming_refs` key keyed by source target ID:

```php
'incoming_refs' => [
  'node__article' => [
    'label'      => 'Article',
    'via_fields' => ['field_featured_image'],
  ],
  'node__landing_page' => [
    'label'      => 'Landing page',
    'via_fields' => ['field_hero_media', 'field_gallery'],
  ],
],
```

`via_fields` is always an array — a source target with two ER fields targeting the same bundle will list both. Only ER fields in the source target's annotation scope (`getFields()`) are considered, matching the forward traversal behaviour. Reverse traversal is flat — incoming sources are not themselves expanded.

---

## Extending the payload

`ContextAssembler::assemble()` invokes `hook_annotations_context_alter()` at the end of every assembly call. Use it to append, remove, or reshape payload sections.

```php
use Drupal\Core\Cache\CacheableMetadata;

function mymodule_annotations_context_alter(array &$payload, array $options, CacheableMetadata &$cacheableMetadata): void {
  // Append a section. Any top-level key not named 'groups' or 'meta' is yours.
  $payload['my_section'] = [
    'setting_a' => 'value',
    'setting_b' => TRUE,
  ];

  // Contribute cache requirements so callers invalidate correctly.
  $cacheableMetadata->addCacheTags(['mymodule_data_list']);
  $cacheableMetadata->addCacheContexts(['user.roles']);
}
```

The `$options` argument is the same array passed to `assemble()` — use it to conditionally modify the payload based on filters the caller applied (e.g. only inject extra data when a specific `entity_type` is requested).

Callers that produce cacheable output must merge the metadata:

```php
$payload = $assembler->assemble($options);
$assembler->getLastCacheableMetadata()->applyTo($build);
```

---

## Drush export

`drush annotations:context:export` (alias `ann:ctx`) assembles the full context payload and prints it as markdown.

```bash
drush ann:ctx                                          # all targets
drush ann:ctx --target=node__article                   # one target
drush ann:ctx --type=node --ref-depth=1                # all node targets, follow ER one hop
drush ann:ctx --types=editorial,rules                  # specific annotation types only
drush ann:ctx --inc-meta                               # include field type/cardinality/description
drush ann:ctx --strip-headings                         # plain-text output without # markers
drush ann:ctx > context.md                             # export to file
```

| Option | Default | Description |
| --- | --- | --- |
| `--target` | — | Limit to a single `annotation_target` ID (e.g. `node__article`). |
| `--type` | — | Limit to all targets of a given entity type (e.g. `node`). |
| `--types` | — | Comma-separated annotation type IDs to include. |
| `--ref-depth` | `0` | Entity-reference traversal depth (0–2). |
| `--inc-meta` | off | Include field type, cardinality, and description. |
| `--inc-refs` | off | Add incoming references to each target — reverse ER sources. |
| `--strip-headings` | off | Remove `#` heading markers for plain-text terminal output. |

All options are optional and combine freely. Outputs a summary line (target count, ref depth, generated timestamp) before the markdown block.

---

## JSON API endpoint

`GET /api/annotations/{target_id}` returns the assembled context payload as JSON. Designed for headless consumers that need annotation data without pulling in the AI module.

```code
GET /api/annotations/node__article
GET /api/annotations/node__article?ref_depth=1
GET /api/annotations/node__article?inc_meta=1
GET /api/annotations/media__image?inc_refs=1
```

**Query parameters** (all optional):

| Parameter | Values | Default | Description |
| --- | --- | --- | --- |
| `ref_depth` | `0`, `1`, `2` | `0` | Entity reference traversal depth. |
| `inc_meta` | `1` | off | Include field type, cardinality, and description. |
| `inc_refs` | `1` | off | Add `incoming_refs` to each target — reverse ER sources. |

**Responses:**

- **200** — full assembler payload (`groups` + `meta`), same structure as `ContextAssembler::assemble()`.
- **404** — `{"error": "Annotation target not found."}` when no `annotation_target` config entity exists for the given ID.

**Access:** requires `view annotations context` or `administer annotations`. Type visibility is filtered by the authenticated user's `consume {type} annotations` permissions — accounts with `administer annotations` see all types. Does not support `role` simulation; that is a preview-page concern.

**Caching:** returns a `CacheableJsonResponse` tagged with `annotation_list`, `annotation_target_list`, and `annotation_type_list`. Cache contexts: `user.permissions`, `url.query_args`, `languages:language_interface` (plus `languages:content` on multilingual sites). Any `hook_annotations_context_alter()` implementations that contribute cache metadata are folded in automatically.

**Response shape:**

```json
{
  "groups": {
    "node": {
      "entity_type": "node",
      "label": "Content types",
      "targets": {
        "node__article": {
          "id": "node__article",
          "label": "Article",
          "entity_type": "node",
          "bundle": "article",
          "annotations": {
            "editorial": { "label": "Editorial", "value": "..." }
          },
          "fields": {
            "body": {
              "label": "Body",
              "annotations": {
                "editorial": { "label": "Editorial", "value": "..." }
              }
            }
          }
        }
      }
    }
  },
  "meta": {
    "generated_at": "2026-04-21T12:00:00+01:00",
    "ref_depth": 0,
    "inc_refs": false,
    "target_count": 1
  }
}
```

The response is the raw assembler structure. Consumers that need a flatter shape (`{field_name: {type_label: value}}`) should flatten client-side — no `?format=flat` option is provided.

---

## MCP endpoint

`POST /api/annotations/mcp` implements the [MCP Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (2025-03-26 spec). Each `annotation_target` is exposed as an MCP resource addressed by `annotation://target/{target_id}`.

**Supported methods:**

| Method | Description |
| --- | --- |
| `initialize` | Capability handshake; negotiates protocol version (`2025-03-26` or `2024-11-05`). |
| `resources/list` | Returns all annotation targets as MCP resources. |
| `resources/read` | Returns assembled context for one target as markdown (`text/plain`). |
| `ping` | Keep-alive; returns empty result object. |
| `notifications/*` | Acknowledged with `202 No Content`; no response body. |

**Query parameters on `resources/read` URIs:**

| Parameter | Values | Default | Description |
| --- | --- | --- | --- |
| `ref_depth` | `0`, `1`, `2` | `0` | Entity reference traversal depth. |
| `inc_meta` | `1` | off | Include field type, cardinality, and description. |
| `inc_refs` | `1` | off | Add `incoming_refs` to each target — reverse ER sources. |

Example URI: `annotation://target/node__article?ref_depth=1&inc_meta=1`

**Type filtering:** `resources/read` only returns annotation types where the `annotations_context.in_ai_context` third-party setting is `TRUE`. Types must be opted in via the annotation type edit form — default is off. If no types are opted in the response content will be empty.

### Auth

Two mechanisms are supported — use whichever fits the client:

**Bearer token (headless clients):** Generate a key at `/admin/config/annotations/context/mcp`. By default the key is stored in `annotations_context.settings` — to keep it out of config exports and the database, use the [Key module](https://www.drupal.org/project/key) (`drupal/key`) file provider with a config override:

1. Write the token to a file outside the webroot and VCS (e.g. `../keys/annotations_mcp.key`).
2. Create a Key entity (Admin → Configuration → System → Keys) using the **File** key provider pointing at that path.
3. Add a config override in `settings.local.php` (gitignored):

   ```php
   $config['annotations_context.settings']['mcp_api_key'] = trim(file_get_contents('../keys/annotations_mcp.key'));
   ```

Drupal reads the override at bootstrap — the key never enters config exports or the DB.

Pass the key as `Authorization: Bearer <key>`. Bearer token holders bypass per-role type filtering and see all opted-in types.

**Session auth:** Any authenticated user with `view annotations context` or `administer annotations` can call the endpoint using a Drupal session cookie. Type visibility is filtered by the user's `consume {type} annotations` permissions.

### Claude Code setup

To make annotation context available to Claude Code during development sessions, add the server to `.claude/settings.local.json` (not `settings.json` — the key must stay out of version control):

```json
{
  "mcpServers": {
    "annotations": {
      "type": "http",
      "url": "https://dotdev.ddev.site/api/annotations/mcp",
      "headers": {
        "Authorization": "Bearer <your-key>"
      }
    }
  }
}
```

Restart Claude Code after saving. The `resources/list` and `resources/read` tools will be available in session, allowing annotation context to be pulled for any target on demand.

### Claude Code skill

A Claude Code skill ships with this module at `.claude/skills/annotations-context/`. It teaches Claude how to interpret annotation payloads, use the MCP endpoint query parameters, and check annotation coverage. To install it in a project:

```bash
cp -r annotations/modules/annotations_context/.claude/skills/annotations-context \
  .claude/skills/
```

The skill is self-contained and works independently of this module's codebase — copy it into any project that has the MCP endpoint configured.
