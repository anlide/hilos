# Conflict Resolution

Collaborative editing is designed in from day one. All editing happens in a
modal, and the modal owns a three-way merge so a user always knows whether a
conflict exists and whether it is resolvable. This builds on the entity store and
the authoritative-backend rule ([data-model.md](data-model.md), [core-and-connection.md](core-and-connection.md)).

## Ask in a modal

All editing happens in a modal; inline forms are forbidden. So does any
other mutation that takes a parameter — a creation or a run with an
option asks for it in a modal too. Editing and creation in a known place
take the row-edit session and its merge below. Creating a new entity by an
entered key or a server-assigned id, and running with an option, do not.
The modal component itself is agnostic — the parent owns the form — but
every edit session runs through a modal so the merge has one home.

In Vue, React, and Angular the modal also holds the window while its request is
in flight (`busy`; [Vue `HilosModal`](../../../framework/frontend/vue/src/HilosModal.vue),
[React `HilosModal`](../../../framework/frontend/react/src/HilosModal.tsx),
[Angular `HilosModal`](../../../framework/frontend/angular/src/HilosModal.ts)):
user close gestures do not dismiss it, and its body is locked; the parent can
still close it by changing `open`. The Vue default slot, React `children`, and
Angular's fallback `ng-content` sit in an always-present `fieldset` with no
border or spacing, which `busy` disables, so their fields turn natively disabled
and keep what was typed — the same nodes, never remounted. A custom body replaces
only that fallback inside the same `modal-body`: Vue exposes it as `#body`, React
as `body({ busy })`, and Angular uses
`<ng-template #modalBody let-modalBusy="busy">` with the exported
[`ModalBodyContext`](../../../framework/frontend/angular/src/HilosModal.ts).
It receives the same `busy`, and its owner disables its field groups with it.
When the custom body contains a submitting `LoadingButton`, its owner keeps that
button outside those groups so it retains its own look and focus until its
spinner. The footer is not wrapped: the modal disables its own Copy button,
while the owner disables Cancel and other footer actions with the same `busy`.
A footer `LoadingButton` keeps its own `loading`, which is not an independent
`disabled` ([accessibility.md](accessibility.md), "Busy state").
Nothing turns `inert` or `aria-hidden`, so the text, the draft and the refusal
stay readable, and Tab and the initial focus skip what is disabled
([`focusTrap.ts`](../../../framework/frontend/core/src/dom/focusTrap.ts)). When
`busy` changes from true to false while the modal is open and no confirm is
showing, Vue, React, and Angular restore initial focus only from the dialog,
document body or no active element; another focus target stays put. The focus trap,
modal layers and scroll lock stay live.

That is the ordinary modal busy contract. The standard framework own-name flow
is a local exception: its `pending` tracks a session-held operation, including
checking and applying, and must not become the modal's `busy`. Pending blocks
Save and Enter while leaving the name field and close paths available. Keep
step-up busy and the page's independent `interactionBlocked` state separate;
the name operation does not take ownership of either.

### Creation in a known place

Use the core row-edit session when the opening button already addresses the
place to fill and a live carrier row lets the modal follow that place. The
place is known before the user types; its value may not exist yet. For example,
two tabs can open Add for the same missing language name. When one saves, the
other receives that name through its live row and merges it with its draft.

Keep two independent facts: **whether the place is occupied** chooses add or
update; **what the form showed on open** supplies the initial merge baseline,
including an empty field or a catalog prefill. Test record existence explicitly,
independently of the value's truthiness: `''` is a value, not absence. A place
that is empty on open is not a deleted carrier row.

The boundary is a **new entity whose key the user enters or whose id the server
assigns**. That creation has no snapshot to merge against; a key collision
remains a server refusal. Asking for a run's parameter likewise needs no merge.

The existing example is
[`createHilosSettingEdit`](../../../framework/frontend/core/src/admin/settings/hilosSettingEdit.ts):
it follows a catalog key while `overrideValue` starts at `null`, using
`createHilosRowEdit` and the window's field/form projections. It demonstrates
the separation of place and value and the standard session. Its `setting_add`
is idempotent; that property does not extend to other creations.

