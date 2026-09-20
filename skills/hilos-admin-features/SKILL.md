---
name: hilos-admin-features
description: Graduate or build a Hilos admin feature — an admin page backed by a browser table and its actions (settings, hilos-users, roles, or a project's own admin table). Use when moving admin table/page/action code between framework and project, deciding the framework/project boundary, choosing framework-owned (activate/configure/use) vs project-owned-by-pattern, abstracting a presence source, or designing extension points for an admin entity, or when an action of the feature changes an entity the page does not own and you need to know what closes it.
---

# Hilos Admin Features

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then
read the canonical spec before editing.

## Read First

- Graduation spec + the two modes + the framework/project boundary:
  `docs/agents/architecture/admin-features.md`
- Browser table / source fan-out mechanics:
  `docs/agents/architecture/browser-source-fanout.md`
- What a row change does on the client — the pending/Apply gate, own changes, and
  the live exception for progress/status rows:
  `docs/agents/frontend/table-subscription.md`
- Which sort orders a table may declare, what it must refuse, and what it
  answers when asked for another: `docs/agents/frontend/table-sort-orders.md`
- Framework extension points + the contract gate:
  `docs/agents/framework-development.md`
- Page/table topology registration: `docs/agents/app-topology.md`
- DB/RT data work: use `$hilos-orm`, `$hilos-runtime`, `$hilos-db-rt-state`
- Frontend views/context: `docs/agents/frontend/sdk-packaging.md` and
  `$hilos-frontend-page-structure`
- An action of this feature writes an entity the page does not own — what
  closes it today, and the two-step form when the name stays on the page:
  `docs/agents/architecture/entity-libraries.md`
  (section "The Lock Does Not Travel With The Name")
- An admin table is the first consumer of the table-agent rule — one holder per
  table per subject, the administrators' windows as state inside it, and a
  refusal instead of an empty list when no holder answers:
  `docs/agents/architecture/table-agents.md`
- Who owns the verifier circle: `docs/agents/architecture/protected-mode.md`

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
5. Before an action of this feature writes an entity the page does not own,
   check what closes it today; if more than AUTHENTICATED, the name stays on
   the page — the page sends the owner a signal, defers its ack, and answers
   the client after the owner confirms
   (`docs/agents/architecture/entity-libraries.md`, section "The Lock Does
   Not Travel With The Name"). The same file's section "When A Name May Live On
   The Library" answers the other direction — when the name belongs on the owner
   after all, and who answers when the gatekeeper is not the answerer.
6. Pass every framework/project boundary shift through the contract gate.
7. Validate with composer scripts via `$hilos-testing-cli`; keep existing admin
   e2e green and add framework unit coverage for the graduated base.

## Hard Rules

- The Apply gate holds a row's position and membership, not its values. A change
  to a value in a shown row applies at once; only a move and a removal wait. The
  reader's own change is the single exception, and a backend may not declare an
  ordinary mutation live to step around the gate. Running work is not a row at
  all — it shows as a progress bar. Data rows stay gated even when this feature's
  own action created them (`docs/agents/frontend/table-subscription.md`,
  *What applies at once and what waits*).

- Never run `git commit` or `git push`.
- Do not copy a framework admin table's query/merge/mutation/action code into a
  project to activate the feature; bind content to the framework table instead.
- Keep `admin_users` (Mode 2, project-owned) separate from `hilos_users`
  (Mode 1, framework-owned); do not fold one into the other.
- The verifier circle belongs to the freeze, not to backup, and a framework-owned
  section may have no line in `FEATURES` at all (Mode 1 of the graduation spec).
- Do not pass `Hilos::$db`/`$rt`/`$setting`/`$table` through constructors to reach
  a graduated base; read the facade at the point of use.
- Do not move an admin action's name to the owning agent, and do not write one
  straight onto it: an agent action is closed by `AUTH_ACTIONS` alone. An action
  closed by more than AUTHENTICATED keeps its name on the page; the page forwards
  the write and answers the client after the owner confirms.
- Stop and ask before changing hilos-user DB fields, RT presence shape, signals,
  action DTOs, or declarative routing.
