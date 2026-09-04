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
| Attempt ceiling | `UserVerification::incrementAttempts()` | guessing a code | **yes** — one conditional UPDATE |
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
keep ignoring it: they consume in order to *void* a challenge (the ceiling branches
of those same three methods, each of which now has two — one for a count that
reached the ceiling and one for an attempt the row refused — plus
`UserVerifications::voidActive()`), and a neighbor that voided it first did the job
they wanted done.

A lost race is written to the operator log as
`VerificationRejectReason::RACE_LOST`, apart from `CONSUMED`. The two look the
same on the row afterwards and mean opposite things: `consumed` is a person
clicking a stale link, `race_lost` is a front end submitting twice. Keep them
apart, or the difference stops being countable.

### The ceiling is the write's condition too, and the row owns the budget

`incrementAttempts(int $maxAttempts): bool` writes
`SET attempts = attempts + 1 WHERE id = ? AND attempts < ?` and reads
`Database::affectedRows()`. The budget of guesses therefore belongs to the **row**,
and every worker spends from the same one. `false` means the row was already at the
ceiling — the same "too late" `consume()` answers a lost race with, never a failed
write — and the caller voids the challenge and refuses without comparing the code,
so a refused guess costs no bcrypt.

Until HIL-715 the UPDATE was unconditional and the ceiling was judged against the
**mirror** on the loaded object; a collection caches its objects, so a worker that
had looked the challenge up once counted only the attempts *it* made. Two workers
got the ceiling each: measured against the real table with a ceiling of 3, two
contexts recorded 6 attempts on one challenge before either was refused. That
measurement is why the cure has the shape it does — the object was never going to
be the right place to hold a number several objects share.

The mirror is re-read from the row after every write, refused caller included,
with a targeted `SELECT attempts`. That half is not optional: four readers judge a
challenge by the mirror — `isActive()`, `UserVerifications::findActive()`,
`VerificationService::hasActive()` and `UserVerifications::describeInactive()` — so
a worker left holding a stale count would go on offering a person a live code on an
exhausted row. A row that is gone by then leaves the mirror where it was; the count
is not something to invent.

`matchCode()` keeps checking the code *before* it counts, which has a deliberate
consequence: a worker whose mirror is behind will accept a **correct** code against
an exhausted row. That is the right trade — the ceiling exists to bound guessing,
and knowing the code is not guessing.

**The guard is temporary, and is meant to stay small enough to remove** (owner's
call, 2026-08-26). Once the user library and the per-user agents arrive, one writer
owns the row and this increment is purely local however it is written. So it must
not grow a transaction, a row lock, a broadcast frame or a cache of its own — all
of which would then have to be dug back out of the architecture. When that horizon
arrives is not this page's to say (proposal P-163): the confirmed child entities of
a user agent (HIL-630) do not include verifications, and the SMS-login and
registration challenges carry no `user_id` at all, so the epic HIL-626 owns it.

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

## Where a Code Is Read on a Stand

A stand delivers nothing to the outside world: every channel ends in the stand's
Mailpit, and that inbox is the one place a code is read, by a spec and by a
person alike.

- **Mail** reaches Mailpit directly over SMTP. The daemon's transport points at
  it (`MAIL_SMTP_HOST` / `MAIL_SMTP_PORT`, port 1025 inside the compose network,
  `MAIL_SMTP_SECURITY=none`).
- **SMS and Telegram** are caught by the stand gateway
  (`framework/docker/stand-gateway`). The daemon's `SMS_ENDPOINT_URL` points at
  the stack's gateway service, `http://<gateway>:18000/sms/send`, and its
  `TELEGRAM_GATEWAY_ENDPOINT_URL` at `http://<gateway>:18000/telegram`, where
  `<gateway>` is `stand-gateway-local` in chat's local stack and
  `tasks-stand-gateway-local` / `poll-stand-gateway-local` in the other two
  (the daemon's environment in each demo's `docker/docker-compose.local.yml`;
  the dev and test stacks follow the same shape). The gateway forwards every
  caught message to the same Mailpit as a letter before it answers the daemon:
  on a stand, "delivered" means "readable".
- **The letter's addresses carry the channel and the recipient.** The sender is
  `<channel>@stand`, the recipient `<recipient>@<channel>.stand`: an SMS to
  `+15550001` arrives from `sms@stand` to `+15550001@sms.stand`, a Telegram
  message to `+15550001@telegram.stand` (`MailForwarder::senderAddress()`,
  `MailForwarder::recipientAddress()`). A spec names the recipient it waits for;
  the mailbox is shared by every spec on the stand, so "the newest letter" is
  somebody else's as often as not.
- **The subject is the message text**, cut to 120 characters, so the code reads
  straight off the mailbox list without opening the letter
  (`MailForwarder::subject()`). The body is `Channel: <channel>`,
  `To: <recipient>`, `Sent: <time> UTC`, a `---` line, then the full text.
- **On the test stack Mailpit publishes no host port**, on purpose
  (`docker-compose.test.yml`, "No host ports"). The Playwright runner reads it
  over `MAILPIT_URL=http://mailpit-test:8025`
  (`demo/chat/tests/e2e/helpers/mail.ts`); a spec takes an SMS code through
  `waitForSmsCode()` in `helpers/sms.ts` and a Telegram one through
  `waitForTelegramCode()` in `helpers/telegram.ts`. A person reads on the local
  or dev stack, where the Mailpit UI is published on a host port that each
  demo's README lists.

There is no file with the code on disk. `StubSmsProvider` used to write each
message as a `.txt` artifact, and HIL-653 (commit `9c269667`) removed it: the
artifact was mistaken for a readable channel, it landed in the work tree owned
by the container's user, and a stale one from an earlier run was once read as
the code a person had just asked for. `demo/chat/data/sms` is a dead remnant
of that. Do not look for a code there, and do not bring the artifact back;
a stand that wants to read its SMS configures a gateway endpoint.

## Anti-Patterns

- Do not spend a challenge with a bare UPDATE of `consumed_at` outside
  `consume()`. There is one write, and its condition is the guard.
- Do not treat `false` from `consume()`, `consumeActive()`, or `verifyCode()` as
  an error to report or retry. It means the ticket is gone; answer the person the
  same way an expired code is answered.
- Do not add a distinguishing outcome for a lost race on the wire. The silence
  toward the person is the anti-enumeration posture the whole service is built
  on; the distinction belongs in the log and nowhere else.
- Do not read the cap as a spend guarantee when sizing anything that costs money.

## Validation

`composer run test:framework:integration` — `VerificationSpendRaceIntegrationTest`
drives the race with two `DbContext` instances over one row, and
`VerificationConsumeLogIntegrationTest` pins what each outcome writes to the log.

The ceiling is pinned on that same fixture:
`testTwoWorkersShareOneAttemptBudgetRatherThanGettingOneEach` is the measurement
above turned into a case, `testAnAttemptRefusedByTheCeilingLeavesTheRowUnchanged`
holds the primitive, and `testTheRefusedWorkerStopsSeeingTheChallengeAsLive` holds
the re-read of the mirror.
