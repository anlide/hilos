# Protected Mode (Node Freeze)

Read this before starting a destructive operation, wiring a CLI command that
triggers one, or touching any code that reads the protected-mode runtime row.

Protected mode is the freeze a node enters while a destructive operation runs
against it: pages stop being served, this node's agents are stopped, and the
only connection left through is the one driving the operation. Its machinery is
`framework/backend/ProtectedMode/`; the two entries are
`StandaloneProtectedMode` (single node) and `ClusterProtectedMode` (leader plus
followers).
The same mode also supports an agent's direct request for the verification window:
visitors are held outside while the agents keep running (*Manual Maintenance*).

## Core Rule

The mode is unconditional and its only entry is an agent. Do not add a switch —
not a `HilosFeature` case, not an env variable, not a facade or static method,
not a second entry "for tests".

An administrator closes visitors out through the Maintenance page's
`maintenance_enable` action or the CLI (`maintenance:enable`); both trigger
the index agent, which asks through this same entry. The page of each SDK
offers a confirmation dialog from its open-state banner (HIL-1362, HIL-1365).
There is no second entry:
the core authorizes the recorded agent by type and index; the agent authorizes
the person.

## Unconditional, With One Physical Boundary

Every project that has an RT context carries the freeze row:
`RtContext::mountFeatureRuntime()` mounts `hilosProtectedModeRuntime` first, ahead
of any declared feature, because a node on which a destructive operation can be
started must be able to freeze. That is a data-integrity guarantee, not a
surface a project chooses. `ProtectedModeRuntimeMountTest` pins it.

The single boundary is physical: a project whose `createRuntime()` returns null
has no context to mount into and cannot enter the mode. Such a project must
declare an `RtContext` before it introduces a destructive operation. No separate
prohibition is needed — the facade already refuses a runtime-carrying feature
without a context (`Hilos::refuseRuntimeFeaturesWithoutContext()`), and
`HilosFeature::BACKUP` brings `RestoreRuntime`, so a restore without RT is cut
off at startup rather than mid-restore.

The verifier circle belongs to the mode, not to `HilosFeature::BACKUP`, and its
table is the second unconditional thing after the freeze row: the verification
window admits people by it, so every installation that can freeze carries it. A
project that builds an RT context is refused by its own topology unit test
without the `hilos_verifier_circle` migration — the second invariant of
`Hilos::validateDeferredFeatureRequirements()` that belongs to no feature
([../app-topology.md](../app-topology.md)).

## The Agent Owns The Entry; CLI Is Only The Trigger

Entering goes one way and one way only:

```
Agent::requestProtectedModeEnable(operation, acceptKey, sessionHash, entryMode = 'freeze')
  → PROTECTED_MODE_ENABLE signal → WorkerManager → WorkerProtectedModeEnableDTO
  → WorkerClient → Hilos::$cluster->protectedMode()->requestEnable()
```

The nested enable payload carries `entryMode`: `freeze` keeps the two-phase
restore path, and `verification_window` enters `verifying` directly on a single
node or on every master of a cluster. Old payloads without the key mean `freeze`.
The worker envelope and route stay the same. The runtime row records `entryMode`
while occupied and clears it on lift; an old version-one state file without it
means `freeze` while occupied. See *Manual Maintenance* for the cluster round.

A CLI command never enters the mode itself. It sends its request down the
command channel to the agent that owns the operation — `backup:restore-request`
reaching `BackupAgent` is the worked example — and that agent asks for the
freeze; a manual-maintenance command (`maintenance:enable`) reaching the index
agent is another. `ProtectedModeSwitch` is out of reach from a CLI process
anyway — not because `Hilos::$cluster` is missing (`Hilos::initEnv()` builds it
everywhere) but because nothing registers a switch into it outside the daemon,
so `ClusterContext::protectedMode()` is null there. No extra guard is written
for it.

## Exactly One Entry, Including For Tests

There is no second entry and none is to be added. The initiator identity
recorded on entry is what authorizes the later release (a stray agent must not
thaw the system mid-restore) and what the agent-start gate lets through, so a
synthetic initiator would test a path production does not have.

An initiator owns the open command (`protected-mode:open` or
`test:protected-mode:open`) as one cluster-wide instance. Startup refuses that
command's agent with `NODE` scope or `INDEXED` set, on a cluster and on a single
node alike. The leader authorizes its later frames by agent type and index;
replicas or multiple indexed instances would hand that right to more than one
actor.

What this obliges a test driver to do (HIL-344): give the driver its own
initiator agent and ask for the mode through it, rather than poking the daemon
past the agent. The precedent is the backup test commands, which ride
`AGENT_COMMANDS` of the live `BackupAgent`.

### The Test Tool That Obligation Produced

Five test-only commands, and the split between them is the whole design:

| Command | Answered by | Why there |
|---|---|---|
| `test:protected-mode:enter <operation> [--accept-key=<k>] [--session-token=<t>]` | the initiator agent | it calls `requestProtectedModeEnable()`; the optional cookie names the browser asking for the freeze (HIL-1305) |
| `test:protected-mode:leave` | the initiator agent | the driven operation is over: it calls `requestProtectedModeVerify()` and lands in the verification window, where a real one lands too |
| `test:protected-mode:open` | the initiator agent | the explicit lift, authorized by initiator identity exactly as in production |
| `test:protected-mode:pass` | the initiator agent | mints one code into the driven window and prints it, so a test has something to type into the verifier's field (HIL-616) |
| `test:protected-mode:close` | the initiator agent | the window's other exit, back into the full freeze: without it nothing ever drove the way out an operator takes when the verifiers found something wrong (HIL-704) |

`ProtectedModeTestDriverTrait` carries the agent half of the drive trio — enter,
leave and open; the mint and the close are answered by `ProtectedModeOperatorTrait`
under their second names (see the operator section below). A trait, because putting
the commands on `AbstractAgent` would hand a test-drive of the freeze to every agent
of every project. The carrier is one, `AbstractHilosIndexAgent`, so chat, tasks and
polls get it by inheritance. The framework's cluster probe fleet
(`FleetProbeAgent`) does not carry it: in a full demo the index agent declares the
same commands, and a command declared by two agents refuses the start. On a
multi-node stand the clustered entry path is driven by the index agent of
binance-btc-tracker: scenario 23 of its cluster stand enters the freeze, opens
the verification window and lifts it, and reads the row of every master at
each step. Scenario 25 of the same stand proves what `active` says there: every
master reads it once the freeze holds, and the close back from the window
answers only once every master has stopped again what the entry stopped
(HIL-1128). A follower's fail-closed refusal is not exercised on a live stand.

Two properties are worth keeping when this code is touched:

- **Every drive command answers on the move, not on acceptance.** Enter answers
  from `onProtectedModeReady()`, leave when this node's row reads verifying, open
  when it is back to inactive, close when it reads active again. That is what
  makes the reply a verdict and lets a test act on the next line instead of
  polling.
- **The pre-checks exist because the core answers nobody.** A disable with no
  freeze and a disable from the wrong agent are log-and-return paths. Reading
  the row first turns each into a stated reason rather than a mute timeout. A
  repeat enable no longer needs it: since HIL-909 the core answers it itself,
  with ready or with a refusal that names its reason. The agent's wait window is
  deliberately the innermost of three, so the informative refusal is the one
  that fires first.
- **Three windows, but two chains.** The nesting is agent inside caller inside
  channel, and the middle link is a different program depending on who is driving:
  a PHP CLI process for an operator, the Playwright e2e client for the test path —
  which reaches the command port over TCP, because the runner has no PHP. The two
  share their innermost and their outermost link and differ only in the middle, so
  it is one number with two executors. All three are declared together in
  `Hilos\Constants\CommandChannelWindows`, where the innermost is COMPUTED from
  the middle less a margin for the refusal to travel — the order cannot be got
  wrong by editing one file. The e2e client's copy of the middle number is in each
  demo's `tests/e2e/helpers/protectedMode.ts` and has to be carried by hand.
  Nothing here scales with the load of the run, unlike Playwright's own ceilings;
  the reasoning, and the route not taken, are in that class's docblock.

There is deliberately **no production-environment refusal on the agent side**,
and since HIL-566 there is no need for one: the socket itself refuses a
test-only command before it parks anything, so every test-only name is gated on
the path e2e actually takes — directly over TCP, since the Playwright runner has no
PHP. What the socket still does not do is authenticate its caller, which is why
`admin:grant` and the other commands not named `test:*` remain reachable to anyone who can
open `COMMAND_PORT`; that is an existing property of the channel, recorded here
so it is not mistaken for something this gate closed.

The snapshot never carries `initiatorAcceptKey`, and never a pass hash — only
the count of outstanding passes. Both are the way through the lockdown, and the
port that would publish them authenticates nobody.

### The Operator Commands Are A Separate Family, On Purpose (HIL-481)

`protected-mode:pass`, `protected-mode:open` and `protected-mode:close` are the
production half: not test-only, because they are the only way back out of a
freeze now that a finished restore no longer lifts one by itself. The agent half
is `ProtectedModeOperatorTrait`, mixed into `BackupAgent` — a restore is the
destructive operation this framework has, so that agent is the initiator the row
records.

`protected-mode:inspect` is the production state question beside them, but it is
answered synchronously by the master rather than routed to an agent. A drive command
whose reply was lost asks it once, with its own short deadline, and reads the fact from
this node's snapshot instead of retrying the drive. It keeps answering mid-freeze,
when every agent but the initiator is stopped. Its phase is only this node's view: on a
cluster it must not be read as proof that every node has quiesced.

They deliberately do **not** share names with the `test:` family. A command
routes to exactly one agent type per project (`TopologyValidator` refuses a
second owner), a project may hold two initiators — the real one and the test
driver's carrier — and a freeze may only be driven by the agent the row names. A
shared name would hand one initiator's freeze to the other, and the identity
check would then refuse it. Hence the two ladders, same shape, different owners.

A third command family serves manual maintenance in production on the index
agent: `maintenance:enable`, `maintenance:disable` and `maintenance:pass`, with
distinct names for the same one-command-owner reason. A project with backup
carries both production families; see *Manual Maintenance*.

The mint and the close each answer to **two** names — `protected-mode:pass` and
`test:protected-mode:pass`, `protected-mode:close` and `test:protected-mode:close`
— and that is not a hole in the rule above but the rule applied: which of the two
ever reaches a given carrier is decided by that carrier's `AGENT_COMMANDS`, so
each name still has exactly one owner per project. What is shared is the handler,
deliberately: one minting path, one refreeze path, one identity check, one wait
for the row to move. A second implementation of "what a pass is" or of "what
closing back does" is the thing worth avoiding, not a second name.

The open has no second name of that kind and does not need one: the test path's
`test:protected-mode:open` is its own command on `ProtectedModeTestDriverTrait`,
because the drive ladder needs a lift that works from every phase — which is also
why teardown uses it and never the close, which is refused outside the window.

