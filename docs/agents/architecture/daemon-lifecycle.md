# Daemon Lifecycle

**Entry point:** `Bootstrap/daemon.php` → creates `DaemonManager` subclass, registers servers, calls `run()`.

## Startup sequence

1. `DaemonApplication::run()` checks the process environment against the project's env
   catalog and refuses to start when a required value has no answer, naming **every**
   missing name in one `MissingRequiredEnvironmentException`. Reading a value only when
   the code touches it names one variable per launch and stays silent until the code gets
   there, so an operator setting up a node learns the set one restart at a time. The check
   runs ahead of `Logger::setLogFile()` — `DAEMON_LOG_FILE` is itself required and may be
   one of the missing ones, and a `Logger` with no file writes to stdout/stderr, which is
   where `docker logs` reads. Only the daemon checks: in a container `docker.php` is the
   watchdog and runs `daemon.php` as its child, so the containerized start passes through
   here anyway, and the worker comes up under a daemon that already answered.
2. `DaemonManager::__construct()` → `Hilos::initSignalRouter()`, creates `AgentManagerDaemon`
3. `daemon.php` registers servers: `HttpServer`, `WorkerServer`, `WebSocketServer` (optionally `FrontendHtmlServer`)
4. `daemon->run()` → creates `EventLoop`, sets up error/signal handlers, enters main loop
5. WebSocket server starts **only after** the required startup agents finish `onStart` (see below); with none declared it opens as soon as `WORKERS_READY`

## Container watchdog and crash recovery (HIL-450)

In Docker the daemon is not PID 1 — `Bootstrap/docker.php` is. It runs migrations and
then supervises `daemon.php` through `DockerManager`, restarting it whenever it dies.
Two rules make that supervision survive a *crash* rather than only a clean exit:

- **Sweep before every start.** Workers are spawned with `proc_open` and inherit the
  daemon's listening sockets (no `FD_CLOEXEC`). A daemon that dies without stopping them
  leaves them holding its ports, and — because a hung worker cannot decide to exit on its
  own — the next daemon can never bind: the node restarts every
  `DAEMON_MIN_RESTART_INTERVAL` seconds forever. So `startDaemon()` first calls
  `OrphanReaper::reap()`: SIGTERM to every live child of this process, polled at 100ms,
  SIGKILL to whatever survives 5s. On a healthy restart it finds nothing and says nothing —
  `WorkerServer::prepareShutdown()` already stopped the workers — so anything it *does*
  find is by definition leftover. The invariant it buys is **"a daemon starts alone"**,
  which is why no bind-retry or error-text parsing is needed anywhere.
  - The reaper scans `/proc` for processes whose PPID is this process, rather than calling
    `kill(-1)`. Re-parenting is flat: orphaned workers *and* their own grandchildren
    (mysqldump, an LLM call) all land directly on PID 1, so the PPID scan sees the whole
    tree. Where the watchdog is *not* PID 1 the scan simply finds nothing, whereas
    `kill(-1)` would take out processes that were never ours.
  - It skips zombies and reaps exited children with `pcntl_waitpid(..., WNOHANG)`. A child
    killed by SIGTERM stays in `/proc` as a zombie until its parent waits for it, and that
    parent is the watchdog itself — without the skip, every single restart would wait out
    the full 5s grace and then SIGKILL a corpse.
- **Shout, but keep trying.** A start that dies before reaching
  `DAEMON_MIN_RESTART_INTERVAL` counts as failed; reaching it resets the count. Once the
  run of failures hits `DAEMON_FAILED_START_THRESHOLD` (default 3) the watchdog logs an
  error with the count, the last attempt's uptime, and the tail of
  `DAEMON_ERROR_LOG_FILE`. It does **not** give up or exit: the cause may be external and
  temporary (a database still coming up, memory pressure), and the compose restart policy
  is deliberately left alone. The reason comes from the error-log file and not from
  `Process::getStdErr()` — the daemon's stderr is redirected to that file, so the process
  has no stderr pipe to read.
