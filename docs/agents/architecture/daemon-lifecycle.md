# Daemon Lifecycle

**Entry point:** `Bootstrap/daemon.php` → creates `DaemonManager` subclass, registers servers, calls `run()`.

## Startup sequence

1. `DaemonManager::__construct()` → `Hilos::initSignalRouter()`, creates `AgentManagerDaemon`
2. `daemon.php` registers servers: `HttpServer`, `WorkerServer`, `WebSocketServer` (optionally `FrontendHtmlServer`)
3. `daemon->run()` → creates `EventLoop`, sets up error/signal handlers, enters main loop
4. WebSocket server starts **only after** the required startup agents finish `onStart` (see below); with none declared it opens as soon as `WORKERS_READY`

## WebSocket readiness gate

The WebSocket server opens only after the agents a project declares in
`DaemonManager::getRequiredReadinessAgents()` have finished `onStart` (reported
`agent_started`). Default is empty — the socket opens as soon as `WORKERS_READY`.
A `$readinessTimeout` (seconds, `null` = wait forever) opens the socket degraded if
the agents never report; while pending, the daemon warns once per minute with the
missing agent ids.

## Main loop (each iteration)

```
processEventLoop()     ← epoll: accept connections, read data
servers->onTick()      ← process buffered client data
dispatchRoleTick()     ← per-iteration hook for the current node lifecycle phase
if amLeader():         ← leader (or standalone); a follower skips all three
  ensureSingletonsStarted()  ← start cluster-singleton agents once per leadership term
  tickReadiness()            ← open the WS once required startup agents are ready
  checkCronJobs()            ← once per minute, after workers ready
dispatchSignals()      ← drain SignalRouter queue → workers / WS clients
Hilos::$ac->tick()     ← analytics flush
pcntl_signal_dispatch()
sleepWithPreciseTiming()
```

## Node lifecycle & role-based onTick

The daemon does not call a single `onTick()`. Each main-loop iteration it asks the
cluster context for the local node's **lifecycle phase** and dispatches the hook
for that phase (`dispatchRoleTick()` → `runPhaseTick()`):

| Phase (`NodeLifecycleState`) | When | Hook |
|---|---|---|
| `Standalone` | cluster mode off | `onTickStandalone()` |
| `MasterLeader` | clustered master holding leadership | `onTickLeaderMaster()` |
| `MasterFollowerOrCandidate` | clustered master with quorum, not leader | `onTickNotLeaderMaster()` |
| `MasterNoQuorum` | clustered master without quorum | `onTickNotLeaderMaster()` |
| `Slave` | clustered data-plane node | `onTickSlave()` |

Rules:

- **App daemon logic goes in `onTickStandalone()`**, not a generic `onTick()`.
  This is the single-node hook and the verbatim successor of the old `onTick()`.
- The phase comes from `Hilos::$cluster->lifecycleState()`; when cluster mode is
  off it is always `Standalone`, so a non-clustered project only ever sees
  `onTickStandalone()`.
