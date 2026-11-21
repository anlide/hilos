# Second Factor

Read this before changing anything about two-step verification (HIL-494): the
gate a proven sign-in passes, the wait it is held in, the trust a browser earns,
the backup codes, the delayed removal, and the ceiling on wrong app codes. The
code lives in `framework/backend/Auth/SecondFactor/`, the sign-in half in the
two libraries (`AbstractSessionsLibraryAgent`, `AbstractUsersLibraryAgent` with
`Command/SecondFactorCommands.php`), and the browser half in
`framework/frontend/core/src/auth/authFlow.ts` and `profile/secondFactor.ts`.

## The gate stands at the session holder

Every way of proving a person ends at the session holder, and the gate stands
there, before `authenticateSession()`: a password, a phone code, a link and its
code, a device key, a provider trip (live tab, no record, held grant), and the
new password of a recovery. `SecondFactorGate::verdict()` answers `pass`,
`verify` (a confirmed app and no live trust of this browser) or `setup` (no app,
and the policy requires one of this person). The fourth answer is the
installation's: a second factor the administrator turned off
(`auth.second_factor.enabled`, HIL-1303) answers `pass` to every proof, with no
code and no enrolment, so a recovery by mail lets the person in at once.
Registration, `admin:create` and impersonation do not pass the gate: the first
has no factor yet, the other two are the operator's.

A grant that already carries the proof (`secondFactorProven` on the grant frame)
skips the gate; that is how the code step, and the end of an enrolment on the
way in, let the person through.

## The wait lives on the session row

A held sign-in is five columns of `hilos_session` (`pending_second_factor_*`:
person, mode `verify|setup|setup_done`, deadline, attempts, the ack to show
afterwards). Only the holder writes them — the users library sends frames
(`missed`, `setup_proven`, `off`, `cancel`) and never touches the row. The
handshake reports the wait as the `second_factor` / `second_factor_setup` step,
naming nobody; a wait that ran out is reported once as the identifier step with
`second_factor_expired`. Every tab of the browser is told the step as it
changes, and the core machine follows it (`followReportedStep`) unless the tab
has a submit of its own in flight.

## What is stored, and who owns it

These are current grants and calls. HIL-1999 retired the sessions holder's
borrowed merge writes; merge and erasure changes now run at the row owners.

| Table | Writers and operations today | What |
|---|---|---|
| `hilos_second_factor` | person's agent `Add`/`Update`/`Remove`, including merge transfer and erasure | apps: base32 secret, last accepted step, confirmed or not |
| `hilos_second_factor_backup_code` | person's agent `Add`/`Update`/`Remove`, including merge transfer and erasure | one row per code, spent by a conditional write |
| `hilos_second_factor_reset` | person's agent `Add`/`Update`/`Remove`, including merge and erasure | removals: when asked, when due, the cancel token, last notice |
| `hilos_second_factor_setting` | person's agent `Add`/`Update`/`Remove`, including merge transfer and erasure | the person's removal wait and a shorter one not yet in force; the app-code miss count, its window, the lock step and its end |
| `hilos_second_factor_trust` | person's agent `Add`/`Update`/`Remove` for grant-or-renew, merge and personal policy | a browser (session row) trusted for a person until a moment, and the moment it was last granted |
| `hilos_second_factor_trust_restriction` | settings library `Add` in the transaction of the save that shortened the term or turned the factor off; no `Update` or `Remove`; the holder and the person's agent read it | one shortening: the save moment and a term of 0 to 365 days; trusts granted no later than the save live no longer than the save plus the term, and 0 takes them out |

The personal edits moved in HIL-1406 are written by the person's agent
(`AbstractUserAgent`, through `SecondFactorPersonEdits`). The users library
carries the request and consumes the owner's done answer; it does not create
ordinary enrollment, backup-code or reset rows.

Before creating secret-bearing material, the personal owner checks that the
person is addressable and has no active merge reservation. Enrollment replacement
removes unfinished enrollments and creates the new factor in one transaction.
The done answer carries the committed secret and URI; a failure rolls back both
the removal and creation, preserving the earlier unfinished enrollment.

Replacing backup codes is likewise owner-local and atomic: the owner deletes the
old set and creates its replacement in one transaction. Display codes are sent
in the done answer only after commit. A failure rolls back the replacement and
preserves the previous set.

A reset request and its cancellation token are created together by the personal
owner. The owner commits before sending the reset notice; the done answer carries
the committed request id and effective moment. A failed transaction leaves no
new reset request and sends no notice.

