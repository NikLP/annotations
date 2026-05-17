# Annotations LocalGov Demo Recipe

Bolt-on demo recipe for [LocalGov Drupal](https://localgovdrupal.org/) sites. Wires annotation targets and starter annotations onto existing LocalGov content types and paragraph types — no new content types or field storages are created.

## What you get

**Annotation targets**

| Target | Bundle | Fields |
|---|---|---|
| `node__localgov_event` | Event | title, body, localgov_event_date, localgov_event_location, localgov_event_categories, localgov_event_image, localgov_event_price, localgov_event_call_to_action, localgov_event_locality |
| `node__localgov_subsites_page` | Subsite page | title, localgov_subsites_summary, localgov_subsites_parent, localgov_subsites_banner, localgov_subsites_topic, localgov_subsites_content |
| `paragraph__localgov_accordion` | Accordion | localgov_title, localgov_heading_level, localgov_paragraphs, localgov_display_show_hide_all, localgov_allow_multiple_open |
| `paragraph__localgov_banner_primary` | Banner primary | localgov_title, localgov_subsites_banner_text, localgov_image, localgov_url, localgov_subsites_banner_logo |

**Annotation types:** Editorial, Technical, Rules (from `annotations_demo_types` dependency)

**Starter annotations:** 44 — covering all fields across all four targets

## Requirements

- Drupal 11 / PHP 8.3+
- [LocalGov Drupal](https://localgovdrupal.org/) — the `localgov_events` and `localgov_subsites` feature modules for the targeted bundles to exist

## Installation

```bash
drush recipe web/modules/custom/annotations/recipes/annotations_demo_lgd
drush cr
```

## Where to look

After applying the recipe:

- **Annotation targets:** Admin → Config → Annotations → Targets
- **Annotations UI:** open any Event or Subsite page node for editing — field-level `?` triggers appear on each annotated field (requires `annotations_overlay`)

## Teardown

Recipes are one-way. Delete the four annotation targets and the imported annotation entities to remove the demo data.

## See also

- [Root module README](../../README.md) — full suite overview
- [annotations_demo_types recipe](../annotations_demo_types/) — shared annotation types dependency
- [annotations_demo recipe](../annotations_demo/) — standalone demo for fresh installs