- On a clustered master the leadership seam (`Leadership`: `amLeader()` /
  `leaderId()` / `hasQuorum()`) is backed by the consensus coordinator (HIL-339,
  see [Consensus coordinator](#consensus-coordinator-hil-339)), so a master moves
  between `MasterNoQuorum`, `MasterFollowerOrCandidate`, and `MasterLeader` as it
  gains quorum and wins or loses elections. A slave never consults leadership and
  always runs `onTickSlave()`.
- All four `onTick*` hooks must obey the **< 0.1s** rule — they run on the master
  loop. A failure reading the phase is contained: it logs and falls back to
  `Standalone` rather than tearing down the loop.

### Membership hooks

The daemon registers itself as the cluster `MembershipObserver` at start. The peer
transport reports mesh transitions through the cluster context, which calls:

- `onNodeJoined(ClusterNode $node)` — a node joined (or came back online)
- `onNodeLeft(ClusterNode $node)` — a node went offline

Both default to no-ops and both run on the master loop, so overrides must stay
non-blocking. The registry stays a pure data structure — there is no per-tick
membership polling; transitions are pushed to the observer.

### Leadership hooks

The daemon also registers itself as the cluster `LeadershipObserver` at start. The
consensus coordinator (below) fires four transitions the daemon exposes as
overridable hooks:

- `onBecameLeader(int $term)` — this node won leadership for `$term`
- `onLostLeadership(int $term)` — this node stepped down (newer term or lost quorum)
- `onQuorumGained()` — the online master set reached a quorum
- `onQuorumLost()` — the online master set fell below a quorum

All four default to no-ops and run on the master loop, so overrides must stay
non-blocking. The framework only *delivers* these events; reacting to them (gating
singleton duties, stopping in-flight work) is the project's job and lands in the
neighbouring cluster slices, not here.

## Consensus coordinator (HIL-339)

A clustered **master** runs a self-written, raft-like coordinator
(`Cluster/Consensus/ClusterCoordinator`) that decides leadership behind the
`Leadership` seam. It takes raft's leader-election and anti-split-brain only — no
replicated log or state machine.

- **Transport.** Consensus multiplexes three new frames on the existing HIL-178
  peer mesh (no new connections): `PeerRequestVoteDTO`, `PeerVoteReplyDTO`,
  `PeerHeartbeatDTO`. The peer protocol version is `2`. Consensus runs only between
  master nodes; slaves are in the mesh but never vote and host no coordinator.
- **Quorum.** A static expected-master-set (`CLUSTER_MASTER_SET`) defines the
  quorum as a fixed majority (`floor(n/2)+1`). `hasQuorum()` counts master-set
  members currently online in the registry, including self, so a partition shrinks
  one side below majority for free. A master that cannot see a quorum stops leading.
- **Election.** Followers hold a randomized election timeout
  (`CLUSTER_ELECTION_TIMEOUT_MIN_MS`..`MAX_MS`); the first to expire becomes a
  candidate, requests votes, and leads on a majority. Candidacy is gated on a live
  quorum, so an isolated minority never inflates the term. A leader asserts its
  term with a one-way heartbeat every `CLUSTER_HEARTBEAT_INTERVAL_MS`; liveness for
  quorum comes from the registry, so heartbeats need no ack. Re-election happens
  only when the leader disappears — a dropped leader link marks it offline
  instantly (fast path) ahead of the election timeout. Term is in-memory only; on
  restart it is re-learned from the first heartbeat or request-vote seen.
- **Driver.** The `PeerServer` builds the coordinator at start (master only),
  installs it via `ClusterContext::registerLeadership()`, and ticks it each
  `onTick` after servicing links. The coordinator only reads liveness and queues
  frames — it never gates or stops work; that is the neighbouring slices' job.

## Role-based singleton duties (HIL-340)

Singleton duties run on **exactly one node cluster-wide** — the leader, or the sole
node when cluster mode is off. Each main-loop iteration the daemon gates them behind
`amLeader()` (`Hilos::$cluster->amLeader()`, which is true for a `StandaloneLeadership`
daemon), so a standalone daemon is unchanged and a clustered follower runs none of
them:

- **Cluster-singleton agents.** `ensureSingletonsStarted()` is an ensure-once for the
  "leader AND local workers ready" start condition (the two arrive in any order). The
  first tick both hold, it fires `WorkerServer::onBecameSingletonHost()` and sets an
  internal `singletonsStarted` flag; real `startAgent` calls happen once per
  leadership term, not per tick. The base `onBecameSingletonHost()` queues
  `INITIAL_AGENTS_START` (launching the bootstrap agent list via routing); a project
  overrides it to start its own cluster-singletons (e.g. one agent per active bot).
  `WorkerServer::onInitialWorkersReady()` is now a per-node "local workers up" hook
  only and no longer starts singletons.
- **WebSocket.** A follower does not open its WebSocket (`tickReadiness()` is inside
  the gate), so browsers never reach a non-leader until cross-node routing exists
  (HIL-180). On promotion the socket opens and duties start.
- **Cron.** `checkCronJobs()` runs only inside the gate.

**The leader-only agent flag.** `AgentDaemonInterface::requiresClusterLeadership()`
(default `true` in `AbstractAgentDaemon`) marks an agent as a cluster-singleton.
`WorkerServer::startAgent()` refuses to start such an agent when the node is not the
leader, covering **both** the bootstrap-list path and direct project starts. The
default is fail-safe: forgetting to mark an agent under-runs it (safe) rather than
double-running a truth source (a correctness bug). Per-node agents opt out with
`false`.

Coordination state is **not** persisted (MySQL is kept out of coordination). A new
leader rebuilds membership/placement by re-querying the mesh; singleton agents are
launched fresh (the previous leader's were killed). Stopping singletons and resetting
`singletonsStarted` on leadership loss is HIL-341.

## Graceful shutdown

- SIGTERM/SIGINT → `shouldExit = true`
- `initiateShutdown()` → calls `prepareShutdown()` on all servers
- Loop continues until all servers report `isReadyToShutdown()` or `shutdownTimeout` (20s) expires

## Key properties

| Property | Default | Meaning |
|---|---|---|
| `$servers` | [] | Registered servers (HTTP, Worker, WS, Frontend) |
| `$shutdownTimeout` | 20.0s | Max wait for graceful shutdown |
| `$cronRules` | [] | Named cron rules added via `addCronRule()` |

## Cron

Register rules in the daemon manager constructor:
`$this->addCronRule('name', '*/5 * * * *')`.

When a rule is due, `DaemonManager::onCron()` queues a `DAEMON/CRON` signal.
Handle the cron name in the target agent's `onSignalCron()` (or on a page cron
handler declared in topology). Override `onCron()` only for daemon-local work
that must not go through the signal router.

Cron fires only after `WORKERS_READY` and at most once per minute.

Cron runs on the leader (or standalone) node only, and last-run state is not
persisted — a new leader schedules "from now". Because `shouldRun()` fires at most
once for a matching minute, no catch-up burst is possible on a leadership change.
**Cron jobs must therefore be idempotent:** a job may be skipped or repeated across a
leadership handover, and that must be acceptable.
