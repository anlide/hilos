# Account Deletion

Read this before touching a person's own account deletion: the request and its
grace period, the window that starts it, the erasure when it falls due, or the
seam through which a project erases its own rows of the person (HIL-302).

## The Request

`hilos_account_deletion` holds one row per request: `requested_at`,
`effective_at` fixed when the request is made (now plus the grace period in
force), `started_at` (the moment the person's agent began the erasure, null
before it; HIL-1939) and two endings, `canceled_at` and `completed_at`. A
request is live while both endings are null, and a begun request stays live
until its own end. The endings and the start are written by conditional updates
carrying that condition (`AccountDeletion::cancel()` / `complete()` /
`start()`); cancel and start carry one more, that `started_at` is still null. So
a "Keep my account" pressed in the minute the erasure runs and the erasure
itself race in the database and exactly one wins, and so do "Keep my account"
and the start. A carried-out row stays after the erasure: the number of an
account that no longer exists and its dates are the trace that it was erased on
request.

The users library starts a request. The person's agent cancels it, whether the
person or an administrator asked (HIL-1409). The session holder marks a request
carried out under a borrowed `Update` claim, because it is the one that erases.

`completed_at` now records the personal owner's final transaction, after all
required results and deletion of its person row. The detailed
[erasure canon](account-operations.md#erasure) separates the conditional start
at that owner from the final. A start records `started_at`. When force-purge
asks for an immediate start, the personal owner also moves the erasure moment
to now in that transaction. A due start leaves its already-due date unchanged.
Deferred opening does not move the date; if the start
transaction rolls back, the date, start and
participant result roll back while the durable operation remains open. A later
participant refusal after the start committed leaves the date in place and the
operation unfinished. The personal owner's final transaction deletes its merge
row, rename journal, capture and person row and records `{"request":<id>|null}`;
a `RESTRICT` refusal rolls back only that final transaction and keeps a safe
reason on the open operation ([*The erasure final*](account-operations.md#current-implementation)).
The start and final are live in HIL-1952 (prepared in HIL-1939 and HIL-1942).
HIL-1936 supplies the operation and assignment carriers without a separate
start field. The cancel/due race and retained request trace remain.

## Starting And Calling It Off

Four signed-in actions of the users library (`AccountDeletionCommands`):

- **open** answers the grace period and where the code goes;
- **code** sends a code of type `account_deletion` (mail) or
  `account_deletion_sms` (phone) to that address;
- **start** checks and spends the code and records the request;
- **cancel** calls it off: the library judges it, the person's agent writes it.

The first three open with the `delete_account` confirmation
([step-up.md](step-up.md)) and read the account again: a deletion already
scheduled refuses them. The operation opens itself with a code to the address,
so an account whose only proof is that address is not asked twice — the gate
passes it and the code of the window is the proof. The address is chosen by
`StepUpMethodResolver::resolveAddress()`, the one rule the step-up uses too; an
account no code can reach starts without one.

Cancel stands outside the gate on purpose: changing one's mind must be easier
than deleting, and a frozen account must be able to do it. It refuses an
impersonated session (`StepUpGate::isImpersonated()`) and, in the words of
`AddressablePerson::require()`, an account that is gone, was merged, or whose
erasure has begun ("This account is being deleted.") — before the hop, or at the
person's agent when the start overtakes a cancel on the hop. A refused cancel
fans no `hilos_account_deletion_state`. Standing outside the gate also makes it
an exit of the freeze: the users library lists it, and only it, in
`FROZEN_EXIT_ACTIONS`, while open, code and start are closed to a frozen person
like every other `AUTH_ACTIONS` name
([account-standing.md](account-standing.md)).

Every start and cancel fans `hilos_account_deletion_state` to the person's
group (`AccountDeletionGroup`); the profile page that draws the danger zone
carries the same state as its `accountDeletion` section and joins the group.
The frontend reads both through `createHilosAccountDeletionStore`, and the
window's steps are `createHilosAccountDeletionFlow` in the core, drawn by
`HilosAccountDeletion` in each SDK. Away from the profile the shell says the
same from the session's standing: a strip under the navigation with the date and
the days left, whose "Keep my account" is this cancel, and a mark by the header
avatar ([account-standing.md](account-standing.md), *Who Draws What*).
The window's code-sent step lives in the session ([step-up.md](step-up.md),
*A window's step lives in the session*); its "Deletion in progress" view follows
the person's scheduled deletion state.

The grace period is the setting `auth.account_deletion.grace_days` — 30 by
default, 1 to 365 (`AccountDeletionSettingsCatalog`, folded into the project's
catalog).

A live request keeps the duration implied by its two saved server moments.
`AccountDeletion::deletionGraceDays` is a computed, read-only View value:
`max(1, ceil((effectiveAtMs - requestedAtMs) / DAY_MS))`, with the stored SQL
moments converted to milliseconds. This is not a column, migration, or
snapshot of the current setting; the ceiling preserves the original whole-day
duration at SQL second precision. A change to the setting affects later
requests, not the cap of one already live. Canceling and scheduling again
creates a request with a new cap.

The profile derives that cap from the `requestedAt` and `effectiveAt` it already
receives, through the shared pure `accountDeletionGraceDays()` helper; its
`accountDeletion` state remains those two fields, with no third wire field. Its
days-left display is
`min(N, max(1, ceil((toLocal(effectiveAt) - now) / DAY_MS)))`, where `N` is
that request's derived duration. The absolute date and raw moments do not
change. Legal re-consent still uses the existing two-argument, uncapped
`accountDeletionDaysLeft()` path.

The admin user card schedules the same request and grace period through
`scheduleFor()`, and cancels it through the person's agent. The card is
answered after the write. Both publish the same state to the person; either
the administrator or the person may call it off.
Scheduling asks the administrator's fresh confirmation first (the step-up
operation `delete_other_account`, see [step-up.md](step-up.md)); calling it off
asks none. Once the erasure has begun, the card's cancel is refused in the same
words as "Keep my account" above — before the hop, or at the person's agent —
and fans no `hilos_account_deletion_state`.

## The Erasure

Once a minute, where a sign-in exists, the session holder sweeps the due
requests (`AbstractSessionsLibraryAgent`). A due request erases its named account
and every account folded into it, including folded accounts down a chain. Those
accounts belong to one person (owner decision, 30 September 2026). The holder
gathers the entire circle first and erases it from leaves to the named account
in ONE transaction:

1. the request is marked carried out; lost to a cancel — rolled back, nothing
   touched;
2. for each folded account, a live request of its own is marked carried out;
3. for each account, the framework's rows go: device keys before ways in, codes
   — those carrying the person's id and those on their current addresses,
   whatever user they carry (HIL-1163),
   the second factor whole, operation confirmations, legal acceptances, the
   [access log](access-log.md) (HIL-1174), and the account's merge row when it
   was folded into another one (HIL-1199). Notification deliveries, notifications,
   channel preferences and push subscriptions leave where their tables are active.
   Orders for the person's data copy and their legal acceptance export leave too.
   A published profile photo row leaves where that optional table is active;
   its registry file id joins the post-commit removal.
   Every session signed in as the person or run by them under an impersonation is
   signed out, waits on their second factor are cleared, and cards about their
   closed access are lowered (HIL-1202);
4. the project's seam `applyAccountErasure(int $userId): AccountErasure` deletes
   its rows for that account;
5. the framework removes the account's rename journal rows, then its person row.

Erasing the survivor erases the accounts folded into it: it is one person.
Erasing a folded account under its own earlier request erases it and the
accounts folded into it, leaving its survivor alone. The leaf-first order
removes each merge row while its survivor still exists; the merge table's
`SET NULL` does not run in this erasure. Folded accounts receive no new request:
the named account's completed request and the erasure log record the operation.

Any failure rolls all of it back; the request stays live and due, and the next
minute tries again. Half an erased account never exists.

The target obligations, owners and order are detailed in
[Erasure](account-operations.md#erasure). Each participant commits its work and
result in its own transaction; a refusal rolls back only that owner's current
work, while earlier results remain durable and the open operation is resumed
from its saved facts. The fixed circle and folded-first ordering are now used
by the live erasure path. The final personal delete follows required results
and uses `RESTRICT` as the last safeguard; a refusal leaves the operation open
with a safe reason, the account's number and the referencing table's name.
The canon names the personal, library, channel, export, sessions and project
portions individually, including their saved anchors and post-commit limits.
The due sweep and force-purge use these owner-local portions from HIL-1952;
that switch retired the old erasure calls, borrowed rights and debt while
keeping rights the old merge still needs.

Tests can bring a request due with `test:account:force-purge <userId>`: the
session holder asks the personal owner to start it and waits on the resulting
operation. The owner moves its moment to now only when a successful start
commits; a deferred opening does not change the date, and a rolled-back start
leaves the durable operation available for recovery. A later refusal leaves
the committed date in place. The holder reports `completed`, `starting`, or
`erasing` from the observed operation; an unanswered wait has an unknown
outcome, and the operation continues. The existing wait window is unchanged; a
large RT snapshot (HIL-1817) can consume it. Run the command again to observe
the same durable operation.

After the commit, the agent of every account in the circle stops itself, on
whichever node it was up. One that was not up does not rise.

After the commit, outside the transaction: the registry files the project and
the framework photo row named go to the files library in one
`Hilos::$files->remove()` when the project declares `FILES`
([files-registry.md](files-registry.md#removing)) — nothing is deleted from disk
past the registry. Export agents receive forget frames to remove files whose
orders the erasure already deleted. A failure after the commit is logged and not
retried: the request is carried out, and no sweep comes back for it.

`DataExportNotifier::forgetUser()` also queues `hilos_data_export_forget_user`
for each account to the export owner after the commit, removing files with no
ready order. A builder checks the retained completed-erasure row before
publishing; see [data-export.md](data-export.md).

`LegalAcceptancesExportNotifier::forgetUser()` queues
`hilos_legal_acceptances_export_forget` for each account to the legal agent,
where the project registers one (HIL-1234). Which person is in which
administrator's file of acceptance records is not known, and the records of an
erased account go with it, so every ready and failed export is removed with its
file and the export being built starts again; see
[legal-documents.md](legal-documents.md), "Export of acceptances".

## The Project's Seam

`assertAdministratorMayDelete()` judges the admin card's target before
scheduling. The framework refuses, in this order, an account that does not
exist, an administrator (`Remove the admin rights first`), and an account folded
into another one (`This account was merged into another one`, HIL-1199). A
project with a refusal of its own overrides it and calls the parent first.

`applyAccountErasure()` refuses by default (`NotImplementedException`), like the
merge's seam: a project that forgot to erase its rows hears it when the first
account falls due. It is called for every account in the circle. An implementation
deletes EVERY row of its own that belongs to that account; where the person is
only mentioned in somebody else's row, the mention goes and the row stays.
The framework deletes the rename journal and person row after the hook; the
journal is still readable during the hook. It runs inside the transaction and
under the holder's own claims, so each table it
writes needs a borrowed claim on the project's session holder. Files are
named in the answer by their registry ids (`AccountErasure::$fileIds`), never
removed in the seam: a file gone from disk cannot come back if the transaction
rolls back, so the library removes them after the commit.

That is the seam today, and a project written today writes it. Under the
target [participant boundary](account-operations.md#erasure), a project names
its own owners, each deleting its rows with a result. A table never activated
owes no participant; existing rows with no owner remain visible unfinished
work, with no fallback writer. The project names its owners and the tables it
leaves out in its `AccountDataOwnerDirectory` (the facade's
`ACCOUNT_DATA_OWNER_DIRECTORY`); the class registered under an owner's type
implements `AccountErasureProjectOwner` and carries its part through
`AccountErasureProjectStep`; and the daemon refuses to start over a mounted
table that no owner covers and no exemption names
(`AccountErasureCoverageGuard`). The directory, gate and owners' parts are live
in HIL-1952. The switch retired the erasure seam's former borrowed write
rights; rights still needed by the old merge remain until its separate cutover.
