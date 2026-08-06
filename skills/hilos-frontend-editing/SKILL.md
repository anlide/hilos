---
name: hilos-frontend-editing
description: Implement or change a data-editing surface on the frontend — any form that edits and saves an entity. Use when adding, building, porting, or reviewing an edit/rename/update form in a Vue/React/Angular view, deciding inline vs modal, or wiring an edit session's draft, merge, and save lifecycle.
---

# Hilos Frontend Data Editing

Use this skill whenever you implement, change, port, or review a frontend surface
that edits and saves entity data. Start with `agents.md`, then read the canonical
rules below. Every edit surface is a modal — never an inline form.

## Read First

- What an edit does to a live table — the pending/Apply gate, own-change
  correlation, and the narrow live exception:
  `docs/agents/frontend/table-subscription.md`
- Editing and modals (rule catalog, section E): `docs/agents/frontend/rules-and-violations.md`
- The modal edit session and three-way merge (canonical): `docs/agents/frontend/conflict-resolution.md`
- The `HilosModal` primitive and per-framework view adapters: `docs/agents/frontend/multiframework-core.md` (component: `framework/frontend/{vue,react,angular}/src/HilosModal.*`)
- Where the edit view and its files live: `docs/agents/frontend/page-module-structure.md`
- Row-payload key ownership — the constant a field is read and rendered by:
  `docs/agents/code-style/wire-key-ownership.md`

## Workflow

1. Put every edit session in a modal: mount `HilosModal` (the agnostic SDK
   primitive) or a project descendant; the parent view owns the form fields
   inside it. Never reveal an inline `<form>` in the page.
2. The modal owns the session — freeze a `baseline` snapshot on open, clone it
   into an editable `draft`, and keep the edited entity live-subscribed so
   `incoming` committed changes arrive while the modal is open.
3. Merge per field against the baseline: take user-only and server-only changes
   automatically; surface a conflict only when the same field changed to
   different values, presenting both for the user to pick.
4. Save is `submit → loading → backend echo`, not the tables' Apply. Validation
   is backend-only; field errors return on the action's `::fail`. Close on the
   committed echo.
5. If the entity is deleted while the modal is open, keep the modal open, block
   save, set the primary button to "Deleted", and keep the draft extractable —
   never discard it silently.
6. When porting the edit view to another framework, re-check it against section E
   and `conflict-resolution.md`; do not copy an inline form forward.

## Hard Rules

- An edit's echo is gated behind Apply for every tab but the one that made it. Do
  not work around the gate: mark the edited row before dispatching
  (`expectOwnChange`) so the initiator applies its own change, and leave everyone
  else gated.

- Edit only in a modal; inline forms are forbidden. Use `HilosModal` or a
  descendant — the parent owns the form.
- The modal owns the baseline / draft / incoming three-way merge; deviating from
  it is a gross violation.
- Save commits only on the backend echo — never optimistic, never the tables'
  Apply path.
- Entity deleted mid-edit: keep the modal open and the draft extractable.

## Contract Gate

Keeping the edited entity live-subscribed while its modal is open, and the
entity's `version` field for optimistic concurrency, are backend contract
surfaces. Pass the Contract approval gate in `agents.md` before changing them.
