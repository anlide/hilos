---
name: hilos-admin-scaffold
description: Generate the activation of a framework-owned admin feature — settings, hilos-users, backup, or a future one like roles — in a Hilos project. Use when wiring settings, hilos-users, or backup into a project, generating the project-side binding a framework admin feature requires (catalog, user entity, presence source, table subclass, thin page, SDK view mount; for backup, the backup catalog, env values, agent/CLI registration, and RT-index binding), or stepping through the per-feature activation order. This covers framework-owned features only; a project's own divergent admin table is Mode-2 authoring — use $hilos-admin-features for that.
---

# Hilos Admin Scaffold

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then
the recipe before generating code.

## Read First

- Per-feature generation recipe + the contract shapes:
  `docs/agents/architecture/admin-feature-scaffold.md`
- Normative boundary + the two modes: `docs/agents/architecture/admin-features.md`
- Browser table / source fan-out mechanics:
  `docs/agents/architecture/browser-source-fanout.md`
- Topology registration (PAGES / TABLES / PAGE_TABLES): `docs/agents/app-topology.md`
- DB/RT data work: `$hilos-orm`, `$hilos-runtime`, `$hilos-db-rt-state`
- Frontend mount + thin context: `docs/agents/frontend/sdk-packaging.md` and
  `$hilos-frontend-page-structure`

## Workflow

1. Identify the framework feature and its contract shape: framework-owned data
   source (settings — configure-only), project-owned data behind a framework
   contract (hilos-users — bound), or a configure-only engine with a monopoly
   agent (backup — a catalog + env + agent/CLI/RT-index binding). Read the base
   class; generate what it leaves abstract.
2. Generate against the framework base classes and their extension points, never
   by copying another project. The engine — table merge, page subscribe, action
   lifecycle — stays in the framework base.
3. Configure-only: generate a catalog provider, register the `final` framework
   table, add a thin subscription-owner page, mount the SDK view.
4. Bound: generate in dependency order — DB entity → RT presence source
   (implements `HilosPresenceSource`) → table subclass (the abstract hooks) →
   thin page → topology + SDK view mount.
5. Pass every DB-entity / RT-item change through the contract gate before writing.
6. Validate with composer scripts via `$hilos-testing-cli`; keep the project's
   admin e2e green. Registering the feature's page / agent / table in the
   topology also means updating the demo's `*TopologyRegistryTest` snapshot and
   running its `test:unit` — a shared cross-ticket guard
   (`docs/agents/app-topology.md` step 12).

## Hard Rules

- Never run `git commit` or `git push`.
- Generate against the framework base; do not generate a copy of the table
  merge/mutation or the page `onAction` lifecycle.
- Back presence with a project RT collection implementing `HilosPresenceSource`,
  not framework analytics (process-local, not user-keyed).
- Scaffold framework-owned features only; a project's own divergent table is
  Mode-2 authoring — do not generate it with this recipe.
- Stop and ask before generating hilos-user DB fields or the RT presence item shape.
