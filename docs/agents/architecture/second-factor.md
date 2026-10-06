# Second Factor

Read this before changing anything about two-step verification (HIL-494): the
gate a proven sign-in passes, the wait it is held in, the trust a browser earns,
the backup codes, and the delayed removal. The code lives in
`framework/backend/Auth/SecondFactor/`, the sign-in half in the two libraries
(`AbstractSessionsLibraryAgent`, `AbstractUsersLibraryAgent` with
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
| `hilos_second_factor_setting` | session holder; users library has Add/Update | the person's removal wait and a shorter one not yet in force |
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

## Policy

Six settings (`SecondFactorSettings`): who must use it (`none|admins|everyone`),
trusted-device days, backup codes per set, the default, shortest and longest
removal wait. A write that moves them is sent to every connection
(`hilos_second_factor_policy`); the browser keeps it in the session scope and
lets it override only answers older than the frame.
