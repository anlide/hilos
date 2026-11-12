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

These are current grants and calls, including the retained foreign writes.
The transitions below do not make a future owner callable today.

| Table | Writers and operations today | What |
|---|---|---|
| `hilos_second_factor` | users library `Add`; person's agent `Update`/`Remove`; holder `Update`/`Remove` for merge/erasure | apps: base32 secret, last accepted step, confirmed or not |
| `hilos_second_factor_backup_code` | users library `Add`/`Remove` (replacement set); person's agent `Update`/`Remove`; holder `Update`/`Remove` for merge/erasure | one row per code, spent by a conditional write |
| `hilos_second_factor_reset` | users library `Add`; person's agent `Update`/`Remove`; holder `Remove` for merge/erasure | removals: when asked, when due, the cancel token, last notice |
| `hilos_second_factor_setting` | person's agent `Add`/`Update`; holder `Add`/`Update`/`Remove` for the merge's wait upsert and merge/erasure deletion | the person's removal wait and a shorter one not yet in force; the app-code miss count, its window, the lock step and its end |
| `hilos_second_factor_trust` | holder `Add`/`Update`/`Remove` (create/renew, global term/switch-off, merge/erasure); person's agent `Update`/`Remove` for personal revocations and for applying the saved restrictions | a browser (session row) trusted for a person until a moment, and the moment it was last granted |
| `hilos_second_factor_trust_restriction` | settings library `Add` in the transaction of the save that shortened the term or turned the factor off; no `Update` or `Remove`; the holder and the person's agent read it | one shortening: the save moment and a term of 0 to 365 days; trusts granted no later than the save live no longer than the save plus the term, and 0 takes them out |

The personal edits moved in HIL-1406 are written by the person's agent
(`AbstractUserAgent`, its writes in `SecondFactorPersonEdits`) on a frame of the
users library, which judges what lies outside the person's set first and does
what follows the agent's answer. The library still creates enrollment, backup
codes and reset requests. Today `issueSet()` also deletes the previous code set.
That is a later write to old rows, not part of creating its replacement.

The personal owner removes the previous enrollment before the library creates
the new one (HIL-1903). Every start of an enrollment - on the way in, the first
app in the profile, and a further app after its code - sends the person's agent
`hilos_user_second_factor_enroll_drop`; the agent deletes every enrollment of the
person that was never confirmed and answers
`hilos_user_second_factor_enroll_drop_done`, and on that answer the library
creates the new enrollment with its secret and answers the same press. The
secret is born at that creation, so nothing secret travels between the hops. A
refusal of the agent refuses the press in its words. An answer that finds an
unfinished enrollment standing again - a second start pressed at the same time
created it first - refuses its press with `SETUP_EXPIRED`, so a person keeps at
most one unfinished enrollment. The personal owner removes the old backup-code
set before the library creates the new codes (not in the code yet — HIL-1904).
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

