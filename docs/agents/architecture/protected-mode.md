# Protected Mode (Node Freeze)

Read this before starting a destructive operation, wiring a CLI command that
triggers one, or touching any code that reads the protected-mode runtime row.

Protected mode is the freeze a node enters while a destructive operation runs
against it: pages stop being served, this node's agents are stopped, and the
only connection left through is the one driving the operation. Its machinery is
`framework/backend/ProtectedMode/`; the two entries are
`StandaloneProtectedMode` (single node) and `ClusterProtectedMode` (leader plus
followers).

## Core Rule

The mode is unconditional and its only entry is an agent. Do not add a switch —
not a `HilosFeature` case, not an env variable, not a facade or static method,
not a second entry "for tests".

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

## The Agent Owns The Entry; CLI Is Only The Trigger

Entering goes one way and one way only:

```
Agent::requestProtectedModeEnable(operation, acceptKey)
  → PROTECTED_MODE_ENABLE signal → WorkerManager → WorkerProtectedModeEnableDTO
  → WorkerClient → Hilos::$cluster->protectedMode()->requestEnable()
```

A CLI command never enters the mode itself. It sends its request down the
command channel to the agent that owns the operation — `backup:restore-request`
reaching `BackupAgent` is the worked example — and that agent asks for the
freeze. `ProtectedModeSwitch` is out of reach from a CLI process anyway — not
because `Hilos::$cluster` is missing (`Hilos::initEnv()` builds it everywhere)
but because nothing registers a switch into it outside the daemon, so
`ClusterContext::protectedMode()` is null there. No extra guard is written for
it.

## Exactly One Entry, Including For Tests

There is no second entry and none is to be added. The initiator identity
recorded on entry is what authorizes the later release (a stray agent must not
thaw the system mid-restore) and what the agent-start gate lets through, so a
synthetic initiator would test a path production does not have.

What this obliges a test driver to do (HIL-344): give the driver its own
initiator agent and ask for the mode through it, rather than poking the daemon
past the agent. The precedent is the backup test commands, which ride
`AGENT_COMMANDS` of the live `BackupAgent`.

### The Test Tool That Obligation Produced

Five test-only commands, and the split between them is the whole design:

| Command | Answered by | Why there |
|---|---|---|
| `test:protected-mode:inspect` | the master, synchronously | during a freeze every agent but the initiator is stopped, so an agent-answered inspector would go silent in exactly the phase worth inspecting |
| `test:protected-mode:enter <operation> [--accept-key=<k>]` | the initiator agent | it calls `requestProtectedModeEnable()` — the one entry, unchanged |
| `test:protected-mode:leave` | the initiator agent | the driven operation is over: it calls `requestProtectedModeVerify()` and lands in the verification window, where a real one lands too |
| `test:protected-mode:open` | the initiator agent | the explicit lift, authorized by initiator identity exactly as in production |
| `test:protected-mode:pass` | the initiator agent | mints one code into the driven window and prints it, so a test has something to type into the verifier's field (HIL-616) |

`ProtectedModeTestDriverTrait` carries the agent half. A trait, because its two
carriers share no ancestor but `AbstractAgent`, and putting the commands there
would hand a test-drive of the freeze to every agent of every project. The
carriers are `AbstractHilosIndexAgent` (so chat, tasks and simple-poll get
it by inheritance) and the cluster demo's `WorkerAgent` (that demo is headless
and has no Hilos index, so without it the clustered entry path — the leader's
quiesce round and a follower's fail-closed refusal — has no live carrier).

Two properties are worth keeping when this code is touched:

- **Every drive command answers on the move, not on acceptance.** Enter answers
  from `onProtectedModeReady()`, leave when this node's row reads verifying, open
  when it is back to inactive. That is what makes the reply a verdict and lets a
  test act on the next line instead of polling.
