# ADR-001: Annotations as a Knowledge Graph — Current State and Evolution Path

**Status:** In progress  
**Date:** 2026-04-30  
**Updated:** 2026-05-04

## Implementation status

### Done

- `annotations_export` module — `ann:ex --format=markdown|obsidian`, `ObsidianVaultWriter`, Obsidian diagnostic complete
- Edge annotation storage — `annotation` entity with `target_id = {source}__{field}__{dest}`, `field_name = ''`; zero schema changes
- `ContextAssembler` — folds edge annotations into `references[field][dest]['edge_annotations']` when `ref_depth > 0`
- `ContextRenderer` — renders edge annotations as blockquotes under the `_via field:_` line
- `ObsidianVaultWriter` — renders edge annotations as indented sub-bullets under wikilinks
- `EdgeEnumerator` service (`annotations.edge_enumerator`, root module) — derives outbound edges from in-scope ER fields; used by UI and eventually scan
- `annotations_ui` add page — edge rows injected between Overview and Fields; `annotations_ui.edge.create` route; `createEdgeAnnotationForm()` / `createEdgeAnnotationTitle()`

### Remaining — next thread

**Edge coverage (`annotations_coverage`)**
`CoverageService` does not yet factor in edges. When implemented: `computeEdgeCoverage()` using a two-level hierarchy (field_annotated → edge_annotated); `affects_edge_coverage` third-party setting on `AnnotationType`; new `edge_total`/`edge_annotated` keys in `getScore()`. See `annotations_coverage/CLAUDE.md`.

**Edge discovery in scan (`annotations_scan`)**
`ScanService` does not yet enumerate or diff edges. When implemented: walk in-scope ER fields per target, compute edge IDs, surface newly reachable edges as coverage gaps when field target bundles change. See `annotations_scan/CLAUDE.md`.

**`AnnotationStorageService::getForEdge()` convenience method**
The ADR proposed this thin wrapper. Currently callers use `getEntityMapForTarget($edge_id)` directly. Add if the pattern recurs enough to warrant naming.

---

## Context

The `annotations_context` sub-module includes a `ContextAssembler` service whose primary job is building a structured payload describing the site's content model — targets (entity type + bundle combinations), their fields, and the human-authored annotations attached to each — for consumption by AI tooling, editorial UIs, or other downstream systems.

The question is whether this output constitutes a knowledge graph, what it is missing, and whether it is worth moving toward a true knowledge graph model.

---

## What the ContextAssembler currently produces

The assembled payload has the following graph-like properties:

**Nodes with typed properties**  
Each `AnnotationTarget` (a bundle, or a field within one) appears as a node. Annotations keyed by `AnnotationType` act as typed attributes on those nodes.

**Typed edges (entity-reference traversal)**  
When `ref_depth > 0`, the assembler follows entity-reference fields outward from a target and recursively assembles the referenced targets. The edge label is the field name. Cycle detection via a `$visited` set prevents infinite loops.

**Reverse edges**  
The `include_incoming_refs` option adds a flat reverse index — which other targets reference the current one, and via which fields.

**Structured output**  
The payload is a nested array grouped by entity type, then target, then field, with optional field metadata (type, cardinality, description). It is format-agnostic; the context module serialises it to JSON or markdown depending on the consumer.

---

## What is present vs. what is missing

| Property | Present | Notes |
| --- | --- | --- |
| Nodes with typed attributes | Yes | Annotations keyed by type |
| Named edges (entity-reference) | Yes | Edge label = field name |
| Bidirectional traversal | Partial | Forward is recursive; reverse is flat, one hop only |
| Cycle detection | Yes | `$visited` array in assembler |
| Annotations on edges | Yes | `target_id = {source}__{field}__{dest}`, `field_name = ''`; assembled into `references[field][dest]['edge_annotations']` |
| Typed relationship predicates | No | "references via field X" is structural, not semantic |
| Queryable graph | No | Output is a static snapshot; no post-assembly filtering or traversal |
| Inference / derived facts | No | No rules engine; all content is human-authored |
| External ontology linking | No | No schema.org, RDF, or linked-data alignment |
| First-class edge entities | No | Relationships are inferred from field definitions, not asserted |
| Push / streaming surface | No | Output is pull-only (static snapshot); no webhook or event stream for agent pipelines to subscribe to |