The pass is minted from the secure half of `RandomHelper` and never falls back to
the pseudorandom one: a guessable pass is indistinguishable from a real one to
everything downstream. Only its SHA-256 travels to the daemon and only the hash
is stored, so the clear value exists in the operator's terminal and nowhere else
— it cannot be read back out of a log, a snapshot or a later reply.

### Two Bits Reach The Browser, And They Answer Different Questions (HIL-616)

`acceptsPass` says the verification window is **open**. `passIssued` says at
least one code is **standing**, so the field has something it could take. The
window opens before anything is minted, and between the two moments a code field
would be a box that accepts nothing — so on an administrative surface the stub
carries a sentence saying to wait, and swaps it for the field the instant the
first code lands. The push that does the swapping is the same `PROTECTED_MODE`
frame every phase change sends, fired once at zero-to-one and addressed only to
the connections the freeze still locks out (HIL-1082); later mints announce
nothing, because the bit already says what they would say.

The second bit exists rather than a narrowed first one because `acceptsPass`
carries two more jobs on the client: it decides when the mode is called over
(`createHilosConnection`) and when a presented key is dropped (`HilosConnection`).
Narrowed to "a code exists", it would reload an admitted verifier out of the
window the moment nobody held a code, and throw away his key on the way.

**A count never leaves the master.** The wire carries a boolean: how many people
the operator invited is his business and tells a visitor nothing he needs. The
count stays where it already was, in the production snapshot
(`protected-mode:inspect`), which also never carries a hash.

## The Freeze Is Also The Window For Repairing What The Operation Broke

Leaving the mode is not a formality: `enterInactive()` sends every client a
`reload` frame, and the browser that reloads asks its questions against the new
world immediately. Anything that has to be true for those questions must be true
*before* the disable request, not after it.

The worked example is the session carry-over (HIL-479). A restore replaces the
database, so every session created after the archive was taken is gone from it —
including the one belonging to the operator watching the restore. `BackupAgent`
therefore photographs the live authenticated sessions in
`onProtectedModeReady()`, while the node is frozen and the old database is still
mounted, and re-creates them once the restore is over. `SessionCarrier` owns both
halves; sessions are matched by `hilos_identity` pairs rather than by user id,
because the same id in another installation's archive is another person.

**The order is a contract, and since HIL-436 it is enforced by a barrier.** The
run does not end where its SQL ends: `finishRestore()` announces the swap, enters
`RestorePhase::REHYDRATING`, and stops. Every process told to re-read answers —
the daemon and each worker over the worker link, and in a cluster each node over
the mesh, aggregating its own processes into one answer — and only when the whole
barrier closes does `completeRestore()` carry the sessions over and ask for
`requestProtectedModeVerify()`. The reason is the verification window itself: a
verifier let in to read caches of a database that no longer exists would confirm a
fiction, so an unclosed barrier keeps the node in the full freeze, names the
processes that did not come back on the restore runtime row, and leaves the
decision to a human with `protected-mode:open`.

Four properties generalize to whatever destructive operation comes next:

- **Read nothing from the new database before announcing the swap.**
  `AbstractAgent::requestDbReHydrate()` re-hydrates the calling process on the
  spot and tells the daemon and the other workers to do the same. Without it a
  reader is answered from collections loaded out of a database that no longer
  exists.
- **Announce the swap on the failure branch too.** A failed import may have left
  the database half-rewritten, and re-reading one that was never touched is
  harmless — so re-hydration is unconditional, while work that *writes* (the
  session carry-over) stays on the success branch.
- **Wait for the announcement, do not shout it.** `ReHydrateRound` is the barrier:
  it closes when every participant confirms, writes off whoever is silent at its
  deadline (`HILOS_DB_REHYDRATE_TIMEOUT`), and takes a participant that
  disappeared off the count rather than waiting for it. A negative answer settles
  the round without completing it — fail-closed, like entry.
- **One barrier, one number, one clock.** Every round carries a number the
  participants echo back, because the roster is spelled with labels — `daemon`,
  `worker #2`, a node id — that name the same processes in every round, so a
  straggler from the previous one would otherwise close this one. That number is
  also what let the announcing agent drop its own deadline (HIL-694): the round
  always answers — on a verdict, on its deadline, or on being superseded by a
  newer announcement — and every step of the delivery that used to return in
  silence now names the agent it could not reach.
- **Repair work never holds the freeze.** A snapshot that could not be taken or a
  session that could not be written is logged and the run proceeds. The people
  affected see a login screen; the alternative is a node left frozen over a
  detail of the recovery.

**A writer the roster walk does not stop owes the freeze two things (HIL-910).**
The freeze stops agents; anything that writes the database from outside every
roster — a per-process singleton, a pool half that lives on its own — goes on
writing through the operation unless it answers the freeze itself. It owes
silence while this node's freeze row answers `silencesUnstoppedWriters()` —
`activating` or `active`, because `active` is where the initiator may replace the
database on the leader or a single node, and a follower reaches `active` only on
its leader's word that every node has stopped (HIL-1128), so on a follower
`activating` is a phase in which the database may change — and at the swap it
owes forgetting any memory tied to the replaced database before its process answers
the re-hydrate round. A cached id that the restored database lacks fails on a
foreign key, which is the lucky case; one that the restored database gives to
another value files facts under the wrong name with no error at all. The analytics
collector is the worked example. In every process it is still outside every
roster, so it answers the freeze itself: while the row silences it, it records
nothing and counts the batch it discards and each refused event as `restore`.
An agent that stops meanwhile is remembered and its stop handed over with its moment,
once the row lets go. `forgetReplacedDatabase()` is called first in
`WorkerManager::handleDbReHydrateMessage()` and
`DaemonManager::applyReHydrateContained()` — on the failed re-read too — and the
collector then counts and discards its batch and captures, and forgets the
master's remembered connections and pages. It keeps no database row numbers in
any process. Nothing is opened again at resume: a worker names its sessions by
keys, and their descriptions travel with every batch, so the writer inserts them
into the restored database from the first batch that names them. The two
agents of the journal are in the roster and the freeze stops them like any other:
the node's journal agent, stopped under the freeze, throws the node's journal
away and places one ready file with the number of discarded events and its other
closed loss episodes. The writer starts again with empty caches and loads that
file after the lift ([analytics.md](analytics.md)).
The re-read after a peer link does not forget: the database there is the same one.
The durable half of the mail pool is the second example, and the forgetting in its
strongest form: it keeps nothing across the freeze at all.
`AbstractDeliveryChannelAgent` drops every delivery it holds on its first tick
under the silence and refuses new ones until the row lets go; the rows stay
`pending` (HIL-1060). The pool picks them up itself on its first tick the freeze
no longer silences, as every new instance does on start (HIL-1135). A restore
leaves nothing to pick up: the restore engine settles every pending row of the
restored journal as failed, since whether it went out is not known, and a person
decides with retry (`BackupRestorer`, `RestoredDeliveries`).