The holder currently claims settings with all three operations for that wait
upsert and the terminal deletions. The person's own wait is created and edited
by their agent (HIL-1406). The complete-only topology comparison does not catch
this overlap; it is named terminal debt, not a permission for partial DB
co-owners. Old erasure rights remain until its live switch
(not in the code yet — HIL-1952), and old merge rights until its own
(not in the code yet — HIL-1999). The detailed
[erasure](account-operations.md#erasure) and [merge](account-operations.md#merge)
canons give each prepared capability its own address. For merge, each owner
checks its protection, shown flag and pending-removal refusals at preparation
(not in the code yet — HIL-1988). Reservation restrictions cover protection
changes and affected policy entrances, including late service frames
(not in the code yet — HIL-1989). The loser transfers or deletes its factor
and backup codes by fate, preserving spent status, and removes its other
second-factor rows with its result (not in the code yet — HIL-1992). The
survivor writes the longer effective wait when protection transfers and
deletes its own trusts under the existing policy, with receipt
(not in the code yet — HIL-1994). The holder ends loser sessions after the
fact and survivor sessions after receipt when the loser's factor first
protects it (not in the code yet — HIL-1995).

The loser's sessions end after commit. When its factor first protects the
survivor, the survivor's sessions end too. A confirmed loser factor revokes
trusts of both accounts; otherwise only the loser's trusts go. The survivor's
profile receives its updated section after commit. Merge tables expose only
confirmed-app presence, hidden from an admin-view-mode viewer, never secrets,
codes or app labels.

## Trust

"Don't ask again on this device" writes a trust row for (session row, person)
until now + the administrator's days (0 offers no trust). The row also keeps the
moment it was last granted: a new pair stores it with its creation, and an
extension moves it to now while the first writing stays. The session row
survives token rotation, so a trusted browser stays trusted; signing out does
not end the trust, switching the factor off and a carried-out removal do.
Trust does not remove a protected operation's fresh confirmation; step-up asks a
different question and deliberately ignores trust ([step-up.md](step-up.md)).

The gate checks the current trust term as well as the row: 0 never accepts an old
trust, and a row ending later than now plus the current term waits on the code
until the session holder applies a shorter term. A saved shorter term caps only
trusts that were live when it was saved, using that save moment; it never extends
an earlier expiry. Saving 0 erases every trust, including expired rows. Raising
the term later does not recreate or lengthen a trust. The settings library sends
the reduction to the session holder and the administrator's action is answered
after the holder commits it. That frame carries the save moment the restriction
below was written with.

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
erasure a term of 0 makes, by the same holder, and with the same 0-day
restriction recorded. When it says no, the trusts live
to their own expiry, and turning the factor back on finds them: a trusted browser
still skips the code step until its trust runs out.

Password recovery and a profile password change revoke trust of every other
browser, including browsers already signed out; the current browser is kept by
its durable session row id across token rotation. Choosing to end other sessions
controls those sessions, not whether their trust is revoked. Ending one session
revokes that browser's trust for the person; ending all other sessions revokes
all their trusts. Blocking a person removes every trust even when no session is
currently signed in. Unblocking does not restore one. Ordinary sign-out keeps
the trust until its expiry or an explicit revocation.

Who writes each of these today (HIL-1407). The session holder's `trust()`
creates a new pair or extends an existing pair on the way in. It applies the
administrator's term installation-wide, removes trusts on switch-off when
requested, and removes them in merge and erasure; it claims all three
operations for those paths. Taking one person's trust away is already that
person's agent's, in the turn of the write that causes it:

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

New trust creation on entry stays at the holder; renewal of an existing
session/person pair goes to the personal owner, which owns every later write
(not in the code yet — HIL-1907). The saved policy needed to apply a shorter
term is retained, as the restrictions above (HIL-1900); the sessions holder's
sweep and the owner's start recover missed application work (HIL-1901). Renewal
and recovery are ownership and recovery obligations, not a declaration of new DB
fields here.

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
the frame; the term and the switch-off below do not yet (HIL-1908, HIL-1909).
The application reads the person's trusts without locking their rows, so a
holder extending a trust strictly between that read and the owner's write can
have the extension cut; the outcome errs on the side of fewer trusts, never
more, and the renewal moving to the owner (HIL-1907) removes the race.

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
changes nothing more; a live move is HIL-1912.

The holder also keeps an internal request for the term and the switch-off to
wait on, "finish the restrictions up to R, then tell me": it starts a pass at
once when none runs, skips the hour's pause, answers at once when nobody owes,
and otherwise answers at the end of the pass with the number of trusts still
violating those restrictions, 0 meaning done. Only tests call it today
(HIL-1908, HIL-1909). A holder that stops drops what waits, and no answer comes.

The person's agent does not wait for the sweep. At start, whether it is the
sweep's frame that woke it or not, it applies every saved restriction to the
person's trusts in one transaction, with no frame and no answer, when the
person owes; a folded account applies them too, and a person with no violation
gets no write. A failure there is logged as an agent error, the start goes on,
and the sweep asks again. The frame of the sweep that woke the agent then
arrives after the start, changes nothing, and is answered ok.

The personal owners apply the saved cap, or remove their rows for a zero term
(not in the code yet — HIL-1908). They also perform `forgetTrusts` when
installation switch-off calls for it (not in the code yet — HIL-1909). The
saved-moment rule, no extension of an earlier expiry, current gate checks and
browser outcomes above stay requirements. The current holder calls remain
until each ordinary move is enabled. Its terminal removals then remain only
until erasure's switch (not in the code yet — HIL-1952) and merge's switch
(not in the code yet — HIL-1999).

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
which forgets the trusts when the second switch says so, lets every sign-in
waiting on a code or on enrolment go back to its start with no reason given — as
when a person's last app goes — and answers the write. A tab let go there finds
an empty wait if it submits a code afterwards, and is told the step has expired.
"Off → on", a reset of the key and a dropped row only send the frame. A browser
write that turns it off is confirmed first: the settings library asks
`AskingAdministrator::confirmed()` for `disable_second_factor` before the write
([step-up.md](step-up.md), "Operations on another person's account"); the
console carries no connection and stands outside.