The secret, code and token written immediately after their new row's insert
remain completion of creation. The person's settings row is the agent's to
create, born of its first edit - a wait chosen, a wrong code counted (owner's decision,
2026-10-09); the library only reads it. Merge and erasure are the session
holder's, below.

Every race is judged by the database: a code step is taken with
`last_used_step < ?`, a backup code with `used_at IS NULL`, a removal is canceled
or carried out with `canceled_at IS NULL AND completed_at IS NULL`.

**Backup codes are stored as they are, not hashed.** The app secret has to stay
readable for the server to compute codes, so a dump of the database already
opens the factor; hashing the codes would add work and protect nothing.

**The cancel token of a removal is stored as it is, not hashed.** Same reason:
a copy of the database already holds the app secret, so it already opens the
factor, and the link only cancels a removal. Hashing it would leave a reminder
with nothing to repeat. The token stays out of the object, the view and the
browser. The first notice and every daily reminder carry the same link.

## Account merge

The administrator chooses the folded account's protection in the merge window;
`account:merge --second-factor=survivor|both` makes the same choice. With no
confirmed app on the loser, no choice is sent. With only the loser protected,
only an explicit transfer is allowed. With both protected, keep the survivor's
apps and codes alone, or accept either account's apps and unused backup codes.
The survivor never loses its existing factor.

The session holder moves confirmed apps and backup codes, preserving spent
status, in the merge transaction. It removes the loser's unfinished enrollment,
reset history, personal setting and trusts. A live removal on the loser refuses
the merge; adding its protection also refuses a live removal on the survivor.
The longer effective removal wait survives and pending shortening is cleared.
All five sets are locked without waiting before the transaction rereads them;
a busy set refuses the operation. Browser requests also carry the two presence
flags shown in the summary, and a mismatch refuses without writing.