### What this means in practice

The current model tells a consumer *what* is connected and *how* (via which field). It does not say *why* the connection exists or *what the relationship means* in the domain. An AI consumer receiving this context must infer relationship semantics from field names and annotation prose — which works, but is fragile and requires the AI to fill gaps the system could state explicitly.

The pull-only model also means agent pipelines must poll or request a full snapshot on demand. Agentic platform architectures (per Bain, 2025) expect real-time streaming pipelines alongside batch delivery so agents operate on current data, not stale snapshots. The Drupal-native path if push delivery is ever needed: `hook_entity_insert/update/delete` writes annotation change events to a queue; a worker pushes them to whatever the consumer is (webhook, message broker). The entity layer is clean enough to add this without schema changes. Not planned; noted as an integration gap if a live-consuming agent pipeline is introduced.

---

## Path toward a true knowledge graph

### 1. Annotatable edges (highest value, moderate effort)

Add a relationship entity or a new annotation scope that targets the triple `(source_target, field_name, dest_target)` rather than just a node. This allows a human to annotate "node__article → field_tags → taxonomy_term__tag" with a typed annotation: e.g., "this relationship classifies the article for editorial discovery, not for URL routing."

This is the single change that closes the largest gap. It turns the graph from a node-annotated structure into a proper subject-predicate-object triple store where all three elements carry meaning.

**AnnotationType IDs:** No new vocabulary design is required. Existing types (`description`, `editorial_note`, `ai_context`) apply to edge annotations the same way they apply to node annotations — `description` on an edge describes why that edge exists. The edge ID convention in `target_id` is the distinguishing factor, not the type. Edge-specific types (e.g. `relationship_direction`) are an escape hatch for semantics that genuinely only apply to relationships; they should not be designed upfront.

#### Annotation hierarchy for edges

Two levels apply, in ascending specificity:

1. **Field-level annotation** — the existing `Annotation` with `target_id = source_target`, `field_name = field_name` already represents "what this field means in general." This annotation covers all destination bundles reachable via the field unless a more specific edge-level annotation exists. For a field like `field_related_content` that points to multiple bundles, one field-level annotation handles all of them.

2. **Edge-level annotation** — `target_id = {source_target}__{field_name}__{dest_target}`, `field_name = ''`. Overrides the field-level annotation for a specific source→field→dest triple. Required only when the same field has meaningfully different semantics depending on destination bundle.

This means the common case — annotate once at field level, done for all destinations — costs one annotation per ambiguous field. The edge-level override exists but is rarely needed.

#### Relationship UI (annotations_ui)

A new "Relationships" section in the `annotations_ui` module surfaces the edge graph alongside node annotations. Implementation outline:

- **EdgeEnumerator service**: walks all `AnnotationTarget` configs, inspects entity-reference field definitions, enumerates all traversable `(source, field, dest)` triples, computes edge IDs. Output is a flat list of edge descriptors; never stored, always derived.
- **Coverage state per edge**: `field_annotated` (field-level annotation exists), `edge_annotated` (edge-level override exists), `missing` (no annotation at any level).
- **UI display**: edge list grouped by source target showing field name, destination target, and coverage state. Add links for unannotated types; Edit links for existing annotations.

**What would need to change:**

- `AnnotationStorageService`: add `getForEdge(source_id, field_name, dest_id)` — a thin wrapper around the existing query with a constructed `target_id` string.
- `ContextAssembler`: when `ref_depth > 0` and an edge is followed, call `getForEdge()` and include the result in the `references` payload. Fall back to the field-level annotation if no edge annotation exists.
- `CoverageService`: new `computeEdgeCoverage()` using the two-level hierarchy (field_annotated → edge_annotated). `affects_edge_coverage` third-party setting on `AnnotationType` mirrors the existing `affects_coverage` pattern.
- `ScanService::computeDiff()`: extend to enumerate and diff traversable edges when field target bundles change, surfacing newly reachable edges as coverage gaps.
- `annotations_ui`: add `EdgeEnumerator` service and the Relationships section.

**Remaining risk:** the naming convention for edge IDs must be documented and stable. If `AnnotationTarget` IDs ever change (rare — derived from entity type and bundle names, which are permanent), edge annotation `target_id` values would silently orphan. Acceptable given the dev-only reinstall policy.

