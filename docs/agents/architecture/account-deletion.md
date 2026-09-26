# Account Deletion

Read this before touching a person's own account deletion: the request and its
grace period, the window that starts it, the erasure when it falls due, or the
seam through which a project erases its own rows of the person (HIL-302).

## The Request

`hilos_account_deletion` holds one row per request: `requested_at`,
`effective_at` fixed when the request is made (now plus the grace period in
force), and two endings, `canceled_at` and `completed_at`. A request is live
while both are null. Both endings are written by a conditional update carrying
that condition (`AccountDeletion::cancel()` / `complete()`), so a
"Keep my account" pressed in the minute the erasure runs and the erasure itself
race in the database and exactly one wins. A carried-out row stays after the
erasure: the number of an account that no longer exists and three dates are
the trace that it was erased on request.

The users library owns the table: its commands start and cancel. The session
holder marks a request carried out under a borrowed `Update` claim, because it
is the one that erases.

## Starting And Calling It Off

Four signed-in actions of the users library (`AccountDeletionCommands`):

- **open** answers the grace period and where the code goes;
- **code** sends a code of type `account_deletion` (mail) or
  `account_deletion_sms` (phone) to that address;
- **start** checks and spends the code and records the request;
- **cancel** calls it off.

The first three open with the `delete_account` confirmation
([step-up.md](step-up.md)) and read the account again: a deletion already
scheduled refuses them. The operation opens itself with a code to the address,
so an account whose only proof is that address is not asked twice — the gate
passes it and the code of the window is the proof. The address is chosen by
`StepUpMethodResolver::resolveAddress()`, the one rule the step-up uses too; an
account no code can reach starts without one.

Cancel stands outside the gate on purpose: changing one's mind must be easier
than deleting, and a frozen account must be able to do it. It refuses only an
impersonated session (`StepUpGate::isImpersonated()`).

Every start and cancel fans `hilos_account_deletion_state` to the person's
group (`AccountDeletionGroup`); the profile page that draws the danger zone
carries the same state as its `accountDeletion` section and joins the group.
The frontend reads both through `createHilosAccountDeletionStore`, and the
window's steps are `createHilosAccountDeletionFlow` in the core, drawn by
`HilosAccountDeletion` in each SDK.

The grace period is the setting `auth.account_deletion.grace_days` — 30 by
default, 1 to 365 (`AccountDeletionSettingsCatalog`, folded into the project's
catalog).

## The Erasure

Once a minute, where a sign-in exists, the session holder sweeps the due
requests (`AbstractSessionsLibraryAgent`). Each account is erased in ONE
transaction:

1. the request is marked carried out; lost to a cancel — rolled back, nothing
   touched;
2. the framework's rows of the person go: device keys before ways in, codes,
   the second factor whole, operation confirmations;
3. the project's seam `applyAccountErasure(int $userId): AccountErasure` deletes
   the project's rows.

Any failure rolls all of it back; the request stays live and due, and the next
minute tries again. Half an erased account never exists.

Tests can bring a request due with `test:account:force-purge <userId>`: the session
holder moves its moment to now and erases it through this path, returning the
project's tally. A failed erasure leaves the request due for the next sweep.

After the commit, outside the transaction: every session the person stands in
is signed out — signed in as them, taking over somebody else's account, or
waiting on their second factor; the files the project named are removed from
disk (a file that will not go is logged as an orphan, not retried); and
`Hilos::$notify->forgetUser()` sends `hilos_notification_forget_user` to the
notifications library, which deletes the person's notifications with their
journal, preferences and push subscriptions. That is a frame and not a write
here because the notification feature is not mounted everywhere; a lost frame
leaves rows of nobody. A failure after the commit is logged and not retried: the
request is carried out, and no sweep comes back for it.

## The Project's Seam

`applyAccountErasure()` refuses by default (`NotImplementedException`), like the
merge's seams: a project that forgot to erase its rows hears it when the first
account falls due. An implementation deletes EVERY row of its own that belongs
to the person — the person's row last, since the others point at it — because
that is what a published privacy text promises; where the person is only
mentioned in somebody else's row, the mention goes and the row stays. It runs
inside the transaction and under the holder's own claims, so each table it
writes needs a borrowed claim on the project's session holder. Files are
named in the answer, never removed in the seam.
