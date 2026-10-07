---
name: hilos-admin-scaffold
description: Generate the activation of a framework-owned admin feature — settings, hilos-users, backup, the maintenance section, the languages and countries section (i18n), the Daemon section, Analytics, or a future one like roles — in a Hilos project. Use when wiring one of these sections into a project, generating the project-side binding it requires (catalog, presence source, table subclass, thin page, agent pair, or SDK view mount), or stepping through the per-feature activation order. This covers framework-owned features only; a project's own divergent admin table is Mode-2 authoring — use $hilos-admin-features for that.
---

# Hilos Admin Scaffold

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then
the recipe before generating code.

## Read First

- Per-feature generation recipe + the contract shapes:
  `docs/agents/architecture/admin-feature-scaffold.md`
- Normative boundary + the two modes: `docs/agents/architecture/admin-features.md`
- The people table `hilos_user` hilos-users draws over — what the framework owns
  of a person and what stays the project's: `docs/agents/architecture/people-table.md`
- Adding the project's own columns to `hilos_user` — the whole ORM chain
  subclassed and mounted under the framework key: `docs/agents/orm/inheritance.md`
  (that work is `$hilos-orm`'s, not this recipe's)
- The personal-data verdict every table must carry, and the restore gates that
  demand it: `docs/agents/architecture/backup-anonymization.md`
- Offering a section's settings as presets — declaring the group, why a preset
  names every key of it, and how a drift reaches the screen:
  `docs/agents/architecture/setting-presets.md`
- What the languages and countries section (i18n) is — the five tables, the one library, the default language from env — and what a project supplies to switch it on: `docs/agents/architecture/languages-and-countries.md`
- What the Daemon section is — the node agent, the collector, the page agent, the master's frame — and what a project supplies to switch it on: `docs/agents/architecture/daemon-section.md`
- What the Analytics section reads, its three required agent pairs and its Privacy boundary: `docs/agents/architecture/analytics.md` and the Analytics recipe in `docs/agents/architecture/admin-feature-scaffold.md`
- Browser table / source fan-out mechanics:
  `docs/agents/architecture/browser-source-fanout.md`
- Topology registration (PAGES / TABLES / PAGE_TABLES): `docs/agents/app-topology.md`
- DB/RT data work: `$hilos-orm`, `$hilos-runtime`, `$hilos-db-rt-state`
- Frontend mount + thin context: `docs/agents/frontend/sdk-packaging.md` and
  `$hilos-frontend-page-structure`

## Workflow

1. Identify the framework feature and its contract shape: framework-owned data
   source (settings — configure-only), framework data with a project-bound
   presence source (hilos-users — bound), or a configure-only engine with a monopoly
   agent (backup — a catalog carrying the reference and PII registries, env
   values, and agent/CLI/RT-index binding; the verifier circle's table is not
   backup's to activate — it belongs to the freeze, and the recipe says where it
   comes from), or a framework section with no feature switch (the maintenance
   section — activation is registering its thin page and the verifier circle
   table in the topology and mounting its SDK view, with no line in `FEATURES`),
   or Analytics (the feature, journal directory, catalog, three agent pairs and
   thin backend page). Its browser tables and SDK views arrive on separate leaves.
   Read the base class; generate what it leaves abstract.
2. Generate against the framework base classes and their extension points, never
   by copying another project. The engine — table merge, page subscribe, action
   lifecycle — stays in the framework base.
3. Configure-only: generate a catalog provider, register the `final` framework
   table, add a thin subscription-owner page, register `SettingsLibraryAgent` and
   `SettingsLibraryAgentDaemon` in `AGENTS` with `PLACEMENT => POLICY` (the single
   writer of the settings collection; one entry, shared with the `LOGS` and
   `NOTIFICATION_DELIVERY` features), mount the SDK view.
4. Bound: generate in dependency order — the `create_hilos_user.sql` stub copied
   among the project's migrations (no entity, no block source: the person is the
   framework's table) → RT presence source (implements `HilosPresenceSource`) →
   table subclass (the presence hooks) → thin page → topology + SDK view mount.
   The recipe names which of these steps are still today's project code until
   their leaves land; follow the recipe, not this summary.
5. The admin surface is closed by default: every framework admin page inherits
   the `ADMIN` access level from `AbstractHilosPage`. Wire the project identity
   seam — `resolveConnectionIdentity()` on the project `BrowserContext` — or the
   mounted feature denies everyone; `isAdmin()` is the framework's answer from
   `hilos_user.admin` (`docs/agents/architecture/page-access-control.md`).
6. Pass every DB-entity / RT-item change through the contract gate before writing.
7. Validate with composer scripts via `$hilos-testing-cli`; keep the project's
   admin e2e green. Registering the feature's page / agent / table in the
   topology also means updating the demo's `*TopologyRegistryTest` snapshot and
   running its `test:unit` — a shared cross-ticket guard
   (`docs/agents/app-topology.md` step 12).

## Hard Rules

- Never run `git commit` or `git push`.
- Generate against the framework base; do not generate a copy of the table
  merge/mutation or the page `onAction` lifecycle.
- Back presence with a project RT collection implementing `HilosPresenceSource`,
  not framework analytics: the cluster writer loads those records seconds later;
  they are not live connection state.
- The account block fact is the framework's: `block` is a column of `hilos_user`
  and the framework reads it. Do not generate a project block source.
- Scaffold framework-owned features only; a project's own divergent table is
  Mode-2 authoring — do not generate it with this recipe.
- Stop and ask before adding columns to `hilos_user` in a project subclass or
  changing the RT presence item shape.
