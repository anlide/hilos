---
name: hilos-admin-features
description: Graduate or build a Hilos admin feature — an admin page backed by a browser table and its actions (settings, hilos-users, roles, or a project's own admin table). Use when moving admin table/page/action code between framework and project, deciding the framework/project boundary, choosing framework-owned (activate/configure/use) vs project-owned-by-pattern, abstracting a presence source, or designing extension points for an admin entity.
---

# Hilos Admin Features

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then
read the canonical spec before editing.

## Read First

- Graduation spec + the two modes + the framework/project boundary:
  `docs/agents/architecture/admin-features.md`
- Browser table / source fan-out mechanics:
  `docs/agents/architecture/browser-source-fanout.md`
- Framework extension points + the contract gate:
  `docs/agents/framework-development.md`
- Page/table topology registration: `docs/agents/app-topology.md`
- DB/RT data work: use `$hilos-orm`, `$hilos-runtime`, `$hilos-db-rt-state`
- Frontend views/context: `docs/agents/frontend/sdk-packaging.md` and
  `$hilos-frontend-page-structure`

## Workflow

1. Decide the mode. Mode 1 = framework-owned feature (settings / hilos-users /
   roles): the project activates, configures, and uses it. Mode 2 = project-owned
   table by pattern (admin_users): the project owns the entity over shared bases.
2. Keep the generic engine (table merge, page subscribe, action lifecycle) in the
   framework; put only content-binding in the project — catalog, extra fields, a
   bound collection, one `SUBSCRIPTION_AGENT_TYPE`, topology registration.
3. Vary behavior through a protected factory/override; vary metadata through a
   catalog provider constant. Bind a collection through a generic so the
   framework never imports the project entity type.
4. Abstract the presence source for hilos-users: the project binds its RT
   connections collection, the framework owns the merge.
5. Pass every framework/project boundary shift through the contract gate.
6. Validate with composer scripts via `$hilos-testing-cli`; keep existing admin
   e2e green and add framework unit coverage for the graduated base.

## Hard Rules

- Never run `git commit` or `git push`.
- Do not copy a framework admin table's query/merge/mutation/action code into a
  project to activate the feature; bind content to the framework table instead.
- Keep `admin_users` (Mode 2, project-owned) separate from `hilos_users`
  (Mode 1, framework-owned); do not fold one into the other.
- Do not pass `Hilos::$db`/`$rt`/`$setting`/`$table` through constructors to reach
  a graduated base; read the facade at the point of use.
- Stop and ask before changing hilos-user DB fields, RT presence shape, signals,
  action DTOs, or declarative routing.
