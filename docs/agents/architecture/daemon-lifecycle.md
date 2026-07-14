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
tickReadiness()        ← open the WS once required startup agents are ready
checkCronJobs()        ← once per minute, after workers ready
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
