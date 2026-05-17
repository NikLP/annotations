# CLAUDE.md — annotations_demo_lgd recipe

Bolt-on demo recipe for LocalGov Drupal sites. See [../../CLAUDE.md](../../CLAUDE.md) for project conventions and data model.

## What this recipe does

Ships annotation targets and starter annotations onto four existing LocalGov bundle types. No new field storages or content types are created — the recipe assumes the LocalGov bundles already exist on the site.

Targets:

| ID | Entity type | Bundle | Fields |
|---|---|---|---|
| `node__localgov_event` | node | localgov_event | 9 fields |
| `node__localgov_subsites_page` | node | localgov_subsites_page | 6 fields |
| `paragraph__localgov_accordion` | paragraph | localgov_accordion | 5 fields |
| `paragraph__localgov_banner_primary` | paragraph | localgov_banner_primary | 5 fields |

44 annotation content entities covering all fields across all four targets.

## Recipe structure

```text
annotations_demo_lgd/
  recipe.yml                              ← recipes dependency, install: annotations_overlay
  config/
    annotations.target.node__localgov_event.yml
    annotations.target.node__localgov_subsites_page.yml
    annotations.target.paragraph__localgov_accordion.yml
    annotations.target.paragraph__localgov_banner_primary.yml
  content/
    annotation/*.yml                      ← 44 annotation entities
```

## No config actions

Unlike `annotations_demo`, this recipe does not use `enableTargetType` — the targets are shipped as config YAML with fields listed inline, and Drupal applies them via normal config import. There is nothing to wire up in `annotations.target_types` because the recipe does not expand what entity types are tracked site-wide.

## LocalGov bundle assumptions

The recipe assumes `localgov_event`, `localgov_subsites_page`, `localgov_accordion`, and `localgov_banner_primary` bundles already exist (provided by the `localgov_events` and `localgov_subsites` feature modules). Applying to a site without these bundles will import orphaned annotation targets — Drupal does not validate that annotation targets reference real bundles.
