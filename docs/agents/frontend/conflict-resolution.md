# Conflict Resolution

Collaborative editing is designed in from day one. All editing happens in a
modal, and the modal owns a three-way merge so a user always knows whether a
conflict exists and whether it is resolvable. This builds on the entity store and
the authoritative-backend rule ([data-model.md](data-model.md), [core-and-connection.md](core-and-connection.md)).

## Ask in a modal

All editing happens in a modal; inline forms are forbidden. So does any
other mutation that takes a parameter — a creation or a run with an
option asks for it in a modal too. The merge below belongs to editing
alone: a creation has no baseline to merge against. The modal component
itself is agnostic — the parent owns the form — but every edit session runs
through a modal precisely so the merge below has one home.

## The modal owns the edit session

The shared entity store always holds the latest **committed** value plus a
version; pages outside the modal always show that committed data. The edit
session — and only the edit session — holds the in-flight state, as three layers:

- **baseline** — a snapshot frozen when the modal opens;
- **draft** — an editable clone of the baseline the user changes;
- **incoming** — committed changes that arrive while the modal is open, because
  the edited entity stays **live-subscribed** for the modal's lifetime.

Drafts never smear onto the shared entity ([data-model.md](data-model.md)); other views keep
showing committed data until the user's save commits.

### The per-field three-way merge

For each field, compare against the open-time baseline:

- `userChanged = draft ≠ baseline`
- `serverChanged = incoming ≠ baseline`

and resolve:

- neither changed → nothing to do;
- only the user changed it → keep the draft;
- only the server changed it → take the incoming value automatically;
- **both changed, to different values → a conflict**, surfaced to the user.

Different fields changed by different users merge automatically (each falls into
an "only user" or "only server" case). The same field changed to different values
is the only thing that cannot auto-resolve.

### Surfacing conflicts

When a field conflicts, the user must know it exists and that it needs a choice:
present both values (theirs and the incoming) and let the user pick. The merge is
per field, so non-conflicting fields stay merged while the user resolves the one
that conflicts.

### The shared row-edit helper

Every edit modal is built on `framework/frontend/core/src/conflict/rowEdit.ts`,
not on a copy of another modal's merge. The helper is pure data: the view keeps
two things — its own form and one `RowEditBaseline`, taken with `openRowEdit`
when the modal opens — and projects both the form and the live row into one
shape of edited fields. For a modal over a table window, the live row is the
table's **focused row**: `focusRow` on open (in place of `applyAndResolve`),
`releaseFocus` on close, `focusedRow` to read; a table whose rows open an edit
or delete dialog wires `sendFocus`. The server follows that row for the tab past
the window — a search, a page turn, a move under the order — so the modal reads
it wherever it went, and reads `undefined` only when the row is gone
([table-subscription.md](table-subscription.md), "A row an open dialog holds in
focus").

`resolveRowEdit(live, baseline, draft)` returns the whole verdict: `gone`,
`conflict`, `dirty`, every field's merge, the `notice` to show, and a `settle`
step. The view applies a step whenever there is one — the snapshot moves, and a
field only the other side changed lands in the form silently — and answers a
conflict with `keepMineRowEdit` or `takeTheirsRowEdit`, which move the snapshot
the same way. The snapshot always holds the last value the person saw as saved,
so a second change after a choice compares against that, not against the value
the modal opened with.

- **Save** is locked while `!dirty`, while a save is in flight, while a conflict
  stands, and when the row is gone; when it is gone the button reads "Deleted".
- The modal's messages share **one line of room** under the fields, taken
  before there is anything to say (`HilosEditNotice`, per
  [styling-rules.md](styling-rules.md), "The room a live message takes"), and
  show one thing by precedence: deleted › conflict › updated. "Updated just
  now" stays exactly while the form shows the value the other side put there
  — typing over it takes the note away, and closing the modal resets it; there
  is no timer.
- **Merge** is offered only on a surface where splicing two values makes sense
  (`mergeable`); a typed value — a setting, a channel field, a name — has no
  Merge, only Keep mine and Take theirs.
- The worked examples are the settings pages,
  `framework/frontend/{vue,react,angular}/src/admin/settings/HilosSettingsPage.*`.

## Save is authoritative-backend, not Apply

A modal save is **submit → loading → backend echo**, not the tables' pending /
Apply mechanism (Apply is tables-only — see [table-subscription.md](table-subscription.md)). The save
emits an action; frontend state changes only when the backend echoes it
([core-and-connection.md](core-and-connection.md)). Validation is backend-only: field errors return via
the action's `::fail` ([rules-and-violations.md](rules-and-violations.md)). On a successful echo the store
updates and the modal closes.

## Entity deleted while the modal is open

If the edited entity is deleted while its modal is open, the modal **stays
open**, save is **blocked**, and the primary button reads **"Deleted"**. The
user's draft stays visible and **extractable** so unsaved input can be copied out
— it is never silently discarded.

## Live editing indicators

A live "User X is editing" or "User X changed field A" indicator is supported
(the entity is already live-subscribed for the merge). It is presentational and
optional, not part of the merge decision.

## Backend contract surface (the gate)

The model keeps the backend small, and the change passes the Contract approval
gate in [agents.md](../../../agents.md):

- each entity carries a **version** for optimistic concurrency — there is **no**
  per-field change-metadata on the wire (the three-way merge lives in the modal,
  not on the entity);
- the edited entity stays **live-subscribed** while its modal is open, so
  `incoming` is delivered — for a row of a table window that is the focus the
  modal takes on it, and the server's following of that row past the window
  ([table-subscription.md](table-subscription.md), "A row an open dialog holds
  in focus").
