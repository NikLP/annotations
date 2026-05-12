# Annotations Export

Submodule of [Annotations](../../README.md). Exports assembled annotation context to a markdown file or an [Obsidian](https://obsidian.md/) vault via Drush. No web UI — intended for documentation pipelines, AI context preparation, and offline knowledge-base import.

---

## Requirements

- `annotations` (core Annotations module)
- `annotations_context` (context assembly and markdown rendering)

---

## Installation

```bash
ddev drush en annotations_export
```

---

## Drush command

### `annotations:export` (alias `ann:ex`)

Assembles annotation context and writes it as markdown or an Obsidian vault.

```bash
drush ann:ex                                              # all targets → stdout (markdown)
drush ann:ex --output=context.md                          # write to file
drush ann:ex --format=obsidian --output=/tmp/vault        # Obsidian vault (one file per target)
drush ann:ex --target=node__article                       # single target
drush ann:ex --type=node                                  # all targets of an entity type
drush ann:ex --types=editorial,rules                      # filter by annotation type IDs
drush ann:ex --ref-depth=1                                # follow entity-reference fields one hop
drush ann:ex --field-meta                                 # include field type/cardinality/description
drush ann:ex --strip-headings                             # remove # markers for plain-text terminal output
```

#### Options

| Option | Default | Description |
| --- | --- | --- |
| `--format` | `markdown` | Output format: `markdown` or `obsidian`. |
| `--output` | stdout | Destination path. File path for `markdown`; directory path for `obsidian`. Required for `obsidian`. |
| `--target` | — | Limit to a single `annotation_target` ID (e.g. `node__article`). |
| `--type` | — | Limit to all targets of a given entity type (e.g. `node`). |
| `--types` | — | Comma-separated annotation type IDs to include (e.g. `editorial,rules`). |
| `--ref-depth` | `0` | Entity-reference traversal depth (0–2). Follows ER fields into referenced targets. |
| `--field-meta` | off | Include field type, cardinality, and help-text description alongside annotations. |
| `--strip-headings` | off | Strip `#` heading markers — useful for piping to plain-text tools. |

All options are optional and combine freely.

---

## Formats

### `markdown`

Renders the full context payload via `ContextRenderer` to a single UTF-8 markdown string. When `--output` is omitted the result goes to stdout, preceded by a summary line (target count, ref depth, generated timestamp). When `--output` is set the file is written directly.

```bash
drush ann:ex --target=node__article --ref-depth=1 --output=article-context.md
```

### `obsidian`

Generates one `.md` file per annotation target in the output directory. Each file includes:

- **YAML frontmatter** — `target`, `entity_type`, `bundle`, `aliases`, and auto-generated tags (`annotated`, `{n}-fields`)
- **Body** — `# {label}` heading, bundle-level annotation text, `## {field}` sections with annotations beneath each
- **Relationships** — `## Relationships` section with `[[wikilinks]]` to related targets when `--ref-depth` is greater than 0

The wikilinks use Obsidian's double-bracket syntax, so related targets resolve to sibling files in the vault automatically.

```bash
drush ann:ex --format=obsidian --output=/path/to/vault --ref-depth=1
# → /path/to/vault/node__article.md, node__page.md, media__image.md, ...
```

Import the output directory into Obsidian as a vault (or drop it into an existing vault folder) to get a navigable knowledge graph of your site's content architecture.

---

## Keeping a vault up to date

Neither of these approaches is implemented yet. They are noted here as considered options if incremental vault updates become useful.

### Diff-mode export

A `--diff` flag could be added to `obsidian` format. `ObsidianVaultWriter` would generate the content for each target, compare it against the existing `.md` file on disk, and only write the file if the content has changed. Orphaned files (targets that no longer exist in scope) would be deleted. Because the writer produces deterministic output, a byte-for-byte or hash comparison would be sufficient.

With diff-mode you could schedule a cron job that keeps an existing vault current without rewriting every file on each run:

```bash
# cron: run every few minutes
drush ann:ex --format=obsidian --output=/path/to/vault --diff
```

### Hook-based live sync

For a more immediate update cycle, a live-sync mode could hook into `annotation` entity save and delete events. When an annotation is saved or deleted, Drupal would re-export just the single affected target file to a pre-configured vault path stored in Drupal state. A new `ann:vault:path /path/to/vault` command would set the path, enabling automatic vault updates within the same request cycle without a cron schedule.

The tradeoff: the vault must be on a filesystem the web process can write to. A vault on a developer's local machine (e.g. synced via a network share or Git) would not update automatically unless the export path points to a location accessible from the server.