- **Say one thing when you die, and nothing else (HIL-617).** The watchdog is simple, and
  there is no watchdog for the watchdog. Its own failure is *not* damped by a per-iteration
  `try/catch`, not retried, and not handed to project code through a hook: it mails one
  letter and leaves. It is deliberately denied what the worker got in HIL-574
  (`containFailure` + `onTickFailure`), and the asymmetry is the whole point — a worker has
  someone to bring it back, `WorkerServer::ensureMinWorkers()`, and the watchdog has nobody.
  Making it survive its own failures by its own means *is* the watchdog for the watchdog.
  Who restarts a watchdog is a question outside Hilos: a compose restart policy, systemd,
  zabbix. The framework does not answer it, and the next author should not make it try.

**Two occasions get mail, and no others (HIL-617).** `WatchdogAlertMailer` writes when the
failed-start run hits the threshold above, and when the watchdog is exiting after a failure
of its own — the first from `recordFailedStart()` at the exact moment the count reaches the
threshold (one letter per run of failures, not one per failure), the second from the run
loop's catch or, for a PHP fatal, from `onShutdown()`. There is no "the daemon is back"
letter and no timed reminder.

- **The mail is the watchdog's own** (`WATCHDOG_ALERT_*`), because it has to be able to
  report itself on a box where the product mail is not configured at all. Those variables
  belong to the watchdog: the master and every other process are barred from reusing a
  setting with `WATCHDOG` in its name. Unlike `MAIL_*` there is no transport auto-select —
  an empty host, From or To address means "not configured", which is one line in the log.
- **The send goes straight out, synchronously, around `HilosMailer::send()`**, which only
  queues a signal for an agent living inside a worker. The watchdog writes precisely when
  there may be no daemon at all, so it pumps the transport itself, bounded by
  `WATCHDOG_ALERT_TIMEOUT_MS`. Every failure of the send is a log line and nothing more.
- **Where the honesty ends, in plain words.** A letter arrives for a failure carrying an
  exception or a PHP fatal. On `kill -9`, on the OOM killer, or when the container itself
  goes down, there is no letter, and this is not a gap to be closed from the inside —
  those are watched for from outside (zabbix, the restart policy).

**The daemon owns its log files.** `startDaemon()` hands its stdout and stderr to
`DAEMON_LOG_FILE` and `DAEMON_ERROR_LOG_FILE` as file descriptors, so the daemon's output is
written by the kernel and never passes through the watchdog: there is nothing for
`tickDaemon()` to tee into the watchdog's own log, and it does not try. The single exception
is the failed-start escalation above, which reads the tail of the error file — a deliberate
read of a file, not of a stream.

