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
and the policy requires one of this person). Registration, `admin:create` and
impersonation do not pass the gate: the first has no factor yet, the other two
are the operator's.

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

| Table | Owner | What |
|---|---|---|
| `hilos_second_factor` | users library | apps: base32 secret, last accepted step, confirmed or not |
| `hilos_second_factor_backup_code` | users library | one row per code, spent by a conditional write |
| `hilos_second_factor_reset` | users library | removals: when asked, when due, the cancel token, last notice |
| `hilos_second_factor_setting` | session holder; users library has Add/Update | the person's removal wait and a shorter one not yet in force; the app-code miss count, its window, the lock step and its end — written by the users library |
| `hilos_second_factor_trust` | session holder | a browser (session row) trusted for a person until a moment |

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

The holder needs full ownership of settings to upsert that wait and erase a
person atomically. The users library keeps only Add/Update for personal wait
changes; no shared-full-owner exception is needed. These set operations remain
at the holder when ordinary personal edits move to instance owners; see
[instance-owners.md](instance-owners.md#operations-over-many-instances).

The loser's sessions end after commit. When its factor first protects the
survivor, the survivor's sessions end too. A confirmed loser factor revokes
trusts of both accounts; otherwise only the loser's trusts go. The survivor's
profile receives its updated section after commit. Merge tables expose only
confirmed-app presence, hidden from an admin-view-mode viewer, never secrets,
codes or app labels.

## Trust

"Don't ask again on this device" writes a trust row for (session row, person)
until now + the administrator's days (0 offers no trust). The session row
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
after the holder commits it.

Password recovery and a profile password change revoke trust of every other
browser, including browsers already signed out; the current browser is kept by
its durable session row id across token rotation. Choosing to end other sessions
controls those sessions, not whether their trust is revoked. Ending one session
revokes that browser's trust for the person; ending all other sessions revokes
all their trusts. Blocking a person removes every trust even when no session is
currently signed in. Unblocking does not restore one. Ordinary sign-out keeps
the trust until its expiry or an explicit revocation.

## The delayed removal

With neither an app nor a backup code, a person asks a removal — from the code
step or from the profile. It takes effect after the person's wait (a shorter
wait they chose waits out the wait in force first; the administrator bounds it,
floor 1 day). The users library sweeps once a minute: a due removal is marked
carried out first, then the factor, its codes and its trusts go; a waiting one
is announced again daily, and that reminder carries the same "it was not me"
link as the first notice (`HILOS_SECOND_FACTOR_CANCEL_URL`, route
`/auth/second-factor/cancel`). Every notice is a mandatory notification type on
every channel, and the link cancels the removal without signing in. Any accepted
code cancels a standing removal too — whoever shows the factor has not lost it.

## The app-code ceiling

Wrong app codes are counted per person, on their row of
`hilos_second_factor_setting`, wherever the code is typed (HIL-1285): the code
step of a sign-in, showing and renewing the backup codes, disconnecting an app,
proving a connected app before adding another, and confirming an operation by
the second factor. All of them pass `SecondFactorCommands`, in the users
library — `confirm()` for the sign-in, `assertProof()` for the rest — so one
library on the cluster writes the count. The first code of a new app is not
counted: its secret was just shown to the person.

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
  answered by the users library — clears the lock, its step, the count and its
  window, and says whether a lock was in force and until when. The person is not
  notified ([../cli/commands.md](../cli/commands.md)).
- **Merge and erasure.** A merged account keeps the survivor's own lock; the
  folded account's row goes as before. Erasure deletes the row.

A removal asked from the code step sends notices to every channel of the owner,
so it passes the auth throttle; the same request from the profile does not.

## Policy

Six settings (`SecondFactorSettings`): who must use it (`none|admins|everyone`),
trusted-device days, backup codes per set, the default, shortest and longest
removal wait. A write that moves them is sent to every connection
(`hilos_second_factor_policy`); the browser keeps it in the session scope and
lets it override only answers older than the frame.
