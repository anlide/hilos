---
name: hilos-frontend-editing
description: Implement or change a frontend edit form or a modal for a mutation with a parameter. Use when adding, porting, or reviewing edit/rename/update forms, known-place creation observed through a live carrier row, new-entity creation, or run-with-an-option dialogs in Vue/React/Angular, deciding inline vs modal, or wiring the draft, merge, and save lifecycle.
---

# Hilos Frontend Data Editing

Use this skill whenever you implement, change, port, or review a frontend surface
that edits and saves entity data, or asks for the parameter of any other
mutation — a creation, a run with an option. Start with `agents.md`, then read
the canonical rules below. Every edit surface is a modal — never an inline form
— and so is any mutation that takes a parameter.

## Read First

- What an edit does to a live table — a value applies at once, only a move or a
  removal waits behind Apply, plus own-change correlation:
  `docs/agents/frontend/table-subscription.md`
- Mutations and modals (rule catalog, section E): `docs/agents/frontend/rules-and-violations.md`
- The modal edit session and three-way merge (canonical), starting with
  "Creation in a known place": `docs/agents/frontend/conflict-resolution.md`
- The row-edit session for edits and creation in a known place, and the window
  factories beside the framework modules: `framework/frontend/core/src/conflict/rowEditSession.ts`
  (`conflict-resolution.md`, "The row-edit session"); the merge under it,
  `rowEdit.ts`, is never assembled by a view
- The `HilosModal` primitive and per-framework view adapters: `docs/agents/frontend/multiframework-core.md` (component: `framework/frontend/{vue,react,angular}/src/HilosModal.*`)
- Where the edit view and its files live: `docs/agents/frontend/page-module-structure.md`
- Row-payload key ownership — the constant a field is read and rendered by:
  `docs/agents/code-style/wire-key-ownership.md`
- An edit dialog on an admin page in the admin view mode — Cancel stays, Save
  gives way to the view-mode mark: `docs/agents/architecture/admin-view-mode.md`
- How the edit view itself must look: `$hilos-code-style-typescript` (and its
  `-vue` / `-react` / `-angular` wrapper)

## Workflow

1. Put every edit session in a modal: mount `HilosModal` (the agnostic SDK
   primitive) or a project descendant; the parent view owns the form fields
   inside it. Never reveal an inline `<form>` in the page. The parameter of a
   creation or of a run with an option is asked in the same kind of dialog —
   the page keeps no strip of its own for it.
2. Classify the dialog using "Creation in a known place" in
   `docs/agents/frontend/conflict-resolution.md`. An edit or creation in a
   place addressed on open and observed through a live carrier row takes the
   core row-edit session — the window's factory in `@hilos/core` for a framework
   window, `createHilosRowEdit` for a project window. Never assemble one from
   `openRowEdit` / `resolveRowEdit` in a view (the linter refuses the import).
   A new entity by an entered key or server-assigned id, or a run with an option,
   stays a modal without this merge; a key collision stays a server refusal.
3. For edits and creation in a known place, use the session's per-field merge
   and baseline advancement from `start()` to `dispose()`. The initial snapshot
   is what the form showed, including empty fields and prefills; occupancy
   independently chooses add or update. Follow the canonical outcomes, with
   Keep mine / Take theirs through `ConflictActions` for different changes to
   the same field. Keep the opening title when Add becomes Save; the notice
   explains the event. An "already exists" race keeps the draft and live source
   for the same merge, without a blind retry.
4. Save is `submit → loading → backend echo`, not the tables' Apply. The view
   uses one verdict for the button and Enter. For a row-edit session, bind
   `canSave` and hand both to `save(run)` with the kit's tracked runner; the
   session closes on success. A valid creation in an empty known place can
   save unchanged shown values; after occupation, ordinary saved-value
   difference, conflict, permission and domain checks apply. Validation is
   backend-only; field errors return on the action's `::fail`.
5. If the edited object or its carrier row disappears, keep the modal open,
   block save, set the primary button to "Deleted", and keep the draft
   extractable. Removing a value under an open edit does not return it to
   creation; an initially empty known place is not a deleted row.
6. When porting the edit view — or the dialog of any mutation with a
   parameter — to another framework, re-check it against section E and
   `conflict-resolution.md`; do not copy an inline form forward.

## Hard Rules

- An edit's echo is gated behind Apply for every tab but the one that made it. Do
  not work around the gate: mark the edited row before dispatching
  (`expectOwnChange`) so the initiator applies its own change, and leave everyone
  else gated.

- Edit only in a modal, and ask for the parameter of any other mutation in one
  too — a creation, a run with an option; inline forms are forbidden. Use
  `HilosModal` or a descendant — the parent owns the form. A mutation that
  takes no parameter — a per-row toggle — needs no dialog of its own.
- Edits and creation in a known live place take the core row-edit session for
  the modal's baseline / draft / incoming merge; a view that assembles the
  merge itself is a gross violation, and the linter refuses the import. New
  entities by entered key or server-assigned id, and runs with a parameter,
  have no such merge.
- Record existence is independent of value truthiness. The shown values start
  the baseline, which the session advances; follow the canonical outcomes for
  empty values, prefills and concurrent creation.
- Save commits only on the backend echo — never optimistic, never the tables'
  Apply path.
- Save and Enter share the verdict. An unchanged valid creation in an empty
  known place may save; an equal already saved value may not. Conflicts, required
  inputs, permissions, domain restrictions and a save in flight still gate it
  (`docs/agents/frontend/rules-and-violations.md`, section E).
- Entity or value deleted mid-edit: keep the modal open and the draft
  extractable, with saving blocked; do not return it to creation.

## Contract Gate

Keeping the edited entity live-subscribed while its modal is open, and the
entity's `version` field for optimistic concurrency, are backend contract
surfaces. Pass the Contract approval gate in `agents.md` before changing them.
