# Verification Codes

Read this before changing anything about a one-time code or link — issuing one,
checking one, spending one, or changing what stops a flood of them. The codes
themselves live in `framework/backend/Auth/Verification/` and
`framework/backend/Database/Object/{Item,Collection}/UserVerification*.php`; the
throttle that answers an actual flood is a separate subsystem
(`framework/backend/Auth/Throttle/`, HIL-420) and stands in front of all of it.
This page says which of these guards holds under contention and which does not, so
that the next leaf does not have to work it out by reasoning about the code.

One table stands behind every flow: `hilos_user_verification`, one row per
challenge, addressed by `(type, identifier)` — a registration confirmation, a
recovery code, both halves of a magic-link letter, an SMS login code. One row is
active at a time, because issuing voids the previous one
(`UserVerifications::voidActive()`).

## Core Rule

A code is spent by `UserVerification::consume()` and by nothing else. The spend
is atomic and its answer is the fact of who won; a caller that skips it or
ignores its answer is handing the same one-time ticket out twice.

## The Four Guards

| Guard | Where it lives | What it stops | Holds under contention? |
|---|---|---|---|
| Single-use | `UserVerification::consume()` | one code being spent twice | **yes** — one conditional UPDATE |
| Attempt ceiling | `VerificationService::verify()` / `matchCode()` / `consumeIfMatches()` | guessing a code | no — per worker, see below |
| Resend cooldown | `VerificationService::refuseBySendGate()` | a resend button held down | no |
| Send cap per window | `VerificationService::refuseBySendGate()` | a script asking for code after code | no — see below |

Defaults are in the env catalog (`framework/backend/Environment/EnvCatalogStub.php`):
ceiling 5 attempts, TTL 900 s, cooldown 60 s, window 3600 s, cap 5 per window and
3 for SMS.

### Single-use is atomic, and says who won

`consume()` writes `SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL` and
reads `Database::affectedRows()`. Exactly one of two workers holding the same live
challenge changes a row; the other is told `false` and takes the same branch as a
caller that found no challenge at all (HIL-679).

Before that it was an unconditional UPDATE, and single-use rested on
`findActive()` no longer matching the row *afterwards* — a rule the second worker
had already passed. Recovery is where that cost real damage: two devices on the
new-password screen each wrote their own secret, the last one won, and the first
was told it had worked.

Do not "improve" this with a transaction or a row lock around check-then-spend.
It was weighed and rejected: the lock would be held across a bcrypt comparison to
buy the same result the WHERE clause buys for nothing.

Three callers pass the outcome outward — `verify()`, `consumeIfMatches()` (behind
`verifyCode()`), and `consumeActive()`. The rest ignore it on purpose, and should
keep ignoring it: they consume in order to *void* a challenge (the three
attempt-ceiling branches and `UserVerifications::voidActive()`), and a neighbor
that voided it first did the job they wanted done.

A lost race is written to the operator log as
`VerificationRejectReason::RACE_LOST`, apart from `CONSUMED`. The two look the
same on the row afterwards and mean opposite things: `consumed` is a person
clicking a stale link, `race_lost` is a front end submitting twice. Keep them
apart, or the difference stops being countable.

## What Is Not Protected: The Attempt Ceiling Is Per Worker

`incrementAttempts()` writes `attempts = attempts + 1`, which is atomic, and then
mirrors the new value on the loaded object. The ceiling is checked against that
**mirror**, not against the row — and a collection caches its objects, so a worker
that has looked the challenge up once counts only the attempts *it* made. Two
workers therefore get the ceiling each: measured against the real table with a
ceiling of 3, two contexts recorded 6 attempts on one challenge before either was
refused.

The guesses still have to be right to be worth anything, and the ceiling still
bounds each worker, so what an attacker buys is a multiplier equal to how many
workers they can spread across — not an unlimited run. Nothing here has been
changed to fix it; it is written down so that no one reads the ceiling as a global
budget when sizing anything that depends on one.

The cure, when it is worth paying for, is the same shape as the one for the cap
below: make the check part of the write, so the row and not the object decides.

## What Is Not Protected: The Send Cap

The cap is **not** atomic, and this is a decision rather than an oversight
(owner's call on P-079, 2026-08-23).

`refuseBySendGate()` reads `sendStats()` in one statement and the issue path calls
`createChallenge()` in another. Nothing holds the pair together, so a burst of
requests on one identifier that arrives inside that window all read the same
count and all pass. What the cap does stop is a sequential script — one request,
then the next — which is what it was built for.

It is left this way because:

- the IP and session throttle stands in front of it
  (`framework/backend/Auth/Throttle/`, HIL-420) and is the guard that answers a
  flood;
- paying a transaction on every send buys only this window;
- the cost of the hole today is extra mail, not money.

**When a real SMS bill appears, the answer is an atomic per-window counter**
(`INSERT ... ON DUPLICATE KEY UPDATE` on a `(type, identifier, window)` row), not
a lock and not a transaction around the read. That is a leaf of its own; do not
fold it into an unrelated change.

Also not covered here, and not covered anywhere yet: two *issues* racing each
other (both voiding, both inserting), and a front end that submits the same code
twice on its own.

## Anti-Patterns

- Do not spend a challenge with a bare UPDATE of `consumed_at` outside
  `consume()`. There is one write, and its condition is the guard.
- Do not treat `false` from `consume()`, `consumeActive()`, or `verifyCode()` as
  an error to report or retry. It means the ticket is gone; answer the person the
  same way an expired code is answered.
- Do not add a distinguishing outcome for a lost race on the wire. The silence
  toward the person is the anti-enumeration posture the whole service is built
  on; the distinction belongs in the log and nowhere else.
- Do not read the cap as a spend guarantee when sizing anything that costs money,
  and do not read the attempt ceiling as a global guess budget.

## Validation

`composer run test:framework:integration` — `VerificationSpendRaceIntegrationTest`
drives the race with two `DbContext` instances over one row, and
`VerificationConsumeLogIntegrationTest` pins what each outcome writes to the log.