These windows use, or still need to adopt, creation in a known place; each
implementing leaf removes its own marker in the commit that lands the behavior:

- `createHilosI18nLanguageNameWindow`
- `createHilosI18nCountryNameWindow`
- `createHilosI18nLocaleAdd`

## The modal owns the edit session

The shared entity store always holds the latest **committed** value plus a
version; pages outside the modal always show that committed data. The edit
session — and only the edit session — holds the in-flight state, as three layers:

- **baseline** — initially the values the form actually showed on open,
  including `''` and any reference prefill; the session advances this snapshot
  after automatic acceptance or a user's choice;
- **draft** — an editable clone of the baseline the user changes;
- **incoming** — committed changes that arrive while the modal is open, because
  the edited entity or carrier row stays **live-subscribed** for the modal's
  lifetime. An empty known place is projected as the shown field values, not
  as a missing carrier row.

The place's occupancy is independent of this field snapshot. An empty place
can have a prefilled form; a saved record can have an empty value.

Drafts never smear onto the shared entity ([data-model.md](data-model.md)); other views keep
showing committed data until the user's save commits.

### The per-field three-way merge

For each field, compare against the session's current baseline, starting with
what the form showed on open:

- `userChanged = draft ≠ baseline`
- `serverChanged = incoming ≠ baseline`

| Changes against the baseline | Outcome |
|---|---|
| Neither side changed | Keep the field as it is. |
| Only the user changed | Keep the draft. |
| Only incoming changed | Take incoming automatically, with an update notice and no choice. |
| Both changed to the same value | Keep the matching value, mark it updated, and ask for no choice. |
| Both changed the same field to different values | Mark the conflict, explain "Changed elsewhere", and offer **Keep mine / Take theirs**. |
| The user and incoming changed different fields | Merge those fields automatically. |

An untouched prefill is unchanged and does not conflict with incoming. An
untouched `''` and a user-entered `''` are the same value: if the baseline is
`''`, new incoming replaces either without a conflict. This also holds where
an empty name is valid; validity and record existence are separate decisions.
After automatic acceptance or a choice, the session moves the baseline. Later
incoming compares against that snapshot, not forever against the initial empty
field or prefill.

### Surfacing conflicts

When a field conflicts, the user must know it exists and that it needs a choice:
present both values (theirs and the incoming) and let the user pick. The merge is
per field, so non-conflicting fields stay merged while the user resolves the one
that conflicts.

### The row-edit session

Every edit modal, including creation in a known place, takes the core's
**row-edit session**,
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
  in focus"). A known place's carrier stays live even while the place has no
  record; do not report that initial absence as `undefined` from the source.
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
  `dispose()`: a field only the other side changed lands in the form without a
  choice and the snapshot moves. Matching draft and incoming also move it. The
  snapshot starts with the shown values, then holds the latest saved values
  accepted automatically or through **Keep mine** / **Take theirs**, so a second
  change compares against that, not against what the modal opened with. The
  window supplies projections; the view does not assemble its own merge.
  The optional `creating(live)` projection, false by default, reports whether
  the known place still has no record independently of those field values.
  On the first occupation, the session marks visible fields whose merged
  values match incoming as updated once, including unchanged prefills. It
  consumes that acceptance before applying the ordinary settle step; later
  changes use the advanced baseline and do not repeat the occupation mark.
- **The notice** is one line by precedence deleted › conflict › updated. The
  window gives the conflict's words (`notice.conflict`) and may give its own
  for deleted and updated; the defaults are `HILOS_ROW_EDIT_COPY`. "Updated
  just now" stays exactly while the form shows the value the other side put
  there — typing over it takes the note away, and closing the modal resets it;
  there is no timer. The modal's messages share **one line of room** under the
  fields, taken before there is anything to say (`HilosEditNotice`, per
  [styling-rules.md](styling-rules.md), "The room a live message takes").