The sessions holder no longer claims these tables for merge or erasure writes.
HIL-1952 retired its erasure rights and HIL-1999 retired its merge rights; the
person's agent owns its rows, including the wait created and edited by that
agent (HIL-1406). The detailed
[erasure](account-operations.md#erasure) and [merge](account-operations.md#merge)
canons give each prepared capability its own address. For merge, each owner
checks its protection, shown flag and pending-removal refusals at preparation
(built; the live merge coordinator sends these duties since HIL-1999).
Reservation guards cover the
existing personal protection mutations and affected policy entrances, including
late service frames (built, HIL-1989). Saved trust policy still limits reads;
startup and service application defer the material writes while this account is
reserved, preserving debt for startup, await or the next ordinary pass after its
own release. Ordinary proof before the give fact still spends backup codes,
checks replay and counts wrong codes, without canceling a reserved reset.
The personal owner now creates enrollment, replacement codes and reset requests
after addressability and reservation checks; each replacement is atomic, and its
done answer carries only committed material. The reset notice follows commit.
New sign-in grants recheck the saved give fact before binding, including OAuth
no-trip, live, held and resumed paths; survivor sign-in remains refused through
receipt even when DB sync was missed. Grant-or-renew has no holder fallback.
These guards and the owner-driven merge are live since HIL-1999.
The loser transfers or deletes its factor
and backup codes by fate, preserving spent status, and removes its other
second-factor rows with its nonsecret result and separate private copy in the
[prepared give](account-operations.md#the-prepared-loser-give), sent by the merge
coordinator, live since HIL-1999. The [prepared survivor receipt](account-operations.md#the-prepared-survivor-receipt)
writes the longer saved-source/current-own effective wait for `BOTH`, clears
pending shortening and preserves app-code misses and lock state. Other fates
leave settings untouched. It deletes its own trusts when the loser's
preparation captured a confirmed factor, under the existing policy. These own
writes and the exact `go`/`receive` result commit in one participant transaction.
Warm receive uses applied memory; local startup resumes an existing obligation
from current mapped own rows, including a new owner in the same worker.
The live hand-over transport, coordinator, and DB recovery continue these
owner duties. HIL-1999 connected the administrator and CLI callers to them.
The prepared holder `go/sessions` duty ends loser
sessions after the exact give; `go/survivor_sessions` ends survivor sessions
after the exact receipt only when the saved give retained the loser's factor.
Each commits its strict `{"sessions":<n>,"accessLog":<m>}` result with its
work and publishes session/profile notifications only after commit (HIL-1995).
These duties are part of the live merge path from HIL-1999.

The loser's sessions end after the prepared duty commits. When its factor first
protects the survivor, the survivor's sessions end after receipt too. A
confirmed loser factor revokes trusts of both accounts; otherwise only the
loser's trusts go. The survivor's profile receives its updated section after
commit. Merge tables expose only
confirmed-app presence, hidden from an admin-view-mode viewer, never secrets,
codes or app labels.

## Trust

"Don't ask again on this device" writes a trust row for (session row, person)
until now + the administrator's days (0 offers no trust). The row also keeps the
moment it was last granted: a new pair stores it with its creation, and a
renewal by the person's agent moves it to the moment the second factor was
confirmed again (HIL-1907), while the first writing stays. The session row
survives token rotation, so a trusted browser stays trusted; signing out does
not end the trust, switching the factor off and a carried-out removal do.
Trust does not remove a protected operation's fresh confirmation; step-up asks a
different question and deliberately ignores trust ([step-up.md](step-up.md)).

The gate checks the current trust term as well as the row: 0 never accepts an old
trust, and a row ending later than now plus the current term waits on the code
until the session holder applies a shorter term. A saved shorter term caps only
trusts that were live when it was saved, using that save moment; it never extends
an earlier expiry. Saving 0 takes every trust away, including expired rows.
Raising the term later does not recreate or lengthen a trust. The settings
library sends the reduction to the session holder with the id of the restriction
below that the save wrote (HIL-1908). The holder writes nothing: it waits for
the sweep to bring that restriction to every person's own agent
(`SecondFactorTrustRestrictionSweep::await()`, below) and answers the
administrator by the result. Nothing owed is success; N trusts still owed is a
refusal ("The trust term is saved, but N trusted browsers are not cut to it yet.
Sign-in holds them to it already; save the same term again to finish."), and
the term stands either way. Saving the same term again sends the id of the
latest restriction, so a repeat finishes what a pass left; with no restriction
saved at all the answer is success at once and no frame is sent, and a longer
term answers at once and extends nothing. The answer may come after the
browser's 30 s wait - with 100 or more people holding a trust, with the save
made in the middle of a pass already running, or with a silent owner - and the
administrator then sees "The action timed out." while the term is saved and
already holds at sign-in; the owner took that price on 13.11.2026.

Each shortening is also kept as a restriction (HIL-1900): a row of the save
moment S and a term of N days, 0 to 365, meaning that trusts granted no later
than S live no longer than S + N days, and that N = 0 takes them out. The
settings library writes it in the transaction of the save, at all four of its
doors — a write, a reset to the default, the removal of an orphan row, a
preset — from the screen and from the console. A term saved shorter than it stood
records `(S, new term)`; so does a term written over one that could not be read,
taken as shorter. The factor turned off while `forget_trusts_on_disable` is on
records `(S, 0)`. A longer term, the same term again, the factor turned on and
the factor turned off with trusts kept record nothing. When the settings cannot
be read after a write that touched the term or the switch, or the restriction
cannot be written, the save is taken back with the restriction and refused in
the words it already used.

A restriction touches a trust only when the trust was last granted no later than
S, so a trust granted again afterwards is not touched. The gate reads the
restrictions from the table, not from memory, and counts one before any person's
agent has applied it: a trust under a restriction whose limit has passed is not
trusted, whatever its row still says, and the limit of a 0-day restriction is S
itself, so such a trust never passes again. Raising the term from 7 days to 30,
or turning the factor off and on again, therefore does not bring back a trust a
shortening already ended, even when the person's agent has not yet applied it.

Turning the second factor off for the installation (HIL-1303) forgets every
trust when `auth.second_factor.forget_trusts_on_disable` says so — the same
erasure a term of 0 makes, with the same 0-day restriction recorded in the
write. The people's agents forget their trusts on that restriction, and the
administrator's request succeeds when nobody owes any trust any more, otherwise
it is refused with the sentence described under Policy below. When it says no,
the trusts live to their own expiry, and turning the factor back on finds them: a trusted browser
still skips the code step until its trust runs out.

Password recovery and a profile password change revoke trust of every other
browser, including browsers already signed out; the current browser is kept by
its durable session row id across token rotation. Choosing to end other sessions
controls those sessions, not whether their trust is revoked. Ending one session
revokes that browser's trust for the person; ending all other sessions revokes
all their trusts. Blocking a person removes every trust even when no session is
currently signed in. Unblocking does not restore one. Ordinary sign-out keeps
the trust until its expiry or an explicit revocation.

Who writes each of these today (HIL-1407). The personal owner creates or renews
every ordinary pair through `grantOrRenew()` on
`hilos_user_browser_trust_renew` (HIL-1989, below). The session holder has no
ordinary `Add`, insert or re-grant fallback; its remaining trust write is
`Remove` for the retained merge and erasure paths. It no longer cuts or removes
trusts for the administrator's term or on switch-off: that is the people's
agents' work on the sweep's frame (HIL-1908 and HIL-1909, below). Taking one
person's trust away is already that person's agent's, in the turn of the write
that causes it:

- switching the factor off and a carried-out removal - in the transaction that
  takes the factor out (`SecondFactorPersonEdits`); the holder's `off` frame only
  lets go the browsers waiting on a code;
- a changed or recovered password - the agent takes the trust of every browser
  but the one named in the ask FIRST and writes the new hash SECOND. Taking the
  trust away commits on its own and the framework does not nest transactions, so
  the order is the guarantee: a failure of either half refuses the password as
  not changed, and no moment exists where the password is new while the other
  browsers still pass without a code;
- a block - in the transaction that writes the flag; lifting it writes the flag
  alone;
- ending one session or all others - the holder ends the sessions and answers the
  browser, then asks the agent on `hilos_user_browser_trust_revoke`, and only logs
  a refusal.

### Trust Writes After The Ordinary Moves

Every sign-in that asks to trust the browser sends the proof moment and requested
expiry to the personal owner (`hilos_user_browser_trust_renew`). The owner checks
that the person is addressable, has no merge reservation, the session still
belongs to that person and is live, a confirmed factor exists, current policy
allows trust, and the password has not changed since the proof. The holder never
inserts or re-grants a trust as fallback. The browser has already been answered:
trust is optional and sign-in does not wait on its result.

`grantOrRenew()` adds an absent pair or renews a standing one in one transaction,
applies saved restrictions, and preserves a later grant already written. The
owner answers whether the pair stands; an owner refusal or a false result is
logged without a holder-side insert or retry. The confirmation moment is stored
instead of the owner's write time. It comes from the holder's clock while a
password change moment comes from the owner's clock, so a cluster clock skew can
still affect their ordering.

The saved policy needed to apply a shorter term is retained, as the restrictions
above (HIL-1900); the sessions holder's sweep and the owner's start recover
missed application work (HIL-1901). Renewal and recovery are ownership and
recovery obligations, not a declaration of new DB fields here.

The person's agent applies the stored restrictions on a service frame,
`hilos_user_second_factor_trust_restrict`, which names the person, the
restriction and the signal the answer is sent under; the sender defines that
signal. The frame is answered every time, and neither the person's standing nor
a merge refuses it: a folded account applies it and stops again, and ordinary
access stays closed to it. In one transaction the agent refuses a restriction
that is not stored ("Trust restriction N is not saved") and writes nothing;
otherwise, for each of the person's trusts, one under a restriction of 0 days is
deleted and any other trust under restrictions is cut to the earliest limit when
it runs longer. Applying again, or in another order, changes nothing more. The
answer is ok when, at the moment of the owner's commit, no trust of the person
violates a restriction up to the one asked for, a trust violating one that
applies to it when its term is 0 or it runs past its limit; the same test tells
the sweep of HIL-1901 which work is unfinished. Today the sweep is what sends
the frame; the switch-off below sends it through the request of the next
paragraph (HIL-1909), and so does the term (HIL-1908).
The owner-only grant path closes the old holder-insert race with a concurrent
revocation. A merge also refuses a browser-trust renewal when the latest merge
record naming that person was created at or after the grant moment. A merge
that was canceled or did not change trust can therefore still require a fresh
second-factor proof. One gap remains: clock skew inside the window can let a
password change that follows confirmation by less than the skew carry the
earlier moment when the agent's clock runs behind. This merge check does not
close that clock-skew gap.

Missed application is found in two places (HIL-1901). The sessions holder
sweeps for it: the framework cron rule
`hilos_second_factor_trust_restriction_sweep` runs every minute from the
holder's start, only where the project has a sign-in surface (AUTH), and its
first tick is at once. A person owes when one of their trusts was granted no
later than a saved restriction and that restriction cuts it: its term is 0, or
the trust runs past the restriction's moment plus its term. The sweep asks the
database afresh for such people in rising id order, after the last one it
reached, and sends each the frame above with the latest saved restriction and
`hilos_user_second_factor_trust_restrict_done` as the answer signal. No more
than 100 people are in flight at once, and nobody is sent a second frame while
one waits. With no restriction saved it asks nothing. An answer outside the
flight is dropped quietly; a refusal and a frame unanswered for a minute (or
whose agent is gone) each leave one warning in the holder's log, naming no
session, and leave the flight. A new pass starts by itself only when none has
run yet or an hour has passed since the last one ended, so a minute with
nothing to do writes nothing and asks nothing, and an owner that keeps refusing
is asked once an hour. The sweep's state is the holder's memory alone: it dies
with the holder, and a new holder begins from its first tick. Two holders
during a move may both send frames, which is harmless because applying twice
changes nothing more. Existing restriction integration covers the move;
failover 4/19 is recorded, and cold person 39 remains quarantined as F-015.
No broader cluster evidence is claimed here.

The holder also keeps an internal request for the term and the switch-off to
wait on (`SecondFactorTrustRestrictionSweep::await()`), "finish the restrictions up to R, then tell me": it starts a pass at
once when none runs, skips the hour's pause, answers at once when nobody owes,
and otherwise answers at the end of the pass with the number of trusts still
violating those restrictions, 0 meaning done. It has two callers: the switch-off
(HIL-1909) and the term (HIL-1908). A first portion that fails to send is logged
as a warning and the answer still comes at the end of the pass. A holder that
stops drops what waits, and no answer comes.

The person's agent does not wait for the sweep. At start, whether it is the
sweep's frame that woke it or not, it applies every saved restriction to the
person's trusts in one transaction, with no frame and no answer, when the
person owes; a folded account applies them too, and a person with no violation
gets no write. A failure there is logged as an agent error, the start goes on,
and the sweep asks again. The frame of the sweep that woke the agent then
arrives after the start, changes nothing, and is answered ok.

The personal owners apply the saved cap, or remove their rows for a zero term,
on the frame of the sweep that the term asked for (HIL-1908). They also forget their trusts when installation
switch-off calls for it, on the frame of the sweep that the switch-off asked for
(HIL-1909). The saved-moment rule, no extension of an earlier expiry, current
gate checks and browser outcomes above stay requirements. HIL-1952 retired the
holder's erasure removals and HIL-1999 retired its merge terminal removals.
The personal owners apply the saved cap, or remove their rows for a zero term,
on the frame of the sweep that the term asked for.

## The delayed removal

With neither an app nor a backup code, a person asks a removal — from the code
step or from the profile. It takes effect after the person's wait (a shorter
wait they chose waits out the wait in force first; the administrator bounds it,
floor 1 day). The users library sweeps once a minute, and the sweep only finds:
each due removal and each one owing its daily reminder goes to the person's agent
as a frame (HIL-1406). The agent carries a due removal out in one transaction - a
conditional mark that it is done, then the apps and the codes go - so a cancel
that won the same minute leaves the factor, and the frame sent again by the next
tick does nothing; on its answer the library tells the session holder, which
drops the trusts, and mails the person. A reminder is marked first, by a
conditional write the same day cannot repeat, and mailed on the answer: a letter
that fails after the mark loses that day's reminder, rather than a failed mark
sending one every minute. The reminder carries the same "it was not me" link as
the first notice (`HILOS_SECOND_FACTOR_CANCEL_URL`, route
`/auth/second-factor/cancel`). Every notice is a mandatory notification type on
every channel, and the link cancels the removal without signing in - the library
finds the removal by its token, the person's agent cancels it. Any accepted code
on the code step cancels a standing removal too — whoever shows the factor has
not lost it.

## The app-code ceiling

Wrong app codes are counted per person, on their row of
`hilos_second_factor_setting`, wherever the code is typed (HIL-1285): the code
step of a sign-in, showing and renewing the backup codes, disconnecting an app,
proving a connected app before adding another, and confirming an operation by
the second factor. All of them are one check, made by the person's agent in the
turn that writes (`SecondFactorPersonEdits`, HIL-1406): the code is the write - a
step taken, a backup code burned, a miss counted, the lock put - so one writer of
the person's set writes the count. The confirmation of an operation asks the agent
of whoever confirms it, an administrator behind a takeover included. The first
code of a new app is not counted: its secret was just shown to the person.

- **The window and the ceiling.** The first miss opens a window of a day; a miss
  after it opens a new one. `HILOS_SECOND_FACTOR_LOCK_MISSES` misses in a window
  (default 10) lock app codes.
- **The ladder.** `HILOS_SECOND_FACTOR_LOCK_STEPS` holds the lock durations in
  seconds (default `900,3600,21600,86400`); the last step repeats. A lock put
  within a day of the end of the previous one takes the next step, otherwise the
  first. The window and that day are constants of `SecondFactorLockPolicy`, not
  environment values: a shorter one would hand a patient guesser its attempts
  back, as the throttle's day of forgiveness would ([auth-throttle.md](auth-throttle.md)).
- **The lock.** An app code under the lock is refused before it is checked —
  a right one too — with `Too many wrong codes. Use a backup code, or try the app
  again in <time left>`, and counted nowhere. Backup codes are outside the lock
  and the count: they cannot be guessed, and they are the owner's way out of a
  lock a guesser put.
- **The sign-in wait keeps its own count.** A wrong code on the code step is
  counted both on the session (five end the wait) and on the person. A refusal
  by the lock is neither, so the wait goes on: a backup code or a removal asked
  from the step still gets the person through. A new sign-in with the password
  starts the wait's count again, not the person's, and does not lift the lock.
- **Races.** The count is one statement on the row; the lock is a conditional
  write `WHERE app_code_misses >= ?` that empties the window, so of two misses
  reaching the ceiling at once one locks and one notice goes out.
- **The notice.** Every lock is the mandatory notification
  `second_factor.app_codes_locked` on every channel of the person: how many
  codes, until when, that backup codes work, and to change the password and
  end the other sessions if it was not them. The session is not signed out.
- **The way out.** `second-factor:unlock <userId>` — the operator's command,
  answered by the users library on the person's agent's answer, the agent lifting
  the lock (HIL-1406) — clears the lock, its step, the count and its window, and
  says whether a lock was in force and until when. The person is not notified
  ([../cli/commands.md](../cli/commands.md)).
- **Merge and erasure.** A merged account keeps the survivor's own lock; the
  folded account's row goes as before. Erasure deletes the row.

A removal asked from the code step sends notices to every channel of the owner,
so it passes the auth throttle; the same request from the profile does not.

## Policy

Eight settings (`SecondFactorSettings`): two switches — whether the second
factor is on for the installation at all, and whether turning it off forgets
every trusted browser (HIL-1303) — then who must use it (`none|admins|everyone`),
trusted-device days, backup codes per set, the default, shortest and longest
removal wait. A write that moves them is sent to every connection
(`hilos_second_factor_policy`, which carries `enabled` too); the browser keeps it
in the session scope and lets it override only answers older than the frame.

Turned off, the second factor asks nobody. The gate passes every proof, a
step-up skips its step and asks the next proof ([step-up.md](step-up.md),
"Choosing the proof"), and nobody is required to enrol whatever the required key
says. The people's apps, backup codes and removals stay as they are: the profile
section keeps carrying them beside `enabled`, refuses every action of its own
with "Two-step verification is turned off by your administrator" but calling off
a removal under way — from the profile and by the letter's link — and a removal
asked for before runs out on time. Turning it back on asks the same apps again at
the next sign-in; the sign-ins already in stay in.

Turning it off is applied whichever door wrote it — the two-factor screen, the
general settings table, the console's `settings:set`. After the write the
settings library compares the policy before and after; on "on → off" it sends the
frame, then hands `hilos_auth_second_factor_disable_apply` to the session holder,
which lets every sign-in waiting on a code or on enrolment go back to its start
with no reason given — as when a person's last app goes — and answers the write.
When the second switch says to forget trusts, the write has recorded the
restriction (S, 0) and the frame carries its id as `restrictionId` (empty
otherwise); the holder erases nothing itself but waits on
`SecondFactorTrustRestrictionSweep::await(restrictionId)`, so the people's
agents forget their trusts, and answers by the result: 0 still owed is success,
more than 0 is a refusal saying that some trusted browsers are not forgotten yet
and will be forgotten automatically. The switch-off itself stands either way.
The price is that the answer waits for the owners: with more than 100 people
holding a trust, or a silent owner, it comes after the browser's 30 seconds and
the console's 15, and the administrator sees the earlier "no answer" while the
switch-off is already in force.

A tab let go there finds an empty wait if it submits a code afterwards, and is
told the step has expired.
"Off → on", a reset of the key and a dropped row only send the frame. A browser
write that turns it off is confirmed first: the settings library asks
`AskingAdministrator::confirmed()` for `disable_second_factor` before the write
([step-up.md](step-up.md), "Operations on another person's account"); the
console carries no connection and stands outside.
