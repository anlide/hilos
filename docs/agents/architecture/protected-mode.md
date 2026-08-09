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

Three test-only commands, and the split between them is the whole design:

| Command | Answered by | Why there |
|---|---|---|
| `test:protected-mode:inspect` | the master, synchronously | during a freeze every agent but the initiator is stopped, so an agent-answered inspector would go silent in exactly the phase worth inspecting |
| `test:protected-mode:enter <operation> [--accept-key=<k>]` | the initiator agent | it calls `requestProtectedModeEnable()` — the one entry, unchanged |
| `test:protected-mode:leave` | the initiator agent | authorized by initiator identity, exactly as in production |

`ProtectedModeTestDriverTrait` carries the agent half. A trait, because its two
carriers share no ancestor but `AbstractAgent`, and putting the commands there
would hand a test-drive of the freeze to every agent of every project. The
carriers are `AbstractHilosIndexAgent` (so chat, simple-todo and simple-poll get
it by inheritance) and the cluster demo's `WorkerAgent` (that demo is headless
and has no Hilos index, so without it the clustered entry path — the leader's
quiesce round and a follower's fail-closed refusal — has no live carrier).

Two properties are worth keeping when this code is touched:

- **Both drive commands answer on the move, not on acceptance.** Enter answers
  from `onProtectedModeReady()`, leave when this node's row is back to inactive.
  That is what makes the reply a verdict and lets a test act on the next line
  instead of polling.
- **The pre-checks exist because the core answers nobody.** A repeat enable, a
  disable with no freeze and a disable from the wrong agent are all
  log-and-return paths. Reading the row first turns each into a stated reason
  rather than a mute timeout. The agent's wait window is deliberately the
  innermost of three (agent, then CLI, then the channel's held request), so the
  informative refusal is the one that fires first.

There is deliberately **no production-environment refusal on the agent side**.
The CLI half refuses (`TestOnlyCommand`), but the command socket authenticates
nobody and e2e reaches it directly over TCP, since the Playwright runner has no
PHP. That ungated socket path is an existing property of the command channel,
shared with `setAdmin` and `connection:test:drop` — recorded here so it is not
mistaken for an oversight and "fixed" at the cost of the e2e.

The snapshot never carries `initiatorAcceptKey`. It is the pass through the
lockdown, and the port that would publish it authenticates nobody.

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
  has its own owner (watchdog, HIL-482).

The two guards are the same check in different contexts — one-tick single-node
entry against a leader round that commits followers — so they are written twice
on purpose and are not merged into a shared helper.

## The "Not In Production" Gate Lives In The Command

The mode itself knows nothing about `APP_ENV`, and must not learn: knowing would
make it useless for the disaster recovery it exists for.

- A test driver command extends `TestOnlyCommand`, which refuses on a
  production-like or empty `APP_ENV` (the same mechanism `cluster:test:inspect`
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
  (`ProtectedModeSnapshotTest`) and the agent driver
  (`ProtectedModeTestDriverTest`).
- `demo/chat` e2e `protected-mode.spec.ts` — drives the mode from a browser:
  enter, the live window showing the stub with the operation the caller named,
  leave, the window working again. It freezes the whole node, so its teardown
  lifts unconditionally; the runner is serialized (`CI=1`).
