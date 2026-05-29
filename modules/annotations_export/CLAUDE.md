# CLAUDE.md — annotations_export

Submodule of Annotations. See the root [CLAUDE.md](../../CLAUDE.md) for project overview, conventions, coding standards, and data model.

Exports assembled annotation context to markdown files or Obsidian vaults via Drush. No web UI, no routes, no permissions — pure Drush.

## What it owns

- `AnnotationsExportCommands` (`src/Drush/Commands/`) — `annotations:export` (alias `ann:ex`); options: `--format`, `--output`, `--target`, `--type`, `--types`, `--ref-depth`, `--inc-meta`, `--inc-refs`, `--strip-headings`
- `ObsidianVaultWriter` (`annotations_export.obsidian_vault_writer`) — writes one `.md` per target to a directory; generates YAML frontmatter and wikilinks between targets

## Services

| Service ID | Class | Purpose |
| --- | --- | --- |
| `annotations_export.obsidian_vault_writer` | `ObsidianVaultWriter` | Writes one Obsidian-formatted `.md` file per target to an output directory |

The Drush command is registered in `drush.services.yml` and injects `annotations_context.assembler`, `annotations_context.renderer`, and `annotations_export.obsidian_vault_writer`.

## ObsidianVaultWriter

`write(array $payload, string $outputDir): int` — iterates `$payload['groups']` → targets, calls `writeTarget()` per target, returns file count. Throws `RuntimeException` on directory creation failure or write failure.

**Per-file structure:**

1. YAML frontmatter (`---` delimited): `target`, `entity_type`, `bundle`, `aliases` (array with target label), conditional `tags` (`annotated` if annotations/fields exist; `{n}-fields` if fields are present)
2. `# {label}` heading
3. Bundle-level annotation text (if any)
4. `## {field_label}` section per field, with annotation text beneath
5. `## Relationships` section (if `ref_depth > 0`) — one bullet per ER relationship as `[[dest_target_id]] via \`field_name\``; wikilinks resolve to sibling vault files

Frontmatter uses `Yaml::dump($data, 2, 2)` — inline level 2 keeps scalars on one line.

## Possible future features

Neither of these is implemented. They are noted here as considered approaches if incremental vault updates become a requirement.

### Diff-mode export (`--diff` flag)

Would add a `--diff` flag to `ann:ex --format=obsidian`: generate content per target, compare with existing file on disk (byte or hash), only write if changed, delete orphaned files. `ObsidianVaultWriter` produces deterministic output so comparison is trivial. Would make cron-driven vault updates cheap.

### Hook-based live sync

Would hook `annotation` postsave/postdelete to re-export just the affected target file to a vault path stored in Drupal state. A new command (`ann:vault:path /path`) would set the path. Vault would stay current within the request cycle. Requires the vault path to be writable by the web process.

## Design notes

`annotations_context` ships its own `ann:ctx` command for quick stdout inspection. `annotations_export` is the dedicated export layer:

- `--output` writes directly to file or directory rather than relying on shell redirection
- Obsidian vault format requires `ObsidianVaultWriter` — justified as a separate module so sites that never use Obsidian don't carry the dependency
- Stateless: no schema, no DB table, no Drupal state; entirely delegates assembly to `annotations_context.assembler` and markdown rendering to `annotations_context.renderer`