- **Can save** is one verdict: the window is open, the carrier row is still
  there, no deletion or conflict blocks it, no save is in flight, and the input
  passes the window's check (`valid` — a name within its bounds, a required
  field filled). A valid creation in a still-empty known place may save even
  when the draft equals the shown baseline, including a prefill or a permitted
  empty name. Once the place is occupied, the draft must differ from the saved
  value. Permissions and domain restrictions still apply: a locale enabled in
  another tab does not become editable. The Save button and the form's submit
  — Enter — read the same verdict; deletion makes the button read "Deleted".
  The lock of the admin view mode stays in the markup (`ConflictActions`); the
  server refuses a viewer's send on its own. `saveAvailable` includes a valid
  still-empty place through `creating(live)`; `canSave` also excludes a save
  in flight. The window supplies this projection to the existing session.
- **One door of the send.** `save(send)` refuses when the verdict says so,
  closes a draft unchanged from an already saved value without a round-trip,
  and otherwise sends, is saving until the outcome, and closes on success —
  the reply of the tracked action for a window over a table. A valid creation
  in an empty place, as reported by `creating(live)`, is excluded from that
  unchanged-edit shortcut and sends even when the draft equals the baseline.
  `saveLanded(send, refusal)` is the same door for a row-backed operation whose
  project answers over the row and not over the action's reply: saving until
  the live fields read as the draft sent — Take theirs in the meantime rewrites
  the draft, not what is waited for — or until the refusal turns non-null. The
  outcome of a save whose window closed in the meantime moves nothing.

**What stays with the window.** The markup; the tracked action
(`useTrackedAction`, `createHilosTrackedAction`), which draws the loading, the
refusal and the toasts, and whose runner the view hands to `save`; the words;
the check of the input; what the form forgets on close (a provider's secret).
The view mirrors the session's signals through its bridge (`useSignal`,
`hilosSignal` / `mirrorHilosSignal`), writes its inputs through `setForm` /
`patchForm`, and passes `state.dirty` to the modal's confirm-on-close. In Vue,
React, and Angular the window also passes the modal `busy` from the session's
`saving`, not from the busy of the tracked action, and disables its footer
Cancel with the same flag. The examples are
[`HilosSettingsPage` (Vue)](../../../framework/frontend/vue/src/admin/settings/HilosSettingsPage.vue)
and
[`HilosSettingsPage` (React)](../../../framework/frontend/react/src/admin/settings/HilosSettingsPage.tsx).
Angular's
[`HilosSettingsPage`](../../../framework/frontend/angular/src/admin/settings/HilosSettingsPage.ts)
mirrors `editor.saving` into `editSaving` for the modal and footer Cancel.

- **Merge** is offered only on a surface where splicing two values makes sense
  (`mergeable`); a typed value — a setting, a channel field, a name — has no
  Merge, only Keep mine and Take theirs.
- The worked examples are `createHilosSettingEdit` and the three settings
  pages, `framework/frontend/{vue,react,angular}/src/admin/settings/HilosSettingsPage.*`.

### Checkpoints and own-save rebase

`checkpoint()` returns detached row-edit data only for an open, cloneable edit:
its baseline (saved values and refreshed-field marks), the shown form, and
whether a known place still awaits its first occupation. It carries no live row,
source, pending save or permission to call a later echo one's own.

`restore(checkpoint, saved?)` is for a caller that has already validated its
serialized shape. Open a new edit on a fresh, present source first; then restore
the checkpoint against that live row. It never opens a window or sends an
action, and a refusal leaves the current edit unchanged. If the caller has
separately confirmed an own save, `saved` can advance the restored baseline
before the first live merge. Take a new checkpoint after a successful restore.

`rebaseAfterSave(saved, checkpoint, publish?)` is a one-use operation for an
own save confirmed by the caller's live correlation. It accepts only a
checkpoint issued by the current opening, advances the baseline to the saved
values and preserves the form as it stands, including a newer draft. The
optional `publish` callback synchronously delivers the trusted own echo before
the regular merge can take it as an external change. It belongs to the live
caller; never serialize or restore a callback or the permission to rebase.
Ordinary `save`, `saveLanded` and conflict handling remain window-bound.

## The standard own-name window is session-held

