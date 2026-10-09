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

### The row-edit session

Every edit modal is the core's **row-edit session**,
`framework/frontend/core/src/conflict/rowEditSession.ts`, not a copy of another
modal's edit. The merge under it stays pure data in `rowEdit.ts` —
`openRowEdit`, `resolveRowEdit`, `keepMineRowEdit`, `takeTheirsRowEdit` — and
the session is the one place that drives it: the snapshot taken on open, the
live row, the step applied as it arrives, the choice at a conflict, the one
line of notice, the one verdict "can save", and the one door of the send. A
framework window takes its own factory beside its module —
`createHilosSettingEdit`, `createHilosChannelFieldEdit`,
`createHilosTwoFactorSettingEdit`, `createHilosOauthRedirectEdit`,
`createHilosOauthProviderFieldEdit`, `createHilosImpersonationScopeEdit`,
`createHilosUserRenameEdit`, `createHilosLegalSettingEdit` — holding what is
the window's own and the same in every view layer; a project window takes
`createHilosRowEdit` directly (the chat's bot window). Nobody assembles a
window from the four merge functions: the linter refuses their value import in
the view layers and the demos (`import type` stays allowed), so the next rule
of an edit window is written once, not once per window.

**What the session holds.**

- **The live row** comes from a source. `hilosTableRowEditSource` is the row of
  a table window: `focusRow` on open (in place of `applyAndResolve`),
  `releaseFocus` on close, `focusedRow` to read, and the key of the row in
  focus checked against the key the window opened on, so another dialog taking
  the focus reads as the row gone. `hilosSignalRowEditSource` is a row a signal
  already carries — a card's own row, the person's own name. A table whose rows
  open an edit, delete, or confirmation dialog wires `sendFocus`; the rotation
  takeout and withdrawal dialogs use the same focus to follow their batch
  (HIL-1233). The server follows the focused row for the tab past the window —
  a search, a page turn, a move under the order — so the modal reads it
  wherever it went, and reads `undefined` only when the row is gone
  ([table-subscription.md](table-subscription.md), "A row an open dialog holds
  in focus").
- **The form and the fields.** The session holds the window's form, and the
  window gives the projection: `fields(row)` from the live row, `form(row)` on
  open, `draft(form, row)` into the edited fields, `take(form, taken, row,
  live)` for a value the other side moved. A form richer than its fields — a
  setting's switch and text folding into one `overrideValue`, a channel's text
  and typed value — stays the window's; when the form is the fields, the
  projections default to the identity. A value hidden from a viewer of the
  admin view mode moves the snapshot and never lands in the form. The window
  says who holds the form: the session, from an `initial` form, or a signal the
  window keeps for more than the edit (`formSignal` — a form shared with a
  creation, a draft a flow hands out as its own).
- **The step** of the merge is applied by the session from `start()` to
  `dispose()`: a field only the other side changed lands in the form silently
  and the snapshot moves. The snapshot always holds the last value the person
  saw as saved, so a second change after a choice compares against that, not
  against the value the modal opened with. **Keep mine** and **Take theirs**
  move it the same way.
- **The notice** is one line by precedence deleted › conflict › updated. The
  window gives the conflict's words (`notice.conflict`) and may give its own
  for deleted and updated; the defaults are `HILOS_ROW_EDIT_COPY`. "Updated
  just now" stays exactly while the form shows the value the other side put
  there — typing over it takes the note away, and closing the modal resets it;
  there is no timer. The modal's messages share **one line of room** under the
  fields, taken before there is anything to say (`HilosEditNotice`, per
  [styling-rules.md](styling-rules.md), "The room a live message takes").
- **Can save** is one verdict: the window is open, the row is still there, no
  conflict stands, no save is in flight, the input passes the window's check
  (`valid` — a name within its bounds, a required field filled), and the draft
  differs from the live row. The Save button and the form's submit — Enter —
  read this one verdict; when the row is gone the button reads "Deleted". The
  lock of the admin view mode stays in the markup (`ConflictActions`); the
  server refuses a viewer's send on its own.
- **One door of the send.** `save(send)` refuses when the verdict says so,
  closes an unchanged draft without a round-trip, and otherwise sends, is
  saving until the outcome, and closes on success — the reply of the tracked
  action for a window over a table. `saveLanded(send, refusal)` is the same
  door for a window whose project answers over the row and not over the
  action's reply (the person's name): saving until the live fields read as
  the draft sent — Take theirs in the meantime rewrites the draft, not what is
  waited for — or until the refusal turns non-null. The outcome of a save
  whose window closed in the meantime moves nothing.

**What stays with the window.** The markup; the tracked action
(`useTrackedAction`, `createHilosTrackedAction`), which draws the loading, the
refusal and the toasts, and whose runner the view hands to `save`; the words;
the check of the input; what the form forgets on close (a provider's secret).
The view mirrors the session's signals through its bridge (`useSignal`,
`hilosSignal` / `mirrorHilosSignal`), writes its inputs through `setForm` /
`patchForm`, and passes `state.dirty` to the modal's confirm-on-close.

- **Merge** is offered only on a surface where splicing two values makes sense
  (`mergeable`); a typed value — a setting, a channel field, a name — has no
  Merge, only Keep mine and Take theirs.
- The worked examples are `createHilosSettingEdit` and the three settings
  pages, `framework/frontend/{vue,react,angular}/src/admin/settings/HilosSettingsPage.*`.

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