- **The pre-checks exist because the core answers nobody.** A repeat enable, a
  disable with no freeze and a disable from the wrong agent are all
  log-and-return paths. Reading the row first turns each into a stated reason
  rather than a mute timeout. The agent's wait window is deliberately the
  innermost of three (agent, then CLI, then the channel's held request), so the
  informative refusal is the one that fires first.

There is deliberately **no production-environment refusal on the agent side**,
and since HIL-566 there is no need for one: the socket itself refuses a
test-only command before it parks anything, so the drive trio is gated on the
path e2e actually takes — directly over TCP, since the Playwright runner has no
PHP. What the socket still does not do is authenticate its caller, which is why
`setAdmin` and the other unflagged commands remain reachable to anyone who can
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

They deliberately do **not** share names with the `test:` family. A command
routes to exactly one agent type per project (`TopologyValidator` refuses a
second owner), a project may hold two initiators — the real one and the test
driver's carrier — and a freeze may only be driven by the agent the row names. A
shared name would hand one initiator's freeze to the other, and the identity
check would then refuse it. Hence the two ladders, same shape, different owners.

The mint answers to **two** names — `protected-mode:pass` and
`test:protected-mode:pass` — and that is not a hole in the rule above but the
rule applied: which of the two ever reaches a given carrier is decided by that
carrier's `AGENT_COMMANDS`, so each name still has exactly one owner per project.
What is shared is the handler, deliberately: one minting path, one identity
check, one wait for the hash to land. A second implementation of "what a pass is"
is the thing worth avoiding, not a second name.

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
frame every phase change sends, fired once at zero-to-one; later mints announce
nothing, because the bit already says what they would say.

The second bit exists rather than a narrowed first one because `acceptsPass`
carries two more jobs on the client: it decides when the mode is called over
(`createHilosConnection`) and when a presented key is dropped (`HilosConnection`).
Narrowed to "a code exists", it would reload an admitted verifier out of the
window the moment nobody held a code, and throw away his key on the way.

**A count never leaves the master.** The wire carries a boolean: how many people
the operator invited is his business and tells a visitor nothing he needs. The
count stays where it already was, in the test-only snapshot
(`test:protected-mode:inspect`), which also never carries a hash.

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
- **Repair work never holds the freeze.** A snapshot that could not be taken or a
  session that could not be written is logged and the run proceeds. The people
  affected see a login screen; the alternative is a node left frozen over a
  detail of the recovery.

## Entry Is Fail-Closed In Both Branches

A node that cannot freeze refuses loudly; it never stands inert while reporting
success. The initiator waits for ready before it destroys anything, so a refusal
leaves the system safely waiting, whereas a silent no-op that still produced
ready would run the operation over live nodes.

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

The two guards are the same check in different contexts — one-tick single-node
entry against a leader round that commits followers — so they are written twice
on purpose and are not merged into a shared helper.

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
unweakened — it reads memory, writes nothing (not the row, not the database) and
makes no blocking call.

| Verdict | What it means |
|---|---|
| `INITIATOR_LOST` | the initiator agent stopped; sticky, because the agent-start gate lets its type start again and a fresh instance would read as alive |
| `RESTORED_FROM_DISK` | the freeze came back with the daemon, so nothing is running behind it; reported on the first tick |
| `QUIESCE_OVERDUE` | phase `activating` past `HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT`; the alert names the nodes that never confirmed |
| `SILENT` | any other non-inactive phase with no progress mark newer than `HILOS_PROTECTED_MODE_SILENCE_TIMEOUT` |

**The progress mark is written by the operation, not by the framework.** What
proves life is that the *work* moved, not that a process is up: a restore spawns
a child and waits for it, so a framework-written "the agent still ticks" mark
would keep a permanently hung restore invisible forever. The initiator calls
`AbstractAgent::reportProtectedModeProgress()` when something of its own advanced
— a new restore phase, output read from the child — and that refreshes
`progressAt` on the freeze row, stamped by the master that owns the row and never
carried on the wire, so a skewed clock elsewhere cannot move the arithmetic.
This is also the answer to "fast restore versus long migration": an operation
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
leaves it running), because otherwise the only channel out of a stuck node is
dead exactly when it is needed. **The log line comes first and always** — it is
the one report that cannot fail, and a delivery failure never changes the
watchdog's own state.

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
  Every accept key is dropped on restore, the initiator's own included: each was
  minted on a 101 that died with the daemon.
- **Leader change.** `ClusterProtectedMode::onBecameLeader()` rebuilds the
  leader-side freeze from the row this node carries. Without it `onDisable()`
  from a live and healthy initiator is dropped (`leadsFreezeFor()` on
  `activeFreeze === null`) and nothing can unfreeze the cluster at all. Who had
  already quiesced cannot be inherited — that list died with the leader — so an
  unfinished round starts over with every follower outstanding and usually does
  not close, which the watchdog reports as overdue. Every threshold is counted
  from the moment the watchdog first saw the freeze, never from the clocks on the
  row, or a promotion in the middle of a healthy hour-long restore would report
  it stuck in the same second.

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

- `composer run test:framework:unit` — covers the entry guards
  (`ClusterProtectedModeTest`, `StandaloneProtectedModeTest`), the unconditional
  mount (`ProtectedModeRuntimeMountTest`), the master-side snapshot
  (`ProtectedModeSnapshotTest`), the agent driver
  (`ProtectedModeTestDriverTest`), the four verdicts and the alarm's repetition
  (`ProtectedModeWatchdogTest`, `ProtectedModeAlertMailTest`) and the freeze left
  on disk (`ProtectedModeFreezeStoreTest`).
- `composer run test:framework:integration` — covers the carry-over across a real
  database swap (`SessionCarrierIntegrationTest`, `SessionsActionsCarryOverTest`).
- `demo/chat` e2e `protected-mode.spec.ts` — drives the mode from a browser:
  enter, the live window showing the stub with the operation the caller named,
  leave, the window working again. It freezes the whole node, so its teardown
  lifts unconditionally; the runner is serialized (`CI=1`).
