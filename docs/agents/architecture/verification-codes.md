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

## What the Person Watching Is Told: The Send-Progress Line

The code screen carries a live line saying where the code has got to — `queued`,
`sending`, `sent`, or `failed` with the provider's own sentence (HIL-826). It is
the answer to a screen that used to look identical whether the letter had left,
was stuck behind a stalled mail server, or had been refused outright.

**The line belongs to the browser SESSION, not to the socket.** It is one runtime
row per session in `hilosCodeSendAttempts`, keyed by the hash of the session
cookie token — the same key the toast stack and the freeze use — and delivered
with `AbstractAgent::sendToSession()`. A reload and a second tab of that browser
read the same line, and another browser reads nothing; those three fall out of the
addressing rather than being three pieces of work. It is runtime and not durable
on purpose: the line lives as long as the code does, and a restart that loses it
loses a sentence about a code nobody can enter any more.

**One writer, and the transports only report.** The sessions library agent owns
the collection (`OWNS_RT` on the project's `SessionsLibraryAgent`, beside the two
waits, because `AuthFeature::mount()` is what mounts it). Everything that carries
a code — the sign-in commands, the per-channel mail queue, the code agent —
reports its step over `HILOS_CODE_SEND_STEP` and writes nothing.

**The mail subsystem learns nothing about auth.** The order for a letter carries
an opaque progress ticket (`CodeSendTicket`, 16 hex, minted per SEND) which the
mail agent keeps beside the message and hands back with every step. Reporting by
recipient address instead was rejected: it would need a rule for which letters
count as codes, and it would hand one browser's progress to every browser parked
on that address.

**A report is judged by its TICKET, not by its session.** `advance()` finds the
row carrying that ticket or changes nothing. That is the whole of the resend race
and of the late report of a dead attempt, with no moments compared: a resend mints
a new ticket, so the previous attempt's refusal cannot paint over the send that
replaced it.

**A retryable refusal goes back to `queued`, not to `failed`.** The raw-send queue
retries with a growing backoff, so `failed` is reserved for a permanent refusal or
an exhausted attempt count. Showing "could not send" and then "sent" a second later
is flicker; `failed` is the state a person acts on by pressing resend, and it has
to keep meaning that.

**The refusal carries the provider's own words**, cut to one sentence on the way
out (`CodeSendStepSignalData::step()`: first line, whitespace collapsed, capped).
This is a deliberate departure from the practice `AuthCodeResultSignalData` set —
a stable reason code only, detail in the log — because a stable code cannot hold
words we did not write. The dialogue behind the sentence still stays in the agent
log, and the outcome signal still carries nothing but its reason code.

**There is no timeout on a silent transport.** A dead mail agent leaves the line
on `queued`, which is true: the letter IS queued. What ends the wait is the code
expiring or the person pressing resend. Inventing "probably not delivered" would
lie exactly where nothing is known.

**An empty frame is legal and meaningful.** `state: null` takes the line away, and
it is sent on every handshake — including when there is nothing to say — for the
reason `publishSessionToasts()` gives: silence and "you are owed nothing" must not
look the same to a browser. A code screen with no line reads as "nothing to say",
never as an error.

**On the frontend the line is bound at BOOT, not by the surface.** `bootHilos`
calls `bindCodeSendProgress()` and the frame lands in `hilosCodeSendProgress`,
which the auth surface reads; the machine is told through
`AuthFlow.reportSendProgress()` and owns the lifetime from there. The binding
cannot move into the surface, and that is the reload clause rather than a
preference: the line is published on the HANDSHAKE, and on a gated page the
surface mounts only once that handshake has been answered — a surface listening
for itself would miss the one frame it exists to draw.

**The phone path opens its code screen at once**, exactly as email always has
(Design D7 of HIL-826). The old rule — open only once a code really went out —
was compensation for having no line: "enter the code we sent via Telegram" was a
promise the transport had not made. With the line the screen promises nothing, so
a channel that turns out unreachable simply rolls the person back to the step they
sent from and dims that channel, as it did before. What is paid: a person can see
the code field and be taken back a second later.

**The refusal is not proved by e2e**, and that is said out loud so its absence is
not read as coverage: a stand cannot kill its mail transport until the emulated
service gateway exists (HIL-919). It is proved by the mail agent's unit test.

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
  `tasks-stand-gateway-local` / `polls-stand-gateway-local` in the other two
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
- Do not write the send-progress line from a transport. The sessions library owns
  the collection; a second writer would make "who said this" unanswerable.
- Do not match a reported step by session, address or moment. It is matched by
  ticket, and a step whose ticket is not the one the line holds changes nothing.
- Do not report `failed` for a refusal that still has retries behind it. That is a
  return to `queued`, and the difference is the whole reason `failed` means
  anything to a person.
- Do not put a transport's raw error on the wire. One sentence goes out, cut by
  `CodeSendStepSignalData::step()`; the dialogue stays in the agent log.

## Validation

`composer run test:framework:integration` — `VerificationSpendRaceIntegrationTest`
drives the race with two `DbContext` instances over one row, and
`VerificationConsumeLogIntegrationTest` pins what each outcome writes to the log.

The ceiling is pinned on that same fixture:
`testTwoWorkersShareOneAttemptBudgetRatherThanGettingOneEach` is the measurement
above turned into a case, `testAnAttemptRefusedByTheCeilingLeavesTheRowUnchanged`
holds the primitive, and `testTheRefusedWorkerStopsSeeingTheChallengeAsLive` holds
the re-read of the mirror.

The send-progress line is pinned by `CodeSendProgressLineTest` (the row's rule -
start replaces, a stale ticket moves nothing, a retryable refusal loses the
sentence), `CodeSendSignalDataTest` (both frames, including the empty one),
`MailDeliveryChannelAgentTest` (the four reports of the mail queue) and
`CodeChannelSendIntegrationTest` (the code agent's own pass). On the frontend,
`core/test/auth/authSendProgress.test.ts` holds the parse boundary and
`core/test/auth/authFlow.test.ts` holds the machine's lifetime for it.