**The watchdog's own errors land in the same error file.** `DockerApplication::run()` calls
`Logger::setErrorLogFile(DAEMON_ERROR_LOG_FILE)` at startup and deliberately does *not* call
`setLogFile()`: with no main log file the logger keeps echoing its whole feed to stdout, so
`docker logs` — the first place a dead node is read — stays complete, while errors are
additionally appended to the file. So both halves of an incident (the daemon's crash trail
and the watchdog's stop/restart trail) end up in one file, the one the Hilos logs admin page
already reads. The daemon does the same for its own process next to `setLogFile()`.

The cluster harness guards this end to end: `cluster start <node>` reuses the existing
container instead of recreating it, and scenario 9 (`cluster_e2e.py`) SIGKILLs the daemon
inside a live container and requires the node to rebind, rejoin the roster, and accept
placements again — with the same container id.

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

`onBecameLeader` and `onQuorumGained` default to no-ops; the other two carry the
HIL-341 reaction defaults below. A fifth hook, `onClusterWorkStop()`, is fired by
the framework (not the coordinator) on quorum loss and on a planned graceful-leave.
All run on the master loop, so overrides must stay non-blocking, and a project that
overrides `onLostLeadership` / `onQuorumLost` should call `parent::` first to keep the
framework default.

### Handing work out of the master (HIL-618)

Every hook above runs on the master loop and must stay non-blocking, which leaves a
project that discovers real work with nowhere to put it. `MasterSignalSender`, which
`DaemonManager` implements, is that place — two doors a project daemon reaches through
`$this`:

- `sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data)`
  — one named agent, wherever in the cluster it runs. Placement decides the route:
  local worker link, or the peer channel when the agent is placed on another node.
  It arrives as an ordinary `AGENT_SIGNAL` / `AgentSignalData` and is taken by the
  agent's own `onSignalAgent()`.
- `sendToWorkers(string $signalName, SignalDataInterface $data)` — every worker of
  this node, monopolistic ones included, and strictly this node: "all workers of a
  node" is addressed by naming the node. It lands on `WorkerManager::onDaemonSignal()`,
  an empty `protected` hook the project overrides. Agents inside those workers are not
  handed it — that is what `sendToAgent()` is for. The hook runs on the worker's tick,
  so it must not block either: see
  [worker-lifecycle.md](worker-lifecycle.md#message-types-from-daemon).

Both doors put a frame in a socket write buffer and return. Three things to know
before using them:

- **Delivery to an agent STARTS a stopped agent.** The local path is the one the
  router uses, and it starts an agent that is not running rather than dropping the
  signal. There is no "do not start" flag; the protected-mode and cluster-leadership
  gates on start apply as they always do. This is also the one place the call is more
  than a buffered write: starting runs the project's agent-daemon factory synchronously
  on the master loop, so that factory is master-loop code and is bound by the rule in
  [heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md) like everything
  else there.
- **Neither door reports delivery.** Both return `void` and swallow every failure,
  because an exception escaping here would end `run()` and take the node down. A
  refusal is written as `Master signal '<name>' to <addressee> dropped: <reason>` —
  as an error normally, as info while the node is leaving.
- **Order against the router's queue is not guaranteed.** These write to the socket
  at once; `SignalRouter::queueSignal()` drains at the end of the loop iteration.

**Use them only when the addressee is known by name and there is no route to declare.**
The ordinary way to move a signal is still `queueSignal()`, which routes by sender —
the signal's source and type decide where it goes, and a destination that changes with
the topology stays a routing rule instead of becoming a call site. This facade is the
imperative exception for the case routing cannot express.

**The framework's own worker broadcasts do not go through this door** — the access
re-decision announcement (HIL-644) carries its own frame and its own branch in the
dispatch pass. `sendToWorkers()` lands on `onDaemonSignal()`, which its own docblock
declares to be the project's hook and one the framework sends nothing through: a project
overriding it without calling `parent::` would silently kill a framework mechanism it
never knew it was standing on. A framework fan-out gets a named frame instead, which no
override can intercept.

### Answering a contained failure (HIL-619)

The master swallows what belongs to one connection so the node keeps serving the rest.
`onContainedFailure(ContainedFailure $failure)` on `DaemonManager` is where the project
hears about it — one empty `protected` hook for all four guards, because the master is
one process and a project should not have to override four places to count one thing.

The card is `Hilos\Core\Daemon\ContainedFailure`: the unit, the address, the failure.
It is the same card the worker's `onTickFailure()` takes (HIL-574); only the enumeration
of units differs, and the two are held together by `FailureUnit`. The master's units are
`MasterFailureUnit`:

| Unit | What failed | Address |
|---|---|---|
| `CONNECTION` | one live connection, read by its server's tick or by the loop's read callback | server name, plus ` acceptKey=<key>` once a WebSocket connection is past its handshake |
| `CONNECTION_ACCEPT` | an incoming connection the server could not accept | server name |
| `LOOP_ITERATION` | one iteration of the main loop, after which the node leaves | `daemon loop` |

A fourth case, `FAILURE_HOOK`, names the hook itself as a guarded unit, but no card ever
carries it to the hook: the only reader of a card is the hook, and handing it its own
failure is the loop the guard exists to prevent. That one is written and stops there.

Four things the contract fixes:

- **The line first, then the hook.** The journal record is not the project's to replace;
  an overridable record is how a guard becomes the silent place it was built to prevent.
  Neither the wording, the level nor the limiter on repeats changed for this.
- **Called on every contained failure**, not in step with that limiter. The limiter keeps
  a storm out of the log; a project counting failures needs the storm counted honestly,
  because the storm is the thing worth reacting to. In one, the hook is called hundreds
  of times a minute.
- **A hook that throws does not take the node with it.** Its failure is written as
  `Failure in the contained-failure hook: ...` and the hook is not called again with it.
- **The loop iteration is reported before the exit flag is set.** That order is load
  bearing: it is the project's last chance to say anything outwards while the node is
  still serving, and the departure is held to `shutdownTimeout`, so a frame handed over
  there still makes it into the socket.

It runs on the master loop, so the hook does nothing costlier than a line or a counter —
no database, no file, no network, no waiting. Anything above that leaves through
`MasterSignalSender` (see above); the rule itself is
[heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md).

Not to be confused with `onException()`: that one answers a failure PHP could not place
anywhere and asks the process to leave, while this one is a report in the other
direction — the failure was caught and life goes on.

Servers are handed the seam (`ContainedFailureSink`) at registration, through
`ServerInterface` rather than by type: a server left without it would contain its
failures in silence, and silence is indistinguishable from a node that has none.

## Consensus coordinator (HIL-339)

A clustered **master** runs a self-written, raft-like coordinator
(`Cluster/Consensus/ClusterCoordinator`) that decides leadership behind the
`Leadership` seam. It takes raft's leader-election and anti-split-brain only — no
replicated log or state machine.

- **Transport.** Consensus multiplexes three new frames on the existing HIL-178
  peer mesh (no new connections): `PeerRequestVoteDTO`, `PeerVoteReplyDTO`,
  `PeerHeartbeatDTO`, raising the peer protocol version to `2` (later slices raise it
  further; it is `4` as of HIL-183). Consensus runs only between master nodes; slaves
  are in the mesh but never vote and host no coordinator.
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

**The placement gate.** Where an agent may run is declared once, in its `Hilos::AGENTS`
entry, on two axes: `AgentRegistryKey::SCOPE` (`AgentScope::CLUSTER` | `AgentScope::NODE`)
and `AgentRegistryKey::PLACEMENT` (`AgentPlacement::LEADER` | `AgentPlacement::POLICY`).
`WorkerServer::startAgent()` reads them and refuses accordingly, covering **both** the
bootstrap-list path and direct project starts: a `NODE` replica starts anywhere, a
`CLUSTER`+`LEADER` singleton only where leadership sits, and a `CLUSTER`+`POLICY`
singleton only where placement put it. Both defaults are fail-safe — an undeclared axis
under-runs an agent (safe) rather than double-running a truth source (a correctness bug).

**Placing the policy agents.** `DaemonManager::ensurePolicyAgentsPlaced()` runs inside
the leader gate, next to `ensureSingletonsStarted()`, and places every `CLUSTER`+`POLICY`
agent the registry declares without an index. It reconciles on every tick rather than
ensuring once, because `ClusterPlacement::placeAgentOnBestNode()` places nothing when no
online node clears the hard gate; a record left `Failed` is retried no more often than
`POLICY_PLACEMENT_RETRY_SEC`. Indexed pools stay with the project — only it knows their
members. With cluster mode off the same pass hosts the agent locally: a single node is
its own leader and its own data plane.

On lost leadership only the `CLUSTER`+`LEADER` agents are stopped
(`WorkerServer::onLostSingletonHost()`). A replica was never tied to the term, and a
policy-placed singleton lives on the node the policy picked — its failover belongs to
placement (HIL-183), not to the leader's list.

Coordination state is **not** persisted (MySQL is kept out of coordination). A new
leader rebuilds membership/placement by re-querying the mesh; singleton agents are
launched fresh (the previous leader's were killed — see HIL-341 below).

## Quorum-loss reaction and graceful-leave (HIL-341)

HIL-339 *detects* quorum loss and flips the flags (`hasQuorum()` → false, `amLeader()`
→ false); this slice *reacts* to those transitions. The trigger for stopping work is
**quorum loss, not every leader change** — a leader change with a live majority lets the
survivors keep working under the new leader.

- **`onClusterWorkStop()` (broad, cause-agnostic).** Fired on **every** node of a
  minority partition on quorum loss (default of `onQuorumLost()`), and locally on a
  planned graceful-leave (from `initiateShutdown()`, cluster mode only). It is the
  project's directive to halt — and, if it wishes, persist — its in-flight business
  work. Distinct from `onLostLeadership`: a follower loses no leadership yet must still
  stop. The framework only delivers it; persisting and resurrecting the work is project
  code. It may fire more than once (quorum lost, then shutdown), so it must be
  **idempotent**.
- **`onLostLeadership()` (narrow, ex-leader only).** The framework default stops this
  node's cluster-singleton agents (`WorkerServer::onLostSingletonHost()`, the mirror of
  `onBecameSingletonHost()`) and clears `singletonsStarted`, so a truth source never
  outlives its term and a later promotion re-runs the start.
- **Resume.** No new hook — the project resurrects through the existing
  `onQuorumGained()` / `onBecameSingletonHost()` (leader) and the slave work-grant.
- **Graceful-leave.** A planned stop broadcasts a `PeerNodeLeavingDTO` on the peer mesh
  (a crash is silence), so peers tell an orderly departure from a failure. A leaving
  **leader** names its most-recently-heard follower as the `designatedSuccessor`, which
  campaigns immediately on receipt (`ClusterCoordinator::triggerDesignatedElection()`,
  raft TimeoutNow-style) while the other followers keep waiting their randomized timeout
  — leadership transfers with no election-timeout wait and no split vote. Fire-and-forget:
  the ordinary election is the fallback if the successor never takes over. A leaving
  **non-leader** names no successor and peers just update membership.
- **Slave grace.** On a leader change a slave keeps working (if it was) until a bounded
  grace deadline (`CLUSTER_SLAVE_WORK_GRACE_MS`) while it awaits the new leader's
  work-decision, so an isolated slave does not run forever. Now consumed by the self-fence
  below (HIL-183).

## Node health and failover (HIL-183)

Detection and failover for a node that goes down — including a hung-but-connected node the
ordinary socket close never catches — built on the registry (HIL-177), peer transport
(HIL-178), placement primitive (HIL-179), and quorum-loss reaction (HIL-341). Re-placement
picks the best-fit surviving node through the node-selection policy (HIL-182), which ranks by
declared capacity and breaks ties toward the node already running the fewest agents — declared
capacity never drops as agents land, so without that tiebreak a fleet of equal agents piles
onto one node.

- **Health detection — per-link keepalive.** Each `PeerLink` runs a keepalive in its
  `onTick`: any inbound frame refreshes "last heard"; after
  `CLUSTER_LINK_KEEPALIVE_INTERVAL_MS` of silence it sends a `peer_ping` (answered by a
  `peer_pong`), and after `CLUSTER_LINK_TIMEOUT_MS` of silence it closes the link. Closing
  reuses the existing `onLinkClosed → markOffline → onNodeLeft → noteNodeOffline` path — no
  new registry scan. It is symmetric (slave, master↔master, master↔slave), a busy link
  never pings, and the same timeout bounds a stalled half-open handshake.
- **Failover re-placement (leader).** `onNodeLeft` arms a failover for each placed agent the
  lost node hosted; after `CLUSTER_FAILOVER_GRACE_MS` (flap tolerance) the leader re-runs
  the `ClusterPlacement::placeAgentOnNode()` primitive onto another capable+online node
  (capability gate only). A node back before its grace cancels its own failover.
- **Slave self-fence (no double-run).** A slave that loses the link to the leader that
  placed its work stops those agents after `CLUSTER_SLAVE_WORK_GRACE_MS`, then reconnects
  via the existing peer dial. The self-fence grace is held **at or below** the failover
  grace, so the old copy of a truth source is stopped before the leader starts a new one.
  On rejoin the node reports what it still hosts (`PeerPlacementReportDTO`) and the leader
  reconciles against its view (leader = truth), issuing `peer_stop_agent` for anything
  already re-placed elsewhere — a returning node never resurrects a moved agent.
- **Degrade gracefully.** When re-placement finds no capable+online node, the agent is
  marked `PlacementState::Unplaced`, logged, and the project `onPlacementDegraded()` hook
  fires; the leader retries automatically when a capable node joins (`onNodeJoined`).
- **Wiring.** `PlacementState::Unplaced` and the `peer_ping` / `peer_pong` frames raise the
  peer protocol version to `4`. The framework `onNodeLeft` / `onNodeJoined` defaults now
  drive failover, so a project override must call the parent. `ClusterPlacement::tick()`
  runs the grace timers on the `PeerServer` loop beside the coordinator tick.

## Graceful shutdown

- SIGTERM/SIGINT → `shouldExit = true`
- `initiateShutdown()` → fires `onClusterWorkStop()` (cluster mode only), then calls
  `prepareShutdown()` on all servers (the `PeerServer` broadcasts the `NodeLeaving` frame)
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