Status's continuing file publisher keeps the same DB silence and forgetting:
only a detached sanitized public snapshot and the freeze fact remain available
for its files and heartbeat. The Status owner abandons its observation baseline
and partial DB-derived build on entry. No DB read/write, Status setting read,
DB action or durable delivery runs while `silencesUnstoppedWriters()` holds;
after lift it reacquires permitted DB data and a fresh Daemon picture. See
[status-page.md](status-page.md#freeze-and-disconnection).

## Entry Is Fail-Closed In Both Branches

A node that cannot freeze refuses loudly; it never stands inert while reporting
success. The initiator waits for ready before it destroys anything, so a refusal
— which reaches it with its reason since HIL-909 — ends that wait with nothing
destroyed, whereas a silent no-op that still produced ready would run the
operation over live nodes.

- `StandaloneProtectedMode::requestEnable()` refuses before it records the freeze.
- `ClusterProtectedMode::onEnable()` (leader) refuses **above** `activeFreeze`,
  `broadcastQuiesce` and every other trace of entry. Order matters: a leader that
  recorded the freeze and then refused would sit half-frozen forever, dropping
  every later attempt as "already in flight", and a disable cannot clear that —
  it needs a live initiator.
- `ClusterProtectedMode::onQuiesce()` (follower) refuses above the freeze record
  **and sends no `quiesced`**. Confirming a freeze that never happened would let
  the leader hand ready to the initiator and run the operation across a node that
  is still serving its clients. The cost is a leader stuck in `activating`; that
  is the accepted half of the trade — data over availability — and a stalled mode
  is reported by the watchdog below.

The two guards are the same check in different contexts — one-walk single-node
entry against a leader round that commits followers — so they are written twice
on purpose and are not merged into a shared helper.

The roster is stopped over several master passes, one agent per pass, and a node
reports itself frozen only at the END of that walk
(`ProtectedModeSwitch::onRosterStopped()`, HIL-1012): on a single node it tells the
initiator ready, on a follower it sends `quiesced` to the leader, and the leader
counts itself among the nodes it waits for until its own roster has stopped. The
lift is stepped the same way, and the frames it owes the browsers go out once the
roster is back (`ProtectedModeExecutor::finishVerifying()` / `finishLift()`).

**`active` on a row means every agent has stopped, on every path in (HIL-1128).**
Every way into the freeze — the first entry, a repeat entry from the verification
window (HIL-1057) and the close back from it — writes `activating` first and
`active` only at the end: a single node at the end of its own walk; in a cluster
the leader once every node of the round, itself included, has reported
`quiesced`, and each follower when the leader then tells it so on
`peer_protected_mode_settled`. That frame leaves before the ready, and the order
carries weight: an initiator on a follower gets both over one link, writes
`active` first, and only then relays the ready that photographs the verifier
circle, so its `enterActive()` never clears a circle already on the row. A
follower takes it only from the leader it follows and only on `activating`.
The close owes nobody a ready: the switch remembers
whether the walk in flight owes one (`readyOwed`), because the phase no longer
tells the walks apart. So `protected-mode:close` answers only once the agents the
window brought back have stopped again everywhere, a ready answered to an enable
on `active` is true by construction, and an enable that arrives while the close
walks gets the refusal an enable gets during the first entry. The close is
answered by the row reaching `active`; a follower that never got the round — it
missed the verify, or its leader died — leaves the close unanswered and the
watchdog names the round overdue, which is the fail-closed half of the trade.

## A Freeze That Stops Moving Is Reported, Never Lifted (HIL-482)

`ProtectedModeWatchdog` watches the freeze and tells a person about it. **It
never lifts one**, in any phase and under any name. A watchdog cannot tell "the
initiator hung" from "the initiator is in the middle of the import", and opening
a node onto a half-written database is the single failure this whole subsystem
exists to prevent — so a timeout that thawed would reintroduce by the back door
what the operator ladder (HIL-481) removed on purpose. Not even the safe-looking
case is excepted: a leader stuck in `activating` handed out no ready and
destroyed nothing, and it is still only reported. One rule, no phase-by-phase
branching, because the person reading the alert at 3am has to be able to predict
what the system did without them.

It runs **on the master**, ticked from the main loop under the same leader gate
the cron check uses, and there is no choice about that: during a freeze every
agent but the initiator is stopped, so a watchdog agent would either be stopped
with them or be the process it is watching. The master's own constraints hold
unweakened: the watchdog judge reads memory, writes nothing, and makes no
blocking call. The existing master tick bridge separately publishes the
returned presentation snapshot through the runtime row's item action, only when
at least one of its seven fields changes. That sync does not make the watchdog
a writer of freeze state or database data.

| Verdict | What it means |
|---|---|
| `INITIATOR_LOST` | the initiator agent stopped; sticky, because the agent-start gate lets its type start again and a fresh instance would read as alive |
| `RESTORED_FROM_DISK` | the freeze came back with the daemon, so nothing is running behind it; reported on the first tick — under a manual window only when `protected_mode.manual_maintenance.restart_is_normal` is off. Workers tell the master this key via `worker_protected_mode_settings`; until one reports it, the returned manual window gets neither a verdict nor a routine-restart line. The default is one warning line for the freeze, without mail |
| `QUIESCE_OVERDUE` | phase `activating` past `HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT`, or a direct `verification_window` on `verifying` past that threshold with follower masters still pending in the leader's roster; the alert names the nodes that never confirmed |
| `SILENT` | any other non-inactive phase with no progress mark newer than `HILOS_PROTECTED_MODE_SILENCE_TIMEOUT` — except in a manual-maintenance window (operation `manual_maintenance` entered as `verification_window`), where no operation is behind the door to mark progress |

**The progress mark is written by the operation, not by the framework.** What
proves life is that the *work* moved, not that a process is up: a restore spawns
a child and waits for it, so a framework-written "the agent still ticks" mark
would keep a permanently hung restore invisible forever. The initiator calls
`AbstractAgent::reportProtectedModeProgress()` when something of its own advanced
— a new restore phase, output read from the child — and that refreshes
`progressAt` on the freeze row, stamped by the master that owns the row. It is
not sent to peer nodes; the existing Maintenance subscription projects it to
the browser as ISO-8601 UTC for display. That display does not feed watchdog
arithmetic, so a skewed clock elsewhere cannot move the judgment. This is also
the answer to "fast restore versus long migration": an operation
that is still running says so, so its *length* stops being a parameter and no
per-operation timeouts exist. An initiator that marks no progress raises a false
alarm on an honest long operation — the accepted direction of the error, because
the alarm is a message and not an action.

**The alarm is a condition, not an event.** First mail on detection, a reminder
every `HILOS_PROTECTED_MODE_ALERT_INTERVAL` while the phase is not inactive, and
an all-clear when it reaches inactive — but only if an alarm was raised. Mail
goes to `HILOS_PROTECTED_MODE_ALERT_EMAILS`, an env address list rather than
`AdminAudience`, and rides the raw-send path (`Hilos::$mail->send()`) rather than
a notification: the alarm fires precisely when the database may be unreadable,
and a `NotificationDraft` is persisted before it is delivered. The consequence is
load-bearing and narrow: the `hilos_mail` pool is exempt from the freeze on both
sides (`protectedModeRefusesStart()` lets it start, `stopAgentsForProtectedMode()`
leaves it running) — its raw half, that is: the durable deliveries it holds are
dropped when the freeze silences unstopped writers and refused until it lets go
(HIL-1060) — because otherwise the only channel out of a stuck node is
dead exactly when it is needed. **The log line comes first and always** — it is
the one report that cannot fail, and a delivery failure never changes the
watchdog's own state.

`HILOS_STATUS` is preserved with `HILOS_MAIL` by the worker start gate and stop
walk, and by the list of agents awaiting a worker. In the worker server these
are `protectedModeRefusesStart()` and `stopAgentsForProtectedMode()`; the same
preserved list feeds the published daemon picture. This permits public file
publication only, never ordinary ORM writes or durable mail. The Status owner
still obeys DB silence in every occupied freeze phase. The exact boundary is
[Status under freeze](status-page.md#freeze-and-disconnection).

### The Current Watchdog Projection On Maintenance

The current judge and the mail notifier have separate state. On every tick with
a runtime row, `ProtectedModeWatchdog::tick()` returns the internal
`ProtectedModeWatchdogSnapshot` with the current verdict and effective
configuration, whether or not a reminder is due. It is a value object, not a
signal payload. With no runtime context or row the method returns `null`; an
inactive row returns a clear snapshot with a null verdict and an empty
pending-node list. Progress can clear `SILENT` from the current screen, but
does not send an all-clear email. The email remains tied to the existing
transition to `inactive`, and is sent only if an alarm was raised. Verdict
priority and the rule that the watchdog never opens the system are unchanged.
Sticky initiator-lost and restored-from-disk facts, reminder timers, and mail
notification history remain private watchdog state.

The presentation lives on the existing node-local singleton
`Hilos::$rt->hilosProtectedModeRuntime`:

| Field | Current presentation value |
|---|---|
| `watchdogVerdict` | `null`, or `initiator-lost`, `restored-from-disk`, `quiesce-overdue`, or `silent` |
| `watchdogPendingNodeIds` | Names for the current `quiesce-overdue` verdict; otherwise `[]` |
| `watchdogQuiesceTimeoutSeconds`, `watchdogSilenceTimeoutSeconds`, `watchdogAlertIntervalSeconds` | Effective thresholds in seconds |
| `watchdogAlertEmails` | Effective recipient list; `null` before publication and `[]` when configured empty |
| `watchdogManualRestartIsNormal` | Latest worker-reported setting, or `null` before a report |

Before first publication the verdict and effective values are `null`, and the
pending-node list is `[]`. Thresholds and recipients become known after the
master reads its process environment; the restart policy remains unknown until
a worker reports it. The snapshot carries resolved integer thresholds and a
recipient list; the RT fields retain their declared null/default-`[]` values
until publication. The shared
`ProtectedModeAlertNotifier::recipients()` reader serves both mail and the
snapshot with the same trimming and fallback behavior. These seven fields are
the presentation addition to the existing singleton; no additional runtime
collection, alias, writer, or owner is added. They do not add history. The
restart-policy value reflects the existing setting; the projection introduces
no new setting or business state.

The version-2 freeze descriptor excludes these seven presentation fields.
Saving strips them, and `restoreFromDisk()` clears all seven even if a descriptor
contains them. Starting a new freeze or returning to `inactive` clears the
current verdict and pending-node list, while the effective configuration stays
on the live row. After a process restart it remains unpublished until the
master publishes a fresh snapshot.

The master remains the registered writer of this runtime singleton.
`ProtectedModeWatchdog` returns the snapshot without writing RT; the existing
`DaemonManager` tick compares all seven values and calls
`updateWatchdog()` through the item action only when one differs, producing one
RT sync for that change. The publisher adds no database query, settings read,
or filesystem read to the master loop. On each node, the row remains local and
is not replicated to peers. The Maintenance page stays on its existing
leader-hosted `HILOS_INDEX` holder, so a browser connected through a follower
receives the state from that same page holder; the follower does not invent its
own watchdog verdict for the page.

The existing `subscription_page_hilos_maintenance` carries these fields in the
pre-response state frame and with stale-state updates. It still uses the existing
admission check, per-viewer copy, `frameForViewer()` privacy pass, and broadcast
fingerprint. There is no additional request, action, companion frame, or route.
The recipient list is hidden as one `HiddenValue` for a viewer, including when
it is `null`, empty, or populated; it is not included in the alert summary or
details, and the hidden list's size is not shown. The restart policy is marked
as a setting field; its current catalog entry has
`admin_view_visible => true`, while the frontend keeps a hideable type for a
future catalog verdict: `Hideable<boolean | null>`. The other five watchdog
diagnostic fields are non-personal technical values, not a raw environment
dump.

Only the Vue Maintenance Overview draws this UI. It reserves one compact,
polite status row above the state card: initiator-lost and restored-from-disk
use danger tone; quiesce-overdue and silent use warning tone. The row truncates
its plain-text summary and keeps “Show details” beside it. While clear, an
inert, `aria-hidden` twin holds the same room. Details open in the ordinary
modal with the full plain-text facts and pending node names; they follow live
verdict updates, and a cleared verdict closes the modal. While the alert remains
present, Close or Escape returns focus to its trigger. Dismissing details
changes no freeze state and sends no action. Moment facts use `formatMoment` in
the reader's time zone; a missing moment shows `—`, never epoch zero or now.

The read-only “Watchdog thresholds” section follows the state and operator
actions in the open, manual, and operation views. It shows quiesce timeout,
silence timeout, reminder interval, alert recipients, and manual-restart policy.
Durations retain their published seconds and units; unknown values show `—`.
The standard `HilosHideable` renders hidden values, while a visible empty
recipient list reads “No recipients configured.” Its footer identifies the
environment sources and the Settings page where the restart policy is changed.
React and Angular Maintenance parity is HIL-1681 and HIL-1682; Circle and Words
do not show these thresholds.

### The Freeze Survives A Restart And A Leader Change

Both belong to the same sentence as the rule above: with nothing lifting a freeze
automatically, the human path out has to actually work.

- **Restart.** The row lives in RT, which is memory only, so a daemon restarted
  mid-restore would come back open. Each node's master therefore writes its own
  row to `protected-mode.state.json` beside `DAEMON_LOG_FILE` on every phase
  change (`ProtectedModeFreezeStore`, published through a temp file so a crash
  cannot leave a torn one) and removes it when the phase reaches inactive.
  `DaemonManager::boot()` reads it after registering the truth source and before
  any server binds, so the first handshake after the restart is already locked
  out. **A file that is there and cannot be read or parsed refuses the startup**
  with the reason named — reading a damaged freeze as "no freeze" opens the node
  on the strength of a parse failure. A missing file is the ordinary startup.
  Every accept key is dropped: it was minted on a 101 that died with the daemon.
  `restoreFromDisk()` keeps the restore initiator's session hash: the legacy
  Backups reopen button in React and Angular uses `belongsToInitiator()`, which
  refuses null, so losing it would lock out the restore operator. Vue now sends
  restore control from Maintenance. Passes, admitted sessions and the circle
  are cleared by choice: a code lasts minutes, and a verifier asks for a new
  one. The restored window admits only the initiating browser. A restored row is still frozen even
  before this node remembers its leader. It accepts the current leader's frames
  and refuses a fresh quiesce outside the verification window. Manual
  maintenance alone clears that hash too, by operation rather than phase,
  returning an empty window; restore keeps its behavior. The separate
  `enteredFromBrowser` flag persists in the version 2 freeze file and survives
  `restoreFromDisk()` even when the manual window loses its session hash, so
  Maintenance still labels the entry as browser or console after a restart.
  Its restart mail follows
  `protected_mode.manual_maintenance.restart_is_normal`; see *Manual Maintenance*.
  On a single node
  `StandaloneProtectedMode::adoptStandingFreeze()`, called from
  `DaemonManager::run()` right after the switch is built, takes the restored row
  over as the freeze it holds, as *Leader change* below does on a cluster: the
  recorded initiator drives it with the ordinary ladder and any other agent is
  refused. Nobody is owed a ready, the operation behind the row having died with
  the daemon; a direct window comes back with its one circle photograph spent,
  so a repeat enable is answered ready without a new circle. A row naming no
  operation or initiator is not adopted.
- **Leader change.** `ClusterProtectedMode::onBecameLeader()` rebuilds the
  leader-side freeze from the row this node carries. Without it `onDisable()`
  from a live and healthy initiator is dropped (`leadsFreezeFor()` on
  `activeFreeze === null`) and nothing can unfreeze the cluster at all. Who had
  already quiesced cannot be inherited — that list died with the leader — so an
  unfinished round starts over with every follower outstanding and usually does
  not close, which the watchdog reports as overdue. Every threshold is counted
  from the moment the watchdog first saw the freeze, never from the clocks on the
  row, or a promotion in the middle of a healthy hour-long restore would report
  it stuck in the same second. Frozen followers and the former leader follow the
  current leader when it sends a frame, and send the next `quiesced` to it.
  The successor already knows the operator: the session hash stands on every
  master's row and is restored with the freeze descriptor (HIL-1305).
- **Initiator moves.** The leader checks the initiator agent type and index in
  disable, verify, progress, pass, circle, and refreeze frames, not the sending
  node. It locates the agent for `ready` through the same placement lookup used
  by signal routing: local delivery, a peer frame to the current node, or a
  warning when placement is unknown. There is no retry or fallback to the entry
  node. A refused enable still answers the requesting node. An agent that moves
  during the verification window may enable again and begin a new quiesce round.
  The row keeps the original node as history, never as the authorization or
  delivery address.

What the watchdog never does: lift, move a phase, touch `passHashes` or the node
list, restart the initiator. Everything past the alert is a person's to decide.

## The "Not In Production" Gate Lives In The Command

The mode itself knows nothing about `APP_ENV`, and must not learn: knowing would
make it useless for the disaster recovery it exists for.

- A test driver command extends `TestOnlyCommand`, which refuses on a
  production-like or empty `APP_ENV` (the same mechanism `test:cluster:inspect`
  and the backup test commands use).
- A real restore does not forbid production at all; it obeys the restore ENV
  matrix instead.

## Who The Freeze Lets Through (HIL-655, re-decided by HIL-718)

`ProtectedModeRuntime::locksOut()` is the whole admission rule, and it shuts a
connection out when four things hold at once: the phase is not
`PHASE_INACTIVE`, **and** the connection is not the initiator's with the
verification window open (`admitsInitiator()`), **and** the browser session
behind it holds no admitted pass (`admittedSessionTokenHashes`, matched by
`admits()`), **and** that session is not in the photographed verifier circle
(`circleSessionTokenHashes`, matched by `admitsCircle()` — HIL-643). Any one of
the last three failing serves that connection the real application, and there is
no fourth way in.

**On a cluster every master's row says the same about a browser (HIL-1305).**
The browser's own master decides its 101 welcome; the node holding its page
agent decides the subscription, so their rows must agree. The operator session
hash travels in `peer_protected_mode_quiesce` with the freeze descriptor. A code
admission travels from the 101 master to the leader and then every master in
`peer_protected_mode_admit`; each receiver checks that the verification window
is open and the code was minted in it. The accept key stays on the node of its
socket. If no leader is known, an admission remains on the 101 master and is
logged.

**The third door opens on a photograph rather than on a presentation
(HIL-643).** The other two are earned at the door — the initiator by having
asked for the freeze, a verifier by producing a code. The circle is decided
before the door exists: an operator names people to the circle and takes them
out in the Maintenance section of the admin surface (`hilos_maintenance`) — the
backup page only points there — and at the
moment the node freezes
`VerifierCircleSnapshot::capture()` reads that list against the live connections
and writes the session hashes of the members who were online onto the row.
Nothing is presented afterwards; the tab that was already open walks in.

**The section's signed-in mark is live, and it is not the photograph (HIL-1119).**
Beside each named address the section says whether that person holds a live
connection right now: `HilosVerifierCircleTable` hears the project's collection
of live session connections, and a named person's tab opening or closing
re-draws that person's row without a reload. The mark only helps an operator
see, before an operation, who would be let in; what decides is still the
photograph above. Two limits come from how live tables work: a person named by
two addresses has only one of the two rows re-drawn live (one source change
gives a table one row mutation — the other catches up on the next draw), and
a tab passing directly from one person to another — an administrator starting
or stopping impersonation — re-draws only the new person's row, for the same
reason. Signing out on an open tab names who left through the binding it
replaced, and that person's mark goes off (HIL-1141).
The section refuses a second address of a person already standing in the
circle (HIL-1120), so two new rows of one person can now come from two admin
tabs naming one person by two addresses in the same second, both past the
read the refusal is decided by. Rows named through the backup page block before
it left (HIL-1122) may also remain — for all of these rows the first limit holds.
No index closes the race: the circle stores no user id, because after a
restore that id would name somebody else.

The order matters and is not an implementation detail. The photograph is taken
under the freeze, because for any freeze that is the first moment the answer is
final: the node is quiesced, so the set of live connections has stopped
changing, and only somebody signed in at that moment is admitted. A restore
adds a reason of its own, and it is why the photograph is taken
**before the database is replaced**: that is also the last moment the question
has an answer — the circle table lives in the database a restore rewrites, and
afterwards the archive's own circle applies — and resolving a person to a
session later would mean reading a session table the restore has replaced.
Direct entry is the exception: the node is not quiesced, and the photograph at
closing is chosen by rule rather than forced by a swap; see *Manual Maintenance*.

The read and the write sit on opposite sides of a process boundary and neither
may cross it. The circle is three database queries, which the master is forbidden
(`antipatterns/heavy-work-in-master.md`), while the freeze row is the master's to
write. So the worker that hosts the initiator photographs it on the ready relay
and sends the result under the initiator's name on the `PROTECTED_MODE_CIRCLE`
frame — the twin of `PROTECTED_MODE_PASS`, whole rather than one entry at a time,
because there is exactly one moment when the list is knowable. No agent sends the
circle itself. Only hashes and a count travel; no address of anybody named
reaches the master. In a cluster the photograph is fanned to every master the
way a code is: the initiator's node sends it to the leader on the
`peer_protected_mode_circle` frame, and the leader writes its own row and sends
it on to every follower master. A member's tab connects to whichever node the
balancer hands it, and each node decides admission against its own copy of the
row. A follower initiator writes its own row before it sends, so with no leader
known that row still holds it and the rest of the cluster lets the circle in by
code alone. An initiator on a slave has no freeze row to write — the freeze
frames reach masters only — and sends the photograph to the leader all the same:
the leader authorizes it by the agent type and index carried in the frame, as it
does every frame of the window, and that authorization is all either half
checks: neither reads the phase.

Whatever the operation, the photograph is taken for the initiator of the
mode, on its ready path; a restore is today's only destructive
operation, not a condition. `WorkerManager::handleProtectedModeReady()` takes it
before it calls the initiator's `onProtectedModeReady()` — the relay is the one
point every ready passes on its way to any initiator, while the hook is
overridden without `parent::` — and queues the frame, so whatever the hook
queues next, a test drive's answer to its enter among it, leaves after the
circle. An empty photograph is sent too: in a direct window the master must
receive it before it can finish opening, and a failed read is logged and sent
as an empty photograph. Until HIL-1118 two carriers each took it in their own hook, the backup
agent and the test drive, each under a declaration of its own; declared by one
agent, the read was refused in every other worker, the initiator's included
(HIL-1096). Now it is read by one rule — a process-wide read of the framework,
*Readers Past The Agent* in [truth-source.md](truth-source.md) — and no
initiator declares it. A photograph that could not be taken is a line in the
initiator's error log and nothing to the operator, a refused read named apart
from any other failure. The empty photograph still completes a direct entry,
so the row then carries `circleNamedCount = 0`.

Without `HilosFeature::BACKUP` the circle loses nothing: the freeze alone admits
its member, and the Maintenance section shows it the same way. Somebody is named
and taken out there too, and empty means nobody was named.

**Both presented doors are gated on `PHASE_VERIFYING`, and for one reason: that is the
first phase with anything behind them.** Under `PHASE_ACTIVATING` and
`PHASE_ACTIVE` the executor has stopped every agent but the one driving the
operation, so no page subscription is answered — admitting a browser there would
hand it a dead application rather than the maintenance surface, where the restore
panel is the one screen still being fed. So the freeze holds every browser, the
initiator's own included, and the window is where they come back. The phase is
what enforces this rather than an assumption about the row: the accept key and
the session hash are recorded on the way in and outlive the freeze until the
lift, the way `admits()` is enforced by the phase rather than by the emptiness of
its list.

The public `/status` file reader is the sole page exception: nginx serves it
without a page subscription, including on a cold load in full freeze. The built
Vue shell keeps its read-only page available through the first-frame hold,
disconnect, stale heartbeat and page-level access reactions. It stays outside
the maintenance stub, which links to it as **Service status**. This opens no
application subscription or mutation and does not enable shell requests,
actions or modal controls; see [status-page.md](status-page.md).
The same browser exception belongs in React (not in the code yet — HIL-1695)
and Angular (not in the code yet — HIL-1696).

**Both halves of the rule are keyed by the browser, and for the same reason
(HIL-666).** The verifier reads a code out once and then behaves like a person:
reloads, opens a second tab, follows a link. Each of those arrives with a
brand-new accept key and the same cookie, so an admission written against the
socket admitted exactly one tab and left the rest of that browser on the stub
holding a code the node had already accepted. What is stored is the hash of the
session token, through the same one door the initiator half uses
(`hashSessionToken()`), and it is compared with `hash_equals()` in a loop rather
than `in_array()`, because both sides are derived from a secret. The list is a
list because several people may verify at once, and the code stays reusable. The
circle's list is keyed the same way and compared the same way, for the same
reason — it is the same kind of value on the same row, differing only in how it
got there.

**The code did not go away when the circle arrived (HIL-643), and will not.** The
circle admits only somebody who was signed in when the freeze started, so
everybody else has nothing else: a colleague reached by phone, an operator on a
machine they had not logged in on, anyone at all after a restore whose archive
does not know them. The code is the emergency entrance, and an emergency entrance
that answers to a list is not one.

**The record is idempotent, and the frame is not.** `admitSession()` refuses to
add a hash it already carries, so a browser is one entry however many of its tabs
present the code. Whether this presentation was a *crossing* is asked separately,
by the executor, before the write: `DaemonProtectedModeExecutor::admitVerifier()`
reads `admits()` first, and only a false there earns the announcement below.

**The initiator is recognized by two halves because one of them does not survive
a reload.** The accept key names the socket that asked for the freeze and dies
with it. An F5 inside one's own restore, or a second tab, arrives with a
brand-new accept key and the same session cookie — so with the accept key alone
the operator would come back from a reload as a stranger once the window opened,
which is what the session half closes. Inside the freeze the two halves say the
same thing as every other browser, and that is the point of the pair: two tabs of
one person must never disagree about which screen they are on (HIL-718).

**Two nulls deliberately do not meet.** A freeze entered with no browser behind
it — a CLI trigger, a scheduled run — leaves `initiatorSessionTokenHash` null,
and a connection carrying no session cookie hashes to null as well. Let those
two match and such a freeze would open the node to every cookieless visitor on
it, so `belongsToInitiator()` refuses both nulls before comparing anything, and
compares with `hash_equals()` because the stored side is derived from a secret.

`ProtectedModeRuntime::hashSessionToken()` is the one door to that algorithm.
The three places that need it — the agent recording the initiator, the master
gating a handshake, and the row comparing them — go through it rather than
spelling the hash out separately, so they cannot drift apart.

**The gate stands in two places, and both hand the row the same pair.**
`WebSocketClient::protectedModeLocksOut()` answers the 101 and shapes the
welcome frame; `BrowserContext::protectedModeLocksOut()` answers a
`page_subscribe`, taking the session token from the runtime connection roster
and hashing to null for a connection the roster does not carry.

**Both ways in therefore need the session stage of that roster**, and since
HIL-666 the verifier's way in needs it as much as the initiator's: a project that
mounts connections at the presence stage answers null from
`sessionConnectionsSource()`, so the worker asks the row about a null hash and
refuses the subscription with `PageServiceUnavailableException` — while the
welcome, which derives the hash on the master and needs no roster at all, has
already told that browser it is inside. `SessionStageStartupGuard` refuses that
configuration before the daemon composes or binds a server. Its daemon error
names the mounted roster, the runtime context that mounted it, the presence base
it extends, and the session-stage base it must extend instead, so the project
author fixes the mount rather than meeting a half-working verification window.

**The welcome is personalized; the broadcast is addressed by browser.** Every
connection is answered with a welcome computed for itself, which is why a reload
and a new tab need no further state to be told where they stand. The push that
announces the mode goes through
`ProtectedModeClientNotifier::notifyProtectedModeState()`, which is told two
exclusions and not one: `excludeAcceptKey` keeps the socket that asked out of the
broadcast, and `excludeSessionTokenHash` keeps every other tab of that same
browser out with it (HIL-666, absorbing HIL-748). They stay two arguments rather
than one because an initiator with no browser behind it — a CLI trigger, a
scheduled run — leaves the hash null. The exclusion is applied to this master's
local connection list before one `WS_USER` is queued per matching accept key.
Every address of this port stays on its master; no frame crosses to another
master, so a tab receives the phase once (HIL-1305).
The port has a third address beside those two,
`notifyProtectedModeLockedOutState()`, which names no exclusion at all: the
master walks this node's connections once and asks the row about each one with
the same `locksOut()` the welcome is composed with, then queues one frame by
accept key for every connection the row still holds (HIL-1082).

**Whom to spare is the caller's, and it changes by phase (HIL-718).** Entering
the freeze (`enterActivating()`) spares nobody, and closing back into it is the
same entry since HIL-1128: there is no application to keep the operator in, so
its tabs go to the same stub as everyone's. The verification window is the one caller that
excludes, and it excludes in order to say the opposite: `enterVerifying()`
broadcasts the stub to everyone still outside, and `finishVerifying()` then
addresses the initiator's session on its own (see below). Direct entry writes
`verifying` and sends ready without a roster walk. Once the worker's circle
frame arrives, `finishVerifying()` first sends the window frame to connections
the row still locks out, then sends the personal frames. A repeated direct
enable may cause another worker photograph, but the master ignores it and does
not send another set of frames. `announcePassIssued()`
spares nobody by argument, because its frame is the one whose verdict is meant
for the held alone: it goes to every connection the row still locks out, and to
nobody else. An exclusion would have to list everybody the window has let in —
the operator by both halves of their identity, every session of the circle, every
pass holder — and the circle, which the old exclusion missed, was put back on the
stub by the first code with its page taken down under it (HIL-1082); a repair
frame after it would still take the page down and put it back. On a cluster each
node announces for the browsers attached to it, judged by its own row, and the
announcement no longer crosses nodes.

**A verifier is pushed to as well, and by session (HIL-666).** Nothing tears a
connection down when the mode turns on (`ConnectionDropper` is called only by a
session rotation and by `test:connection:drop`), so a tab that was standing on
the stub when its owner typed the code in another tab would stand there for the
rest of the window. On the crossing each master sends its own connections of the
admitted session one addressed `WS_USER` frame each: `active: false` with
`acceptsPass: true`. The second bit is load-bearing — the client calls the mode
over only when both are false, and a frame without it would reload the tab out
of the window instead of into it. The same crossing, in
`DaemonProtectedModeExecutor::admitVerifier()`, then has the session's open
pages answered again (see below, HIL-912).

**The operator and the circle are carried back in the same way, and by the same
delivery (HIL-718, HIL-912).** After the ordinary roster is back, or immediately
after the direct window's photograph, `finishVerifying()` sends a
frame addressed to the initiator's session and to every session of the circle
photographed at the freeze: `active: false` with `acceptsPass: true`, `passIssued`
read off the row because a pass can be minted while the roster comes back. The
set is keyed by the hash, so an operator who is a member of their own circle is
told once, and each session is tried alone, so one failure does not cost the
others their window. Two things about this frame in particular. It is a second
frame rather than the broadcast sent without its exclusion, because a personal
frame racing the general one would arrive in either order, and losing that race
would leave the operator on the stub in a system that is running again. The
circle is not excluded from that broadcast at all: it went out with the phase,
before the roster, so the personal frame lands after it and a circle tab holds
the stub only for the gap between the two. And when the freeze recognized no
session and photographed no circle — a CLI restore, or a browser whose session
the agent could not read — no frame is sent at all, there being nothing to
address it to.

**Leaving the stub is not the whole way back, so the pages are answered again
(HIL-911, HIL-912).** Those tabs come out onto the pages they already had, answered while
the phase was still inactive — and the backup page built its reopen block from that phase,
so the banner would tell the operator to reopen the system from a page offering nothing
to press. Every browser the window lets in therefore has its personal frame followed by
`ProtectedModeClientNotifier::reassessPagesOfSession()`, which re-decides every open page
of that session: the operator and the circle from `finishVerifying()`, a pass holder on
the crossing in `admitProtectedModeSession()`. It is the ordinary re-decision of an open
page, by its session criterion ([page-access-control.md](page-access-control.md)),
answered with a whole `page_response` the client ingests in place. It runs after the
agents are resumed, because a page is answered by the agent serving it, and it is skipped
exactly when the session frame is — no session, nothing to answer.

The re-decision reaches only a page the worker holds a subscription for, and a tab
loaded UNDER the freeze holds none: the client refuses every outbound frame while the
mode holds it, the page subscribe included. That half is the client's — the tab sends
the subscribe it could not send when the personal frame says the mode no longer holds
it, and the page is answered like any subscribe
([core-and-connection.md](../frontend/core-and-connection.md)). A page the server
already holds is left to the server, so no tab is answered twice.

The notification bell is the second sender a freeze refuses, and its half differs in one
point. A group has no server-side re-answer — only a join answers it
(`AbstractGroup::onSubscribe()`) — while its membership outlives the freeze, so a tab
that joined before the freeze would carry the snapshot of the database the restore
replaced, with the restore's own outcome notice written into the new one. So the
notification binder joins again whenever the mode stops holding the connection, whether
its earlier join was refused or answered; the server records the membership by name and
answers again, so a tab is never a member twice (HIL-1079,
[core-and-connection.md](../frontend/core-and-connection.md)).

**A tab that closes while the freeze holds its agent is told to that agent when the
lift brings it back (HIL-1208).** The browser sends nothing under the freeze, so the
one frame a stopped agent is still owed is a connection's close: its worker keeps the
subscription mirror, the page's reader interest, the page router with its pages and
the agent's own subscriber sets through the stop, and the lift puts the agent back
into that same worker. The master holds the close at the delivery door
(`DaemonManager::sendSignalToAgentDestination()`) for an agent the freeze stopped on
this node and the lift has not asked back yet
(`WorkerServer::awaitsProtectedModeResume()`), without starting it, and lets it go on
the agent's start report like any held frame; the worker's ordinary close does the
rest. The hold sits on the agent's node, where the close is refused. A walk that
passes the agent without bringing it back here drops the close with a warning. Every
other frame for a stopped agent is still dropped.

**On the way out the frame goes to everybody, the initiator included.**
`DaemonProtectedModeExecutor::enterInactive()` passes no exclusion at all, and
the frame means reload: after a restore the initiator's data is as stale as
anyone's. What the browser does with that frame, and why the reload is a full
one rather than a re-subscribe, is the client half in
[../frontend/core-and-connection.md](../frontend/core-and-connection.md).

## Manual Maintenance: The Window Without An Operation (HIL-1291)

The section has an Overview page, `hilos_maintenance` at
`/hilos/maintenance`, a child Circle page, `hilos_maintenance_circle` at
`/hilos/maintenance/circle`, and a child Words page, `hilos_maintenance_words` at
`/hilos/maintenance/words`. `AbstractHilosMaintenancePage` still owns the
Overview state frame, subscriber tracking, and the manual enable, disable and
pass handovers described below. `AbstractHilosMaintenanceCirclePage` is a
separate page served by the same index agent; it owns the circle add/remove
actions, their DTOs, validation, refusals and acknowledgements, and the
`HilosVerifierCircleTable` row actions and viewport. Its row copy, `Hideable`
identifier, member id, focus, dialog and online-presence behavior stay the same,
without a card wrapper. It has no manual-state hook, subscriber or timer.
`AbstractHilosMaintenanceWordsPage` is also served by the index agent and
displays the project's protected-mode stub registry as a read-only table via
`HilosMaintenanceWordsTable`, indicating default stub fallback via rowMessage for
operations with no own entry. The shared catalog head identifies the owner as
Hilos › Maintenance and provides Overview/Circle/Words tabs in the existing page
response, without child cards or new wire fields or signals.

All six demo backends register the Circle and Words pages and their table bindings.
In the current frontend transition, only Vue builds the Circle and Words views.
React and Angular keep the circle table on Overview and mark Circle and Words unbuilt,
so their tabs are hidden and a direct Circle or Words address returns `not_served`;
their Overview `PAGE_TABLES` entry remains until HIL-1681 and HIL-1682. Circle
add/remove actions are already owned by the Circle page, and dispatch does not
require a subscription to it, so the existing React and Angular Overview UI
continues to work.

An agent requesting `verification_window` takes the row from `inactive` straight
to `verifying`, skipping `activating` and `active`: no agents stop or restart and
no unstopped writer is silenced. The window already permits agent starts
(`WorkerServer::protectedModeRefusesStart()`); writer silence already applies only
in `activating` / `active` (`ProtectedModeRuntime::silencesUnstoppedWriters()`).
Why skip the full freeze: the writer rule in *The Freeze Is Also The Window For
Repairing What The Operation Broke* explains the discarded node analytics journal
(counted as restore loss) and dropped durable mail deliveries until silence ends.
Stopping and restarting agents would also put the initiating browser on the stub.
The owner's rule is "the door is closed, the house keeps working": a direct
window cannot become a full freeze through repeat enable or refreeze.

The index agent owns entry, disable of its manual window and code minting, with
stated refusals; its test open already requires one instance per cluster. Its
third CLI family has those three commands (`maintenance:enable`,
`maintenance:disable`, `maintenance:pass`), with names distinct from backup's
`protected-mode:*` and the test-only `test:*`, since a command routes to one
agent type. Enable prints no code; a lost reply is checked through
`protected-mode:inspect`.

The index agent accepts manual entry only from `inactive` and asks for
`manual_maintenance` with `entryMode='verification_window'`. A browser request
carries both its accept key and session hash; a CLI request carries an empty key
and no session hash. Disable and mint require this exact verifying window and
the index agent's recorded type and index. One request may await confirmation at
a time across manual, test, and operator paths: the ready/refused relay carries
no request id. Enable answers on ready, disable on `inactive`, and mint only when
its hash appears on the row. An expired wait is *unconfirmed*, since a late
signal may still change the row; a late mint can leave a hash whose clear pass
has been discarded, so close and reopen the window to void it.

The test family cannot drive a manual window. `test:protected-mode:enter` refuses
the reserved `manual_maintenance` operation even from `inactive`, so it cannot
create a full freeze under that name. `test:protected-mode:open` and
`test:protected-mode:pass` refuse a manual window, and
`test:protected-mode:close` refuses any direct verification window before the
core would drop its refreeze request. Ordinary test freezes and restore's
operator commands retain their own paths.

Visitors get the stub; inside are the initiating browser (when one exists),
the circle photographed at closing, and code holders. The master waits for the
worker's photograph, including an empty one, before it sends the locked-out
frame and personal admission frames. The `passIssued` bit is read from the row,
so a code minted before the photograph is not hidden by the opening frame.
The browser uses the same accept-key and session halves; two nulls never meet,
so CLI entry has no initiating browser. With the node running and no database
swap, the photograph's moment is chosen by rule: a circle member arriving later
or named during the window enters by code.

The initiator agent may disable its direct window. The row becomes `inactive`,
the state file is removed, and the lift frame goes to every connection at once.
There is no stopped roster to resume and no lifted-roster hook to wait for.

Any admitted admin may open the system from Maintenance: the page and agent
check admin rights, session admission and that the window is manual. The page's
`maintenance_disable` action accepts an empty `MaintenanceDisableActionDTO`;
identity is resolved on the server. It stays behind the ADMIN gate, outside
`AGENT_ACTIONS` and `READING_ACTIONS`. The page refuses a missing session or
runtime row, a non-verifying or non-manual window, and a locked-out session
before forwarding. `maintenance_agent_disable` carries
`ManualMaintenanceDisableSignalData` through the existing handover to the
index agent, which repeats `PageAccessGate::assertAction()` and admission before
calling `disableManualMaintenance()`. The existing agent-type/index and
single-request checks remain. The CLI may also disable through `maintenance:disable`; the exit
is the same `inactive` and reload for everyone. Restore's window still opens
from Backups only for its initiating browser. Minting in Maintenance is only for
this window and shows the code once. The CLI mints too via `maintenance:pass`,
only in the manual window. The code uses secure randomness; only its hash
stands on the row, its clear value in the reply. One code admits several
browser sessions while the window lasts. Closing the result does not revoke
it; disabling maintenance or restarting the daemon invalidates every code.

The watchdog exempts the manual window on the row (operation `manual_maintenance`
with `entryMode` `verification_window`; a full freeze carrying that name is still
judged) from `SILENT`: no work owes progress behind that door. This is an operation
exception, not phase branching; other verdicts stand, and the watchdog never lifts.

On restart, phase, operation and initiator agent identity survive; codes,
admitted sessions and the circle are cleared by the ordinary restart rule.
Manual maintenance also clears the initiating browser's hash, returning an empty
window, by operation, not phase; restore keeps its hash. Agents start in `verifying`. Enter with a new
CLI code (`maintenance:pass`) or disable from the CLI (`maintenance:disable`). A
settings-catalog key, `protected_mode.manual_maintenance.restart_is_normal`,
defaults to on: one warning line per restored freeze, without mail. Off gives
`RESTORED_FROM_DISK` mail and reminders as for restore. Projects without this
key in their catalog or without settings use the default. Registration starts a
worker's first report; a cataloged key waits for the settings source's readiness
before that read. After each settings-row change, workers re-read the key and
report a changed value to the master via `worker_protected_mode_settings`; the
master does not read the database. Until the first worker reports after restart,
the manual window gets neither a routine-restart line nor a restored-from-disk
verdict.

`Hilos::PROTECTED_MODE_STUB` maps operations plus a default to copy, is
overridden wholesale by a project, and refuses startup without both the default
and `manual_maintenance` entries or with missing or extra copy fields. The manual
entry says "closed for maintenance", with no promise of a brief wait or automatic
return. Its admitted banner names manual maintenance
and says the system is closed to visitors, naming the Maintenance section in
words, with no disable button. Projects may replace the registry, but must
provide their own `manual_maintenance` entry too.

Restore under the manual window is refused as a foreign freeze; disable manual
maintenance first, accepting the visitor gap before restore freezes the node
(owner decision, 2026-10-04); manual entry under another freeze is refused too.

The Overview page shows open / manually closed (since when, button or CLI, not
who) / operation running, the last without manual controls. It sends a
`subscription_page_hilos_maintenance` state frame before `page_response` and
pushes changed frames after protected-mode RT sync through the index agent's tick;
locked-out subscribers receive none. The frame counts passes and the captured
circle without sending personal fields. The enable action is on the `ADMIN`
page, not in `AGENT_ACTIONS` or its reading-action list: a viewer cannot send it.
The empty `MaintenanceEnableActionDTO` lets the browser choose no identity,
operation, entry mode or visitor message. The page resolves the accept key and
session hash on the server and refuses a missing session before forwarding.

`HandoverGatekeeperTrait` sends `maintenance_agent_enable` with
`ManualMaintenanceEnableSignalData` to the index agent and defers the tracked
ack. The agent calls its existing `enableManualMaintenance()` and answers under
`maintenance_enable_done` with `HandoverAnswerSignalData`. Both routes come
from their owners' declarations. The correlation travels with the ask and
answer, so the page may be recreated and re-answered on admission before the
action completes; no pending request is held on the page instance.

Only `ManualMaintenanceOutcome::SUCCEEDED`, after protected-mode ready, produces
success and the sentence "Maintenance is on — the system is closed to visitors".
A state push showing manual maintenance is not the action's answer. `REFUSED`
carries the agent's reason. `UNCONFIRMED` carries "The result of turning on
maintenance is unconfirmed. Check the state in Maintenance before trying again."
followed by the original reason: it does not claim the system stayed open. The
existing 13-second agent wait remains the only server wait, within the client's
30-second action timeout, and a late ready produces no second answer. The
session hash stays inside the request and is absent from the outcome and ack.

The open-state banner carries a yellow **Turn on maintenance** button,
below its text on a narrow screen. Unknown, manual and operation states carry
no enable button. Its confirmation explains the visitor screen, the initiating
tab, the captured circle and later code holders staying inside, and the agents
continuing to work. There is no visitor-message field; the footer names admitted
administrators as those who may turn it off.

The core owns `MAINTENANCE_ENABLE_ACTION`, `sendMaintenanceEnable()` and
`HILOS_MAINTENANCE_ENABLE_COPY`; the action dispatches exactly `{}`. Each SDK
binds its tracked-action driver (`useTrackedAction` in Vue and React,
`createHilosTrackedAction` in Angular), `LoadingButton` and `HilosActionError`.
Before submission, Cancel, the cross, Esc and backdrop close without sending.
While waiting, every close and repeat submit is blocked. The dialog is mounted outside the open-state
banner: a manual push replaces the banner without unmounting the pending submit.
A changed position blocks an unsent confirmation; an already sent action waits
for its own answer. Success closes and the ordinary tracked lifecycle shows the
server's one success sentence. A refusal stays readable in the reserved error
plate, with Cancel available again. Client timeout, disconnect or unreadable reply
show the unconfirmed instruction above, with no automatic retry or claim that
the system stayed open.

Disable answers through `maintenance_disable_done` and the existing
`HandoverAnswerSignalData`, preserving correlation after page recreation and
keeping the session hash off the answer. Only observed `inactive` succeeds;
queueing the request or receiving a state push does not. Refusal carries its
reason; an unconfirmed result says "The result of turning off maintenance is
unconfirmed. Check the state in Maintenance before trying again." followed by
the reason. The same 13-second server wait and 30-second client timeout apply,
with no retry or extra waiter. A late inactive produces no second answer.

The manual card alone offers the yellow **Turn off maintenance** button.
Its confirmation explains opening to everyone, every page reloading itself,
issued codes losing admission and a new enable being needed to close again.
The core owns `MAINTENANCE_DISABLE_ACTION`, `sendMaintenanceDisable()` and
`HILOS_MAINTENANCE_DISABLE_COPY`; dispatch sends `{}`. The modal uses the same
tracked action, reserved error plate and busy-close rules as enable, and stands
outside the manual card. A changed state blocks unsent confirmation without
unmounting a pending request. Client timeout, disconnect or unreadable reply
show the unconfirmed instruction. The existing lift clears the freeze and
admission, removes its file and sends `protected_mode_state {active:false}` to
every connection. The common connection handler reloads each document, including
the actor's, without waiting for the ack. The component never reloads locally;
if the ack arrives first, the ordinary tracked lifecycle may show the one server
sentence "Maintenance is off — pages are reloading".

The manual card also offers **Issue code**. Its confirmation sends the
tracked `maintenance_pass` action with an empty `MaintenancePassActionDTO`;
untracked requests are refused before minting. The page resolves the session
and checks the verifying manual window and admission. Its ADMIN gate keeps
this write outside `READING_ACTIONS` and `AGENT_ACTIONS`. Any admitted admin
may ask, whether admitted as initiator, circle member or code holder, and
whether the window was opened in a browser or from the CLI.

`maintenance_agent_pass` carries `ManualMaintenancePassSignalData` through the
existing handover. The index agent repeats `PageAccessGate::assertAction()`,
compares the current server session hash to the ask and checks admission, then
calls the existing `mintManualMaintenancePass()`. The existing single-request,
window and initiator-agent checks apply. Only observing the new hash in
`passHashes` succeeds. `maintenance_pass_done` carries a local typed wrapper,
`ManualMaintenancePassDoneSignalData`, with the unchanged handover answer and
a nonempty `pass` only on success. A refusal carries no code. Before disclosure
the page repeats the current access, session and admission checks; loss of
access answers the tracked submit with a failure. Success returns
`MaintenancePassReplyDTO` only in that submit's `::success.reply`, with the
sentence "Code issued". No code enters the page state, count push, broadcast,
error, toast, log, URL or browser storage.

The core owns `MAINTENANCE_PASS_ACTION`, `maintenancePassReplySchema`,
`HilosMaintenancePassReply`, `sendMaintenancePass()` and
`HILOS_MAINTENANCE_PASS_COPY`. A missing or empty reply code is `invalid-reply`.
Each SDK uses its existing tracked runner, loading button and reserved refusal
plate. Pending blocks every close and repeat submit; a changed state blocks
an unsent confirmation, and the modal stands outside the conditional card.
A confirmed reply replaces the confirmation with **Verification code**, focuses
the code, and offers the modal's standard Copy/Copied alongside Close. The raw
value is text and remains selectable when clipboard access is unavailable.
Closing by any route or leaving the page forgets it; reopening starts a fresh
confirmation. A late promise after unmount cannot restore the result.

Refusals retain their reason. Unconfirmed server outcomes append the original
reason to: "The result of issuing a code is unconfirmed. A code may have been
issued, but it cannot be recovered. Issue a new code if needed; turn maintenance
off and on to invalidate all issued codes." Client timeout, disconnect and
invalid reply show the same instruction. There is no automatic retry or secret
recovery. The existing 13-second owner wait and 30-second client timeout remain;
a late hash produces no second answer. The ordinary state push updates only
the pass count for every admitted subscriber.

Enable, disable and code issuance are disabled for a view-mode viewer,
including their confirmation openers, in each of the three SDKs. There is no
extra project wiring: the index agent already serves this page and owns manual entry.

In a cluster all masters close, and the three CLI commands work from any node,
including a slave. The index agent asks through its own master and the leader
drives the round. The leader enters its own direct window and sends
`peer_protected_mode_window` with the existing `ProtectedModeQuiesceData` to the
follower masters. Each follower enters `verifying` and answers `quiesced`,
including on a repeat window frame. The leader and followers read `entryMode`
from their own `runtimeView()` row; the descriptor carries no entry mode.

The round waits only for follower masters: no agent roster stops, no agent-start
gate closes, and no `active` or `peer_protected_mode_settled` follows. Ready goes
to the initiator only after every follower has answered. A pending follower past
the quiesce timeout raises `QUIESCE_OVERDUE` even though the leader already reads
`verifying`; the watchdog names the pending nodes and never opens the system.

The worker photographs the circle on ready. The leader accepts that photograph
once and distributes it to the follower masters, including an empty photograph;
each master finishes its window once. Repeat enable answers ready without
starting a new round, replacing the circle or stopping agents. A freeze or
refreeze cannot turn the direct window into a full freeze. Pass hashes reach
every master. Disable sends the ordinary lift to the followers and calls
`enterInactive()` plus `finishLift()` on both sides, with no `deactivating`
phase or roster walk. Every master clears its passes and removes its state file.

A successor leader adopts a direct `verifying` row as an established window,
without a new ready or circle photograph; the recorded initiator may still mint
and disable. A restarted master restores its own state file before opening its
entrance. The ordinary restart rules above clear codes and admission, and the
direct window's photograph is already spent, so a repeat enable cannot take a
new one.

Two late-master limits apply to every freeze, including manual maintenance
(owner decision, 2026-10-16): a master joining during a window without a freeze
file stays open; a master returning with an old freeze file after disable stays
closed until that file is removed manually. There is no catch-up synchronization
for either case.

## The Restore Ladder On Maintenance (HIL-1679)

Under operation `restore`, phase `verifying`, and not a manual window, the
initiating browser on Overview (`/hilos/maintenance`) sees three empty tracked
actions: `maintenance_restore_pass`, `maintenance_restore_open` and
`maintenance_restore_close`. Another admitted administrator sees the section's
state and one line, and those three actions stay off. The right is
`ProtectedModeRuntime::belongsToInitiator()` on the session hash. The same
user id is not that right.

The page sends `maintenance_agent_restore_control` to `HILOS_BACKUP`, the agent
that also answers `protected-mode:pass`, `protected-mode:open` and
`protected-mode:close`. The reply is `maintenance_restore_control_done`.
Browser and CLI share one slot on `ProtectedModeOperatorTrait`. The browser
ask is that agent's `protectedModeOperatorAsk` for the span of the control.
It is not a field of the runtime row.

A pass is disclosed once, after its own hash stands on the row, and only after
the page repeats the admission check. Open goes through
`BackupAgent::requestProtectedModeRelease()` and `RestoreReleaseGate`. Close
returns the node to a full freeze and withdraws a release the gate is still
holding; a reopen asked while that close is in flight does not win. A wait
that runs out refuses the ask. It does not open the system.

A full freeze still stops the page agent. The preserved list and the delivery
silence were not given an exception so the modal could stay up. Close sends
the node back into that freeze, and the lockout frame already owed to every
connection takes the Maintenance page and the modal on it together. Open's
lift can reload the browser before the action ack, as a manual disable already
does.

Vue draws the ladder and sends the reopen control from Maintenance. React is
HIL-1681 and Angular is HIL-1682; their legacy Backups reopen action still uses
`backup_reopen` until those frontends move to Maintenance.

## This Node's Live Hold (HIL-1679)

The node-local row `hilosProtectedModeRuntime` carries four live fields:
`diagnosticsNodeId`, `stoppedAgentCount`, `preservedAgentTypes` and
`agentStartGateClosed`. They are this node's hold. They are not a sum across
the cluster and not a history of agents that were stopped earlier.

`stoppedAgentCount` is the distinct agents in the stopped set and in the
resume queue the walk has not asked to start yet. A start already asked for
has left that count. Agents still only in the stop queue are running, so they
are not in it.

`preservedAgentTypes` is the one list both the start gate and the stop walk
already pass. Today that list is the mail agent. The initiator is spared
because the row names it, and the list does not contain it.

`agentStartGateClosed` is the same phase verdict the inspect snapshot reports
once the row is mounted: closed unless the phase is `inactive` or `verifying`.
In the verification window the gate is open. Until the master has published,
the four fields stay null. That null is not a count of zero and not a gate
reading false.

The master writes the tuple from `publishProtectedModeAgentDiagnostics()` on
the pass immediately after `advanceProtectedModeRoster()`, and only when the
tuple changed, through the item action `updateAgentDiagnostics()`. The freeze
file does not hold these fields as truth: `restoreFromDisk()` sets all four
to null.

The Maintenance frame carries the same four fields, the initiator, `activatedAt`
and `progressAt` as ISO-8601 UTC, and `isInitiator` filled in on each delivery
from `belongsToInitiator()`. The frame carries no session hash and no code.
The reader that asks the row for this hold is named in the table below.

## The Nodes Read Side

The framework-owned [Nodes page](../../../framework/backend/Pages/Maintenance/AbstractHilosMaintenanceNodesPage.php),
`hilos_maintenance_nodes` at `/hilos/maintenance/nodes`, shares Maintenance's
head and tabs. Its applicability follows the installed cluster mode: without
`Hilos::$cluster->isEnabled()`, a direct page subscription is refused as not
found. A table query on a standalone installation follows the ordinary
`table_window_refused` path; it is not a page 404 response. All six demo
backends register the page and table. The Vue mounts are in chat and
binance-btc-tracker; the React and Angular mounts remain with HIL-1681 and
HIL-1682. The [core table reader](../../../framework/frontend/core/src/admin/maintenance/hilosMaintenanceNodes.ts)
owns the presentation and copy, and the [Vue page](../../../framework/frontend/vue/src/admin/maintenance/HilosMaintenanceNodesPage.vue)
draws it with the standard viewport and cards. Nodes adds no payload to the
Maintenance Overview.

One read-only [HilosMaintenanceNodesTable](../../../framework/backend/Tables/ProtectedMode/HilosMaintenanceNodesTable.php)
uses table key `hilosMaintenanceNodes`, slot `node`, and row key `nodeId`. Its
window is 25 rows, initially ordered by `nodeId` ascending; `nodeId` is its only
sortable field. The table has no actions, search, filters, or separate list.
Each row carries six `notPersonal` fields: `nodeId`, `phase` (nullable),
`quiesced` (nullable), `isLeader`, `isInitiator`, and `reportState` (`current`,
`missing`, or `silent`).

### Peer Reports And Projection

The read source runs in [PeerServer](../../../framework/backend/Cluster/Peer/PeerServer.php),
after that server's link and consensus pass, independently of protected-mode
agents and page subscribers. Each node, including a slave, reports its own
actual RT phase; absent local RT state is `null`. The full diagnostic snapshot
is broadcast with the existing `broadcastToMasters()` path when it changes,
when the working link set changes, and every five seconds. The
`peer_protected_mode_node_report` frame's
[PeerProtectedModeNodeReportDTO](../../../framework/backend/Cluster/Peer/DTO/PeerProtectedModeNodeReportDTO.php)
has required nullable `phase`, `term`, `quiesceNodeIds`, `pendingNodeIds`, and
`initiatorNodeId` keys. The node id comes from the authenticated peer link, not
from the frame.

Only an authenticated active [PeerLink](../../../framework/backend/Cluster/Peer/PeerLink.php)
supplies a report; candidate and draining links do not. The inbox is private to
its `PeerServer` instance. A new working link starts without a cached report,
and closing the replaced draining link does not remove the report associated
with its successor. These snapshots use no database or file access, blocking
I/O, new agent, or second membership register. The
[PeerProtocol](../../../framework/backend/Cluster/Peer/PeerProtocol.php)
channel is version 23 and requires a matching version at handshake; the report
does not provide compatibility with older peers. The existing cluster
registry supplies membership, including offline and slave nodes; a rejected
foreign handshake does not add a row.

The [ProtectedModeNodesCollector](../../../framework/backend/ProtectedMode/ProtectedModeNodesCollector.php)
projects the registry membership together with participants from the recognized
leader's complete report for the inspected current term, then sorts by node id.
A lost link leaves the node `silent`; a linked node with no report or no phase
is `missing`; a reported phase is `current`. `isLeader` follows the recognized
leader and term. `isInitiator` comes from the authoritative leader report, not
from the browser's node. `quiesced` is `true` for a participant absent from the
leader's pending set, `false` for a pending participant, and `null` when the
current report cannot establish either fact. A leader or term change clears the
cached round authority; replacing a node's working link clears that node's
cached report.

The [cluster protected-mode coordinator](../../../framework/backend/ProtectedMode/ClusterProtectedMode.php)
keeps `quiesceNodeIds()` as the full selected roster separately from pending
nodes, through its
[ProtectedModeQuiesceRoster](../../../framework/backend/ProtectedMode/ProtectedModeQuiesceRoster.php)
interface; reset returns it to `null`. A full freeze selects self and follower
masters. A direct verification window selects follower masters without the
leader itself. Adopting an
established freeze does not begin another collection, so its roster is empty.
The projection therefore does not turn an empty pending set without a full
roster into a positive acknowledgement for all nodes. It reads the actual
roster already held by the master; slave-freeze behavior remains HIL-1818.

### Local Runtime And Browser Delivery

The local [ProtectedModeRuntime state](../../../framework/backend/Runtime/State/Item/ProtectedModeRuntime.php)
has `maintenanceNodes: ?list<Row>`, initially `null`. Only the local master
writes it through
[`updateMaintenanceNodes()`](../../../framework/backend/Runtime/View/Actions/Item/ProtectedModeRuntimeActions.php),
and only a changed projection syncs to that master's workers. `null` means
unpublished or unavailable; `[]` means a known empty set. Neither value invents
an inactive phase. The [freeze store](../../../framework/backend/ProtectedMode/ProtectedModeFreezeStore.php)
neither saves nor restores this projection; `restoreFromDisk()` resets it to
`null`. The neighboring four agent-diagnostic fields and seven watchdog fields
keep their existing meanings. The projection stays local to this node: it is
not copied across nodes with the freeze row or routed through the Daemon
node-agent chain, which a full freeze stops.

The `MultiRowViewportTable` declares the runtime item in `BROWSER.SOURCES` and
its row fields in `BROWSER.ROWS`. Its
[source-change builder](../../../framework/backend/Tables/ProtectedMode/HilosMaintenanceNodesTable.php)
compares `SourceChange.previous[maintenanceNodes]` with the current projection
and emits create, update, or delete mutations by node id. Changes to unrelated
singleton fields do not produce row deltas. There is no Nodes page publisher or
second publication cache: the initial `page_response`, standard table windows,
and table mutations carry the data.

A query before the first projection is published follows the existing refused
window and owed-window path. Once the source is available, the table can return
the complete window without a reload. For a successful empty mutation list from
this declared source, the existing owed-window catch-up runs before the empty
result returns; unrelated no-ops stay silent, a refused query keeps its debt,
and the existing flush set-aside limits that window to one full-answer attempt
per flush. The general source-change and owed-window behavior remains in
[Browser Source Fan-out](browser-source-fanout.md#coming-back-without-a-reload).

Each subscription and delivery still goes through the existing
[`BrowserContext`](../../../framework/backend/Core/Browser/Context/BrowserContext.php)
checks for ADMIN, view mode, and `locksOut`. The full
`activating`/`active` freeze still closes the application with its shared
placeholder and stops the page agent. The existing verification-window
admission, circle, and code rules remain in force. Nodes grants no access,
control, or change to ready, stop, start, or membership behavior.

## Reading The Runtime Row, And What Null Means

These places read the row without asking whether the mode is "active for this
project" — activation is not declarative, so there is nothing to ask:

| Reader | Question it asks |
|---|---|
| `DaemonManager::registerProtectedModeTruthSource()` | should this master own the row? |
| `WebSocketClient::protectedModeLocksOut()` | is this handshake frozen out? |
| `BrowserContext::protectedModeLocksOut()` | is this page subscription frozen out? |
| `DaemonProtectedModeExecutor::runtimeView()` | where do I write the phase? |
| `WorkerServer::protectedModeRefusesStart()` | may this agent start during a freeze? |
| `AbstractDeliveryChannelAgent::freezeSilencesDeliveries()` | must the durable deliveries go quiet? |
| `AnalyticsCollector::isHeld()` | must the collector record nothing and throw its batch away? |
| `AnalyticsJournalAgent::onStop()` | is this stop the freeze's, so the node's journal goes with its lost events counted? |
| `AbstractHilosMaintenancePage` / `AbstractHilosIndexAgent` | what state should the Maintenance section show and push after RT sync? |
| `HilosMaintenanceNodesTable` | what node reports and current round facts belong in this table window? |
| `WorkerServer::getProtectedModeAgentDiagnostics()` | what is this node holding stopped, which types stay running, and is the start gate closed? |

The two entries read the same row as their guard (`StandaloneProtectedMode` and
`ClusterProtectedMode`, each with its own `runtimeView()`).

**Null means "this process holds no runtime state", never "this project declined
the mode".** Write that meaning into any new reader's PHPDoc; the older wording
about projects opting out described a contract that no longer exists. The
executor keeps its null branch and its log line as defense in depth even though
the guards now refuse before reaching it.

## Anti-Patterns

```php
// Wrong: the mode is not a feature a project switches on.
enum HilosFeature: string
{
    case PROTECTED_MODE = 'protected_mode';
}
```

That case is banned in the `HilosFeature` docblock, and the reason is a real
defect (HIL-513): making the freeze declarative means a project can ship a
destructive operation with no freeze behind it.

```php
// Wrong: a CLI command reaching for the switch directly.
Hilos::$cluster->protectedMode()->requestEnable($data);
```

Send a command to the owning agent instead, and let it call
`requestProtectedModeEnable()`.

```php
// Wrong: an entry that stands inert when the row is missing.
if ($this->runtimeView() === null) {
    return; // silently, after ready was already promised
}
```

Refuse loudly and before any trace of entry, as above.

## Validation

- Binance cluster scenario 34 reads the first WebSocket welcome through the
  shared entry with `entry-welcome <master> [<token>] [<pass>]`. It verifies that
  the operator and a code holder are inside on every master while a stranger
  sees the stub on each, after the normal enter → leave → open drive (HIL-1305).

- `composer run test:framework:unit` — covers the entry guards
  (`ClusterProtectedModeTest`, `StandaloneProtectedModeTest`), initiator placement
  and peer-frame identity (`ProtectedModePeerFrameTest`), singleton initiator
  topology (`TopologyValidatorTest`), the unconditional
  mount (`ProtectedModeRuntimeMountTest`), the master-side snapshot
  (`ProtectedModeSnapshotTest`), the agent driver
  (`ProtectedModeTestDriverTest`), the four verdicts and the alarm's repetition
  (`ProtectedModeWatchdogTest`, `ProtectedModeAlertMailTest`), the freeze left
  on disk (`ProtectedModeFreezeStoreTest`), the analytics collector told to
  forget by the round answer and not by the peer-link re-read
  (`WorkerManagerDbReHydrateAckTest`, `DaemonManagerDbReHydrateBarrierTest`),
  silent under the freeze with its stops handed over after it
  (`AnalyticsJournalOutboxTest`), the journal agent throwing the node's journal
  away when the freeze stops it (`AnalyticsJournalAgentTest`),
  the phases that silence the unstopped writers (`ProtectedModeContractTest`), and
  the mail pool dropping and refusing its durable deliveries while its raw half
  keeps sending (`MailDeliveryChannelAgentTest`).
- The Maintenance watchdog presentation is covered by Vue
  `HilosMaintenanceWatchdogAlert.test.ts` and `HilosMaintenancePage.test.ts`;
  Overview e2e checks in `demo/binance-btc-tracker/tests/e2e/tests/`
  (`protected-mode.spec.ts`, `maintenance-restore.spec.ts`, `a11y.spec.ts`) use
  `expectMaintenanceWatchdogThresholds()` from `framework/frontend/e2e/maintenance.ts`;
  the cluster browser check is in
  `demo/binance-btc-tracker/tests/e2e/cluster/live/protected-mode.spec.ts`.
- `composer run test:framework:integration` — covers the carry-over across a real
  database swap (`SessionCarrierIntegrationTest`, `SessionsActionsCarryOverTest`)
  and the analytics collector held by the freeze, forgetting the swapped schema,
  and its worker's sessions coming back by their descriptions
  (`AnalyticsDatabaseSwapIntegrationTest`, under `activating` and `active`).
- `demo/chat` integration `MailPoolFreezeIntegrationTest` — a mail delivery in
  flight when the freeze begins is cut and its row written no more.
- `demo/binance-btc-tracker` e2e `protected-mode.spec.ts` — drives the mode from a
  browser: enter, the live window showing the stub with the operation the caller
  named, leave, the window working again, and the verifier circle walking in on
  the tab it had open. It freezes the whole node, so its teardown lifts
  unconditionally; the runner is serialized (`CI=1`). It also covers Maintenance
  Overview and Circle navigation by direct address, reload, Back and Forward; the
  shared Hilos › Maintenance head, one h1 and the current tab; the table on Circle
  and no circle/table-controller traffic on Overview; and the live window,
  admission, view mode and cleanup. `backup.spec.ts` keeps its pointer on Overview
  and then selects the Circle tab. The Circle view is currently built only in Vue.
  The manual window runs as one scenario in each kit's `protected-mode.spec.ts`
  (binance-btc-tracker Vue, ecommerce-shop React, online-testing Angular), with
  checks in `framework/frontend/e2e/maintenance.ts`; those helpers use Overview.
  Only binance-btc-tracker runs the console entry, one code for two browsers and
  every page reloading itself. The teardown turns a manual window off too: the
  test open refuses one.
- `demo/binance-btc-tracker` cluster scenarios 23 and 25 — drive the freeze and
  verification window through the live peer mesh and repeated quiesce rounds.
- `demo/binance-btc-tracker` cluster scenario 38 — enables manual maintenance on
  one follower master, mints a pass from a slave and disables on another follower
  master. Every master reads `verifying` with no stopped agents and an open
  agent-start gate, then one pass, then `inactive` with no passes.
- `demo/binance-btc-tracker` cluster/live `protected-mode.spec.ts` — keeps its
  existing assertions and parked F-016 cases; circle setup and cleanup use the
  Circle address and return to Overview before manual or document marks. Scenario
  38 is the active manual cluster check; F-016 remains parked and unverified.
- `demo/binance-btc-tracker` e2e `maintenance-restore.spec.ts` — drives a real
  backup confirmed by its typed id. The freeze is the backup agent's, not
  `e2e-freeze`. Teardown lifts with `protected-mode:open` and repeats while the
  close still holds the operator slot. The test driver's open does not lift a
  freeze that agent started.
- `demo/binance-btc-tracker` e2e `maintenance-words.spec.ts` — displays the project's
  protected-mode stub registry as a read-only table on the Words tab, verifying
  `manual_maintenance` copy and `rowMessage` fallback for the default stub, direct
  address entry, reload, and tab returns to Circle and Overview.