### 2. Queryable graph interface (moderate value, higher effort)

Replace the static snapshot approach with a graph query API. Rather than assembling the full payload and filtering it in the consumer, allow callers to specify a starting node, traversal depth, filter predicates, and return shape.

The `graphql` Drupal contrib module is the natural tool here. It exposes Drupal entities as a queryable graph via a typed schema, and the annotations entity model maps cleanly onto GraphQL types and resolvers.

**Risk:** GraphQL adds a schema maintenance surface. Worth it only if multiple consumers need different subgraph shapes.

### 3. Obsidian export (lower effort, immediate utility)

Emit the assembled context as Obsidian-flavoured markdown: each target becomes a note, entity-reference relationships become `[[wikilinks]]`, and annotations become note body content. Obsidian's graph view and Dataview plugin provide human-readable graph visualisation and querying at zero additional infrastructure cost.

This is the highest value / lowest effort starting point for two reasons. First, it delivers immediate utility for editorial navigation. Second, it functions as a coverage diagnostic: if the exported graph is navigable without edge annotations, the case for implementing them weakens. If it looks like a hairball of unlabelled connections, that confirms the gap and makes the priority concrete. The Obsidian output surfaces exactly where edge annotation precision matters before any coverage infrastructure is built.

#### Implementation: annotations_export module

`annotations_context` currently owns both the assembler and its markdown renderer (`ContextRenderer`) — a stateless service that turns an assembler payload into a markdown string. The preview UI and MCP/JSON endpoints depend on `ContextRenderer` directly and stay in `annotations_context`.

`annotations_export` is a new sub-module that owns delivery: Drush commands and file writers. It depends on `annotations_context` for both the assembler and `ContextRenderer`, which it calls directly. The existing `annotations:context:export` Drush command moves here. The Obsidian vault writer is added alongside it.

| Concern | Module |
| --- | --- |
| Assembly (`ContextAssembler`) | `annotations_context` |
| Markdown rendering (`ContextRenderer`) | `annotations_context` |
| Preview UI | `annotations_context` |
| MCP / JSON API | `annotations_context` |
| Drush export (`ann:ex`) | `annotations_export` |
| Obsidian vault writer | `annotations_export` |

```bash
drush ann:ex --format=obsidian --output=/path/to/vault
drush ann:ex --format=markdown --output=/path/to/file
drush ann:ex                                            # markdown to stdout
```

The `obsidian` format writes one `.md` file per target:

```markdown
---
target: node__article
entity_type: node
bundle: article
tags: [annotated, 3-fields]
---

# Article

{{description annotation}}

## field_title
{{field annotation}}

## field_body
{{field annotation}}

## Relationships
- [[taxonomy_term__tag]] via `field_tags` — {{edge or field annotation}}
- [[user]] via `field_author` — (inferred: authorship)
- [[node__article]] via `field_related_content` — {{field annotation}}
```

Each `[[wikilink]]` resolves to the corresponding target note in the vault. Obsidian's graph view connects them automatically. Dataview queries can surface unannotated nodes, edge coverage gaps, or all targets of a given type.

#### Reverse direction: Obsidian vault → Drupal bootstrap

The inverse operation is theoretically viable. An Obsidian vault authored as a content model — one note per content type, wikilinks as relationships, frontmatter as target metadata, note body sections as annotation prose — could drive a `drush ann:import --source=obsidian` command that:

1. Reads vault `.md` files and derives `annotation_target` config entity definitions from frontmatter and note titles.
2. Walks wikilinks to infer entity-reference field relationships between targets.
3. Creates `Annotation` content entities from note body sections keyed by field name headings.

This inverts the authoring workflow: instead of annotating an existing Drupal site to describe it, an author models the desired site structure in Obsidian first, then bootstraps Drupal config from that model. Most useful for greenfield site builds where the content model is being designed before Drupal is configured — the graph becomes the spec, not the output.

The Obsidian format the export produces (section 3 above) is already structured for round-trip fidelity: frontmatter carries `target`, `entity_type`, `bundle`; field headings are machine names; `[[wikilinks]]` are target IDs. An importer would parse exactly those conventions.

Not planned; noted as a potential long-term direction.

