# ADR 0005: One repository for all modules

**Status:** Accepted
**Supersedes:** Initial multi-repo approach (one repo per module)

## Context

The DOT suite started development with modules in separate repositories (dot, dot_scan, dot_annotation, dot_report each in their own repo). This was motivated by a desire to keep modules independently releasable and to mirror how some large Drupal projects separate contrib modules.

In practice this created friction: cross-module changes required coordinated commits across repos, context-switching overhead was high, and the modules are developed and versioned together — there is no realistic scenario where `dot_scan` ships on a different release cycle from `dot`.

Git submodules were considered as a middle ground. They were rejected: submodules are designed for pulling in external dependencies, not for organising sub-packages of the same project. They add clone complexity and contributor friction for no real benefit here.

## Decision

All modules live in the `dot` repository under `modules/`:

```
dot/
├── dot.info.yml
└── modules/
    ├── dot_scan/
    ├── dot_annotation/
    ├── dot_report/
    └── ...
```

This is the standard Drupal.org pattern for module suites (Commerce, Webform, Search API, etc.). Drupal.org treats the root as the project; Composer picks up all sub-modules from the single package.

## Consequences

- Single issue queue, single release cycle, single CHANGELOG
- Cross-module changes land in one commit
- `CLAUDE.md` travels with the module repo, not the DDEV environment repo
- The only reason to split would be if a sub-module needed a genuinely independent release schedule or separate maintainers — neither applies