The framework's standard profile name binding in
[`profileRename.ts`](../../../framework/frontend/core/src/profile/profileRename.ts)
uses the session holder's complete `hilos_profile_name_state`. Unknown state is
not idle; a known `change: null` is. The submitted operation and its checking,
applying or terminal outcome live independently of the dialog. Closing hides
the dialog and writes `open: false`; it does not cancel accepted work. Incoming
state never opens the dialog by itself.

Each tab stores only its own presentation intent in
[`profileRenameMemory.ts`](../../../framework/frontend/core/src/profile/profileRenameMemory.ts),
through `browserStorage` under `hilos.profile.rename.window` and the registered
session `browserValue` labeled `Name change window`: owner id, open flag, step,
draft, edit checkpoint and request id. The strict versioned shape is untrusted
tab data; it does not store a server phase, refusal, permission or step-up
proof. Explicit close writes `open: false`; dispose and F5 preserve the prior
open intent. After authoritative handshake and name state, the same tab and
person may restore its open checkpoint without sending again. A new tab has no
checkpoint to open. Sign-out, an identity change and browser value erase clear
the tab data; inaccessible or invalid storage leaves the live window usable.

The restored edit merges against a fresh live name. A tab may rebase a result
only when its local attempt correlation matches that operation. An already
open dirty tab that merely observes another tab's session attempt keeps the
ordinary `Changed elsewhere` conflict. If the locally submitted name succeeds
while the person has typed a newer draft, success advances the baseline without
discarding that text; it closes the window only if the same submission is still
open and unchanged. Explicitly closed windows stay closed. Manual open shows
the current attempt or refusal, or the current live name when the session is
ready.

All three SDKs keep the name field editable during pending work and gate both
Save and Enter with the same operation verdict. They show `Checking your
name…` only in the checking phase, in a stable `role="status"`,
`aria-live="polite"` slot; an invisible twin keeps the space when it is idle,
and the spinner is hidden from assistive technology. Applying remains pending
without being called a moderation check. These rules apply to the standard
own-name flow; other edit windows keep the modal lock and their dispose-bound
save semantics above.

## Save is authoritative-backend, not Apply

A normal modal save is **submit → loading → backend echo**, not the tables'
pending / Apply mechanism (Apply is tables-only — see
[table-subscription.md](table-subscription.md)). The save emits an action;
frontend state changes only when the backend echoes it
([core-and-connection.md](core-and-connection.md)). Validation is backend-only:
field errors return via the action's `::fail`
([rules-and-violations.md](rules-and-violations.md)). On a successful echo the
store updates and the modal closes. The standard own-name flow uses its
session-held state and owner reply instead of `saveLanded`; its narrow lifetime
exception is described above.

### From Add to Save in a known place

Choose add or update from the place's current occupancy. If another tab fills
the place, the primary action changes from **Add** to **Save**; keep the title
the modal opened with, and let the notice explain the event and its merge
outcome. If the arriving record matches the draft, show an update notice without
a choice, even when the value also matches the opening prefill; there is nothing
to save. A conflict needs a choice before saving. Existing permissions and domain
prohibitions still hold.

An "already exists" refusal protects the instant race between sending add and
the backend writing the record. Keep the modal live and retain the input; let
incoming arrive through the same source and produce the same merge outcome as
any other concurrent creation. Do not blindly retry the save. Success comes
from the backend's answer, not from seeing the place become occupied. For a
new entity addressed by an entered key or a server-assigned id, the collision
remains a refusal; it does not turn the dialog into an edit of another entity.

## Entity deleted while the modal is open

If the edited entity is deleted while its modal is open, the modal **stays
open**, save is **blocked**, and the primary button reads **"Deleted"**. The
user's draft stays visible and **extractable** so unsaved input can be copied out
— it is never silently discarded.

For a user-requested close, show the standard unsaved-changes confirmation when
any projected draft field is not `Object.is`-equal to its value in the last
accepted snapshot (`baseline.values`). If every field matches, close immediately.

This includes a value removed from a known place while it is being edited, even
if its carrier row survives. Do not turn deletion under an open edit back into
creation. A place that was empty on open is a different case; deletion of its
carrier row still blocks saving.

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