---

### 4. schema.org alignment (low effort, optional)

Map `AnnotationType` IDs to schema.org properties where applicable (e.g., `description`, `abstract`, `keywords`). This adds machine-readable semantics for external tools without changing the storage model.

---

## ECA and Modeler: what they are and what is borrowable

**ECA (Event-Condition-Action)**  
ECA is a no-code rules engine for Drupal. It processes `(event → condition → action)` models stored as config entities and executed at runtime. Its models are directed acyclic graphs. The graph structure, node configurations, and edge relationships are all serialised into a single config entity and deployed atomically via `cex`/`cim`.

**Modeler API (Workflow Modeler)**  
The Modeler API is a generic React-based visual graph editor — drag-and-drop nodes and edges on an infinite canvas. ECA is its primary consumer but it is built on a plugin API (`ModelOwner` / `Modeler`) that is genuinely domain-agnostic. The component taxonomy it exposes (`START`, `ELEMENT`, `LINK`, `GATEWAY`, `SUBPROCESS`, `SWIMLANE`, `ANNOTATION`) is workflow-oriented however, and a non-ECA use case would need to define its own mapping. Its `ANNOTATION` component type is a text note that can be assigned to other component IDs — the same concept as this module, applied to workflow nodes.

**Does Modeler relate to entity relationship diagrams in Drupal?**  
No. The Modeler's nodes and edges represent process flow, not data structure. There is no concept of entity type, field cardinality, or foreign key. A dedicated `entity_relationship_diagram` contrib module exists for schema visualisation. The Modeler canvas *could* be repurposed via a custom `ModelOwner` plugin, but it would be working against the component taxonomy. Only worth considering if a visual relationship editor becomes a priority and nothing better exists.

### The trick to steal from ECA's graph storage

ECA does not store edges as first-class entities. Each graph node (a `Component`) carries a `$successors` array — an adjacency list embedded directly in the source node:

```php
readonly class ComponentSuccessor {
  public function __construct(
    protected string $id,          // target component ID
    protected string $conditionId, // edge label — also a component ID
  ) {}
}
```

The entire graph is self-contained in one config entity: traversable without JOINs, atomic on deploy, and versionable in git as a single file.

**Applied here:** the `Annotation` entity's `target_id` field is already an unconstrained string — it has no foreign key enforcement. Every traversable edge in the context assembler has a derivable, stable ID: `{source_target}__{field_name}__{dest_target}`, e.g. `node__article__field_tags__taxonomy_term__tag`. An `Annotation` stored against that string as `target_id`, with `field_name = ''` (the existing bundle-level sentinel), *is* an edge annotation — with zero schema changes.

This collapses the "annotatable edges" problem from "design and build a new entity" to "agree on a naming convention and wire it into the assembler." The valid edge IDs are computable from field definitions the assembler already traverses, so they never need to be stored explicitly — exactly the ECA insight applied to our model.

### Other ECA relevance

- **Inference layer.** ECA could react to annotation saves and create derived annotations — e.g. flagging an edge as under-described when no edge annotation exists for a traversed relationship. This avoids building a custom rules engine. Speculative until the base graph model is stable.

- **ECA models as annotatable targets.** ECA models are config entities. They could be registered as `AnnotationTarget` entries, making the annotation system self-describing.

---

## Value for in-house AI systems

Businesses building internal AI — fine-tuned models, RAG pipelines, agent systems — all hit the same wall: the model knows the world but not *their* business. It doesn't know what their content types mean, what their editorial rules are, how their domain concepts relate. That gap is domain grounding, and it is exactly what a complete annotation layer addresses.

The annotations suite does not produce training data in the content-volume sense. What it produces is the **knowledge layer** that makes everything else work better. The distinction matters: the annotations describe the *schema and semantics* of a business's content model; the business's actual content is the volume. Both are needed; only one is hard to produce in a structured, machine-readable form.

### RAG knowledge base

The context payload — structured, human-curated, relationship-aware — is superior RAG source material to anything produced by scraping the site. Raw pages carry what content *says*; annotations carry what content *means*. A RAG system grounded on annotation context answers domain questions more accurately because it is working from the ontology, not the text. The `annotations_context_ccc` integration already demonstrates this at smaller scope: agent system prompts enriched with annotation context are more precise than prompts without it.

