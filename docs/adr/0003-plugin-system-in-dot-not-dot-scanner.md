# ADR 0003: Target plugin system lives in `dot`, not `dot_scan`

**Status:** Accepted

## Context

DOT discovers site structure via a plugin system — one plugin per entity type (NodeTarget, TaxonomyTarget, etc.). The question was where this framework should live: in `dot_scan` (the module that executes scans) or in `dot` (the core module).

Putting it in `dot_scan` seemed natural — plugins are used for scanning. But `dot_scan` is optional; sites using pre-authored config (recipe/profile deployments) don't need it. If the plugin framework lived in `dot_scan`, the scope management UI in `dot` couldn't use it to list discoverable entity types, and third-party modules couldn't contribute plugins without depending on `dot_scan`.

## Decision

The plugin framework (`TargetInterface`, `TargetBase`, all concrete plugins, `DotDiscoveryService`) lives in `dot`. Plugins are registered as services tagged `dot.target`. `dot_scan` is purely the execution layer — it calls `DotDiscoveryService::getPlugins()` and runs each plugin's `discover()` method, but owns none of the plugin machinery.

## Consequences

- Any module can contribute a target plugin by tagging a service `dot.target` — no changes to `dot` or `dot_scan` required
- `GenericTarget` auto-discovers any fieldable entity type not claimed by a specific plugin, so custom entity types (ECK, hand-rolled) just work without a dedicated plugin
- `dot_scan` can be disabled without breaking the scope management UI
- The scope UI and the scanner share the same plugin list via `DotDiscoveryService` — no duplication
