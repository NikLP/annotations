# Annotations Documents

Generates AI-authored documentation for your annotation targets and stores it in a two-panel browser.

## Requirements

- [Annotations](../../README.md) (root module)
- [Annotations Context](../annotations_context/README.md)
- [Drupal AI](https://www.drupal.org/project/ai) with a configured default chat provider

## Installation

```bash
ddev drush en annotations_docs
```

The module installs an `annotations_document` content type with two fields: `annotations_doc_body` and `annotations_doc_target`. This content type is intentionally excluded from the annotation targets list.

## Usage

1. Navigate to **Content > Annotations > Documents** (`/annotations/documents`)
2. The left panel lists all annotation targets grouped by entity type
3. Targets with existing documents are clickable; targets without show a **Generate** link
4. Click **Generate** to open the confirmation form — click the button to trigger generation; a progress indicator appears while the AI provider is called, then the page redirects to the document on completion
5. Review the draft in the main panel; click **Edit** to refine, then set the node status to **Published**
6. Click **Regenerate** at any time to produce a new draft from the current annotations

## Permissions

| Permission | Purpose |
| --- | --- |
| `access annotation documents` | View the browser and document nodes directly |
| `generate annotation documents` | Trigger AI generation and regeneration |
| `administer annotation documents` | Full administration; implies `access annotation documents` but not `generate annotation documents` — generation must always be explicitly granted because it triggers potentially paid AI API calls |

## Customising the system prompt

The AI system prompt is stored as a config entity at `ai.ai_prompt.annotations_docs__generate__default` and can be edited at **Admin > Config > AI > Prompts**. Changes take effect immediately on the next generation.