### System prompt components

The assembled context payload drops directly into any agent system prompt as a reusable component. This is format-agnostic: the same JSON or markdown the assembler produces today works for CCC, for OpenAI-compatible agents, for any LLM API. No bespoke integration per agent framework.

### Synthetic training data

The annotation layer is a *specification* from which training data can be generated. Every content type is described, every field has annotated semantics, every relationship has (or should have) an edge annotation. From that specification you can generate schema-valid synthetic examples programmatically — diverse, correctly structured, grounded in the business's actual domain model. This is more defensible than "here is some text to train on" and scales independently of how much real content the business has published.

### Evaluation grounding

When a business tests whether their in-house model is behaving correctly, they need ground truth. The annotation layer is a formal description of correct domain understanding. A model output that contradicts an annotation — misidentifies a relationship, misunderstands a field's purpose, violates an editorial rule — is a measurable failure. The annotation layer can drive an evaluation harness without additional tooling.

### The differentiating property: it stays current

One-time documentation goes stale. The annotation layer is version-controlled config (`cex`/`cim`), regeneratable on demand, with a coverage metric showing what has drifted. As the content model evolves, the knowledge layer can be updated and re-exported. AI systems fed by it stay grounded on the current state of the business, not a snapshot from an earlier point in time.

### LLM-readable export surface (llms.txt)

The assembled annotation payload is already a structured, machine-readable description of a site's content model. Exposing it as an `llms.txt`-style file (analogous to `robots.txt`, an emerging convention for site-level LLM context) costs almost nothing on top of the existing `ann:ex --format=markdown` output. A site-level endpoint or drush-written file at `llms.txt` would let any LLM-aware toolchain discover and load the annotation layer without bespoke integration. The `annotations_context` JSON endpoint already provides the richer structured form; `llms.txt` is the low-friction human-and-machine-readable complement. Not planned; worth a small spike if the module needs a demo-friendly "drop this on your site and LLMs understand it" story.

### Annotations as a persistent memory layer

RAG treats content as a retrieval corpus at inference time. Memory is different: state that persists *between* LLM interactions — facts, user preferences, episodic context — so later sessions have continuity. The annotation entity model maps onto this directly. Annotation types become memory categories (fact, preference, observation, episode); annotation targets tie memories to the specific content they are *about* rather than floating them in a disconnected key-value store. The `consume {type} annotations` permission model lets an AI agent read certain memory categories but not others. The write path is the current gap: annotations are human-authored today, and an AI agent creating annotations as memories would need a lightweight POST pattern (JSON:API or a thin custom endpoint). This is a small addition. The differentiator over purpose-built LLM memory stores (Mem0, conversation-level summaries) is content-linkage: a memory is anchored to the node, paragraph, or field it concerns, not just to a session. No other system in the Drupal ecosystem provides that.

### Boundary

The suite does not address content-volume training needs — style, tone, product catalogue, historical records. Those require a separate data pipeline. What the annotations suite provides is the structural and semantic layer: the ontology, the rules, the relationships. For most in-house AI use cases (internal assistants, content tooling, editorial support, agent grounding), that structural layer is the hard part the business does not know how to produce. The content volume they already have.

---

## Recommendation

Start with the **Obsidian export** (`annotations_export` module, `drush ann:ex --format=obsidian`). It costs little, delivers immediate editorial utility, and functions as a coverage diagnostic — the graph view will reveal whether edge annotation gaps actually impede navigation in practice.

The suite exposes two Drush commands: `ann:ex` (export, all formats) and `ann:scan`. The alias follows the `cex`/`cim` config management convention. The former `ann:ctx` command is retired; its functionality lives in `ann:ex --format=markdown`.

If the Obsidian output confirms the gap, implement **annotatable edges** using the three-level hierarchy above: auto-inferred obvious edges pass automatically, field-level annotations cover the common case, edge-level annotations handle the exceptions. Reuse existing `AnnotationType` IDs throughout — no vocabulary design needed upfront.

The **Relationship UI** in `annotations_ui` follows from annotatable edges: enumerate traversable edges, de-emphasise obvious ones, surface coverage state for the remainder.

ECA as an inference layer is a longer-term option worth revisiting once the graph model is stable.
