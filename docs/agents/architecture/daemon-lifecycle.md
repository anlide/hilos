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
   where `docker logs` reads when the two log addresses themselves are the missing ones:
   the watchdog then hands the child its own descriptors. With the addresses set and
   another name missing, the list lands in the daemon's raw output pair, and the container
   log shows it once the watchdog quotes the daemon's last words on the first failure.
   Only the daemon checks: in a container
   `docker.php` is the watchdog and runs `daemon.php` as its child, so the containerized
   start passes through here anyway, and the worker comes up under a daemon that already
   answered.
2. `LogRootOwnershipGuard::claimLogRoot()` claims this daemon as the owner of the log
   directory, or refuses the start when a marker already names another environment or
   node, or cannot be read. It runs after the required-environment check — `APP_ENV` and
   `DAEMON_LOG_FILE` are present — and **ahead of** `Logger::setLogFile()`. A refusal
   that the directory belongs to another daemon cannot be written into that daemon's
   journal; `Logger` without a file writes to stdout/stderr, which is `docker logs`,
   and that is where this refusal has to land. That is the opposite of
   `AnonymizationStartupGuard` below, which stands **after** `setLogFile()` on purpose:
   its reader is the author of a migration, and the refusal belongs in the daemon log
   where that author will look for it. Here the reader is the operator of the stand.
   Only the daemon carries it: the worker inherits the decision, and the CLI is where
   a stand is repaired.
3. `SetOwnershipGuard::assertMountedSetsDeclared()` refuses the start of a node whose
   mounted tables do not declare whose set their rows belong to. It reads class constants
   only and stands before the other guards, so the cheapest and most basic wiring question
   is answered first.
4. `SessionStageStartupGuard::assertRosterCarriesSessions()` refuses the start of a node
   whose browser connections roster stands on the presence stage instead of carrying
   session tokens. It reads only the in-memory map of mounted runtime collections and
   stands between the constant-only set-ownership guard and the live-schema query.
5. `AnonymizationStartupGuard::assertLiveSchemaClassified()` refuses the start of a node
   whose live schema is not classified for anonymization. Only a project declaring
   `HilosFeature::BACKUP` is asked at all — such a node keeps copies of a database it
   promises to be able to anonymize, and the promise is only as good as the verdict on the
   newest column. It reads the schema of every configured connection, the same set a
   backup dumps, and names every unclassified table and column in one refusal, because the
   reader is the author of the migration and one edit answers all of them. It stands here,
   before the manager exists, so a node that is not going to come up binds no port and is
   seen by no peer; and after `Logger::setLogFile()`, so the refusal lands in the daemon
   log where that author will look for it. In a container it therefore speaks on the very
   start whose migrations opened the gap — `docker.php` applies them before this runs.
6. `DaemonManager::__construct()` → `Hilos::initSignalRouter()`, creates `AgentManagerDaemon`
7. `daemon.php` registers servers: `HttpServer`, `WorkerServer`, `WebSocketServer` (optionally `FrontendHtmlServer`)
8. `daemon->run()` → creates `EventLoop`, sets up error/signal handlers, enters main loop
9. WebSocket server starts **only after** the required startup agents finish `onStart` (see below); with none declared it opens as soon as `WORKERS_READY`

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
  SIGKILL to whatever survives 5s. On a healthy restart it finds nothing and says so, one
  line at info level naming the count, because an absent record is otherwise
  indistinguishable from a sweep that never ran — `WorkerServer::prepareShutdown()` already
  stopped the workers — so anything it *does* find is by definition leftover and still gets
  its own warning per orphan. The invariant it buys is **"a daemon starts alone"**,
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
  `DAEMON_MIN_RESTART_INTERVAL` counts as failed; reaching it resets the count. Every
  unexpected stop is logged immediately with the process's terminating signal (preferred
  over an exit code), exit code or unknown status, the uptime, and what the daemon printed
  since this start across up to three streams in fixed order (`daemon-error.log`,
  `daemon-raw.log`, `daemon-error-raw.log`) within a shared 2000-byte budget. Because
  descriptors are kept open across daemon restarts and files may survive, stream sizes are
  marked at daemon start, and the quote only includes output appended after that mark.
  Once the run reaches `DAEMON_FAILED_START_THRESHOLD` (default 3), the watchdog adds a
  line about the series and sends its alert email; the series line does not repeat the tail
  that the individual failure already logged. It does **not** give up or exit: the cause
  may be external and temporary (a database still coming up, memory pressure), and the
  compose restart policy is deliberately left alone. The reason comes from the files and
  not from `Process::getStdErr()` — the daemon's stdout and stderr are redirected to files,
  so the process has no pipe to read. Under the PHP defaults of the image (`display_errors=1`,
  `log_errors=0`) a fatal is printed to stdout, master failures land in stderr, and deaths
  from unhandled warnings are caught by the node error log.
- **Say one thing when you die, and nothing else (HIL-617).** The watchdog is simple, and
  there is no watchdog for the watchdog. Its own failure is *not* damped by a per-iteration
  `try/catch`, not retried, and not handed to project code through a hook: it mails one
  letter and leaves. It is deliberately denied what the worker got in HIL-574
  (`containFailure` + `onTickFailure`), and the asymmetry is the whole point — a worker has
  someone to bring it back, `WorkerServer::ensureMinWorkers()`, and the watchdog has nobody.
  Making it survive its own failures by its own means *is* the watchdog for the watchdog.
  Who restarts a watchdog is a question outside Hilos: a compose restart policy, systemd,
  zabbix. The framework does not answer it, and the next author should not make it try.

**The watchdog does not refuse over an address it does not own (HIL-843).** With
`DAEMON_LOG_FILE` or `DAEMON_ERROR_LOG_FILE` unset it names the gap once at warning level,
skips the rotation it has no directory for, and hands the child its own descriptor
(`Process::DESCRIPTOR_REDIRECT`) in place of the file the address would have named, so the
daemon's own list of *every* missing name is what reaches `docker logs`. A descriptor and
not a path: `/dev/stdout` inside a container resolves to an anonymous pipe, which `open()`
answers with `ENOENT`, so no path leads to the container log.

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
  `WATCHDOG_ALERT_TIMEOUT_MS` from the first pump on. Every failure of the send is a log
  line and nothing more.
- **Where the honesty ends, in plain words.** A letter arrives for a failure carrying an
  exception or a PHP fatal. On `kill -9`, on the OOM killer, or when the container itself
  goes down, there is no letter, and this is not a gap to be closed from the inside —
  those are watched for from outside (zabbix, the restart policy).
  There is also no letter for a failure *before* the loop: the path check, the missing
  functions and the log rotation all run before `WatchdogAlertMailer::fromEnv()` is built,
  so a full log volume kills the watchdog silently. And `WATCHDOG_ALERT_TIMEOUT_MS` does
  not cover the name lookup inside the connect, so a box whose DNS is gone can hold the
  dying watchdog for as long as its resolver takes. Both are the price of a watchdog with
  no watchdog, not gaps to be closed from the inside — the deep handlers that would close
  them (a resolver of our own, the mailer built before the file work, a `try` around the
  whole run) are exactly what this must not grow, and there is nobody to catch them when
  they misfire. What an operator can do is give `WATCHDOG_ALERT_SMTP_HOST` an IP address:
  then there is no lookup at all.

**The daemon owns its log files.** `startDaemon()` hands its stdout and stderr to the raw
twins of `DAEMON_LOG_FILE` and `DAEMON_ERROR_LOG_FILE` — `daemon-raw.log` and
`daemon-error-raw.log`, named by `DaemonRawStream` (HIL-480) — as file descriptors, so the
daemon's output is written by the kernel and never passes through the watchdog: there is
nothing for `tickDaemon()` to tee into the watchdog's own log, and it does not try. The
single exception is the failed-start escalation above, which reads the tail of the raw
error stream — a deliberate read of a file, not of a stream.

**The watchdog's own errors land in the same error file.** `DockerApplication::run()` calls
`Logger::setErrorLogFile(DAEMON_ERROR_LOG_FILE)` at startup and deliberately does *not* call
`setLogFile()`: with no main log file the logger keeps echoing its whole feed to stdout, so
`docker logs` — the first place a dead node is read — stays complete, while errors are
additionally appended to the file. So both halves of an incident (the daemon's crash trail
and the watchdog's stop/restart trail) end up in one file, the one the Hilos logs admin page
already reads. The daemon does the same for its own process next to `setLogFile()`.

What the container log is *for* — the watchdog's voice and the details of a daemon crash,
and nothing else — is the owner's rule in [logs.md](logs.md), "The Container Log Is A
Glance, Not The Record"; read it before adding a line to `docker logs` or taking one away.

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
  ensureSingletonsStarted()  ← start cluster-singleton agents once per term, and again after a worker dies with agents
  tickReadiness()            ← open the WS once required startup agents are ready
  checkCronJobs()            ← once per minute, after workers ready
dispatchSignals()      ← drain SignalRouter queue → workers / WS clients
Hilos::$ac->tick()     ← analytics flush
pcntl_signal_dispatch()
sleepWithPreciseTiming()
```

A refused agent start is not fatal to the loop (HIL-999). An agent of this node that does
not come up in `dispatchSignals()` — no free worker, or any other failure the worker server
declares for the reach — is written down, handed to the project as an `AGENT_START` card, and
answered: a waiting page gets `subscription_page_error` with `errorCode: agent_unavailable`,
an operator gets a command reply, a push gets nothing. `ensureSingletonsStarted()` contains a
failing `onBecameSingletonHost()` the same way and still marks the start done.

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

Liveness is born of this node's own link and of nothing else (HIL-1059): its own
handshake with a node puts it online (`onNodeJoined`), and the close of its last link
to a node or the leave frame the node sends about itself puts it offline
(`onNodeLeft`). `peer_roster` and `peer_announce` carry membership only — id, role,
capabilities, address — so a node heard of only from a neighbour lies in the registry
offline, known and not yet seen, until its own handshake. `onNodeJoined` also fires on
a membership change of a node that is online here: the node announced its own new
capability, and a leader retries what it could not place.

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
hears about it — one empty `protected` hook for every guard, because the master is
one process and a project should not have to override one place per guard to count one thing.

The card is `Hilos\Core\Daemon\ContainedFailure`: the unit, the address, the failure.
It is the same card the worker's `onTickFailure()` takes (HIL-574); only the enumeration
of units differs, and the two are held together by `FailureUnit`. The master's units are
`MasterFailureUnit`:

| Unit | What failed | Address |
|---|---|---|
| `CONNECTION` | one live connection, read by its server's tick or by the loop's read callback | server name, plus ` acceptKey=<key>` once a WebSocket connection is past its handshake |
| `CONNECTION_ACCEPT` | an incoming connection the server could not accept | server name |
| `LOOP_ITERATION` | one iteration of the main loop, after which the node leaves | `daemon loop` |
| `AGENT_START` | one agent that did not come up, after which the node keeps running | the agent id, or `cluster singletons` for the project's singleton hook |

A further case, `FAILURE_HOOK`, names the hook itself as a guarded unit, but no card ever
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

## Peer channel trust (HIL-1034)

Every link between two nodes is **mutual TLS** on the framework's own transport, and
there is no plain mode: the peer port is internal to the cluster, and a network that
encrypts the wire (a docker bridge, Tailscale) still cannot tell a node from any other
process on it — one behavior instead of a flag, as the incoming half already decided
(`AbstractTlsServer`, "There is no plain mode").

**What it defends against.** A *stranger*: a process on the same network without a
certificate of the cluster's authority. Before this, any process that reached the peer
port could introduce itself as any node — a master of `CLUSTER_MASTER_SET` included —
vote, and receive every RT and DB sync frame; the hello/welcome frames carry only
self-declared fields and no secret. A holder of a valid node certificate is a member:
names inside frames after the handshake (a `voterId` in a vote, nodes in a roster or an
announce) are **not** checked against the link's name.

**Trust.** One authority per cluster, one certificate per node, and the certificate's
common name (CN) is the node id. A neighbour is accepted only when its certificate is
signed by an authority of the trust file **and** names exactly the node id its hello
(accepting side) or welcome (dialing side) introduces. The name is checked by
`PeerLink` (`requireCertifiedAs()`), not by OpenSSL (`verify_peer_name` is off): a seed
is dialed by address, before anyone knows the name behind it, and one place of the check
serves both sides. The check stands before the link remembers the peer or tells the
server about it; a mismatch drops the link through the same branch as an incompatible
protocol version (`Peer link dropped: …`), and the registry is not touched. Addresses are
not a basis for trust (they churn, HIL-343), and neither are pinned fingerprints (the
membership is not known in advance, HIL-346). The role is not in the certificate: who may
be a master is still `CLUSTER_MASTER_SET`, by name.

**Two environment values**, both required when `CLUSTER_ENABLED` is true:

| Value | Holds |
|---|---|
| `CLUSTER_TLS_CERT_FILE` | this node's certificate followed by its private key, one PEM |
| `CLUSTER_TLS_CA_FILE` | the certificates of the authorities the node trusts; several one after another while the authority is replaced |

**The start refuses.** `ClusterTlsConfig::fromEnv()` runs in `PeerModule` before the
peer port opens, and the first check that fails stops the start with its reason
(`ClusterConfigurationException`): a value is empty; a file does not read as PEM; the node
file has no private key that fits its certificate; the CN is not `CLUSTER_NODE_ID`; the
certificate has expired; no authority of the trust file signed it for **both** server and
client use (a node accepts and dials). Expiry is checked before the chain on purpose — the
chain of an expired certificate fails too and would name the wrong reason. Under 30 days
to the end the node starts and warns: `Cluster TLS certificate of node '<id>' expires on
<date>; issue a new one with cluster:tls:issue`. A node that started and quietly stayed out
of the cluster would be worse than one that did not start and said why.

**Who names a refused handshake, and why both ends.** A transport given a trust file names
every refusal as `SocketTlsHandshakeException` —
`Socket TLS handshake failed: <ip:port>: <OpenSSL's reason>` — which leaves the client's
read and takes the ordinary road of a failing connection: the rate-limited WARNING of
`ClientReadFailureLog`, the contained-failure card to the project, the link dropped. The
dialing side retries on its usual five seconds. In TLS 1.3 a refusal of the **dialer's**
certificate is known only to the accepting side: the dialer finishes its own handshake and
then finds the connection closed. So the accepting node always names it, the dialer names
it when the reason is its own (it does not trust the acceptor), and a dialer whose link the
peer closed after TLS and before any welcome writes one line per series to that target —
`Peer <host>:<port> closed the link before welcoming this node; that node's log names the
refusal` — and the next only after a handshake with that target has been taken to its end.
A link the dialer dropped itself before the welcome — a silence timeout, a frame it refused,
the welcome included — says so in its own line and is not blamed on the peer.
A refusal on the peer port means a misconfigured node or a stranger; the operator must see
either. A public port (a TLS server with no trust file, such as the stand gateway) asks no
client for a certificate and closes a refused handshake without a word, as before.

**Issuing — three framework commands**, all printing PEM to stdout and nothing else,
database-free and run in the CLI process (`cli-read`): they touch nothing the installation
owns, and where a file goes is the operator's decision.

```
cluster:tls:ca                       > cluster-ca.pem   authority: certificate + key, stays with the operator
cluster:tls:trust cluster-ca.pem     > ca.pem           the certificate alone - on every node
cluster:tls:issue m1 cluster-ca.pem  > m1.pem           node m1's certificate + key - on node m1 only
```

Keys are EC prime256v1, signatures sha256, serials from the secure random source. The
authority lives ten years; a node certificate lives ten years or what is left of the
authority, whichever is shorter. Every authority carries the same name, `Hilos cluster CA`,
so a certificate names its signer by key identifier (`subjectKeyIdentifier` on both,
`authorityKeyIdentifier` on a node): without it OpenSSL takes the first authority of that
name in a trust file as the signer, and a file holding two would refuse every node of the
second.

**Replacing.** A node certificate: issue a new one and restart that node. The authority: the
trust file carries the old and the new authority one after the other (in either order),
nodes restart one at a time; their certificates are reissued by the new authority, again one restart at a time; then
the old authority leaves the trust file. There is **no revocation list**: a leaked node file
is closed by replacing the authority. There is no hot reload of the files either, and no
session resumption — links are few (N−1 per node) and long-lived, and a restart re-reads
everything, as it does for the role and the seeds.

**Rolling out** is one step: a node of the old code and a node of the new simply do not link.
`PeerProtocol::VERSION` did not move — no frame changed. The stand `demo/cluster` carries its
own fixtures and a node of a foreign authority, `x1`, which scenario 17 shows refused on both
ends and listed by nobody (`demo/cluster/README.md`).

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
  one side below majority for free. Online means reachable over this node's own link;
  a neighbour's word about a master does not count (HIL-1059). A master that cannot
  see a quorum stops leading.
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
  leadership term and once more after each worker that dies hosting agents (the
  loss re-arms the flag, HIL-502) — never per tick, and never while the node is
  leaving. The base `onBecameSingletonHost()` queues
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

## Who may carry placed work (HIL-445)

Recorded because on 26.07.2026 a master's work was expected to be picked up by a
neighbor when that master went down, and no log could say there had never been any:
"this master carries nothing" was an empty configuration string, not a decision. Five
statements; the mechanism they govern is built by HIL-447 and HIL-448.

1. **Role is not a placement gate, and is not going to become one.** Candidacy is
   decided by what a node *declares*, never by whether it is a master or a slave.
   Selection works this way: `ClusterPlacement::pickBestNode()` builds a candidate from
   every online node, and `PlacementCandidate::accepts()` — required tags, a declared
   capacity, free room for the agent's cost — is the whole hard gate, the one
   implementation both the policy and a placement by node name go through. The word
   "master" appears nowhere in it.
2. **A master may carry placed work, and carries node replicas today.** A `NODE`-scope
   replica starts on any node (the placement gate in `WorkerServer::startAgent()` lets
   it through), and `demo/cluster` runs its `db_probe` replica on two masters in a green
   scenario (`scenario_11_cross_node_db_fact` writes on m1 and reads on m2) — a replica is
   not placed by the policy and needs no declared capacity. What the stand's masters do not
   accept is placed work, and by rule 4: they declare no capacity
   (`CLUSTER_NODE_CAPABILITIES: ""` in `docker-compose.cluster.yml`).
3. **The leader carries work by construction, and is a legal placement target — last
   among equals.** A `CLUSTER`+`LEADER` singleton runs where leadership sits (see *The
   placement gate* above). For policy placement, among otherwise equal candidates the
   node-selection policy picks the leader last, and only when no other eligible
   candidate exists does the work go to it rather than staying unplaced: a three-master
   cluster with no slaves must still place its work. It is a ranking rule in the
   tiebreak chain of `BestFitPlacementPolicy::selectNode()`, not a gate. It sits right
   after the load after placement and before the head count: behind the head count the
   leader would take every N-th free agent as soon as the other nodes caught up.
4. **Acceptance of placed work must be declared.** A node that declares no capacity is
   not a candidate; the declaration is the capacity model (see *Consumable capacity and
   agent cost* below), and the refusal that names its absence is the machine-readable one
   (not in the code yet — HIL-447). This inverted the earlier default, where an agent that
   declares no required tags ran anywhere and "this master carries nothing" was produced by
   an empty configuration string rather than by a decision. The rule is in the code: a node
   with no `key=value` in `CLUSTER_NODE_CAPABILITIES` is no candidate for the policy, a
   placement naming it is refused (`PlacementCapabilityException::noDeclaredCapacity()`),
   and every clustered node logs at start which capacity it declares or that it declares
   none — so the stand's masters with `""` accept no placed work by rule.
5. **There is no master-specific ceiling, and none will be added.** How much work a node
   accepts is one model for every node: consumable capacity and an agent's declared cost,
   the cap and the honest refusal
   (not in the code yet — HIL-447). A busy master is a scheduling question, not a role
   question — agent work runs in a worker *process*, not on the master loop
   ([agent-lifecycle.md](agent-lifecycle.md)), so the risk is host CPU/IO contention,
   not a blocked event loop.

## Consumable capacity and agent cost (HIL-448)

Placement is resource accounting, not a pick by labels and head count.

- **Capacity.** A node declares it in `CLUSTER_NODE_CAPABILITIES`, parsed by
  `NodeCapacities::fromTags()`: a bare token (`worker`, `gpu`) is a boolean capability, a
  `key=value` token with a number (`ram=10`, `slots=4`) is a consumable stock of the
  resource `key`. Resource names are free — the resource worth guarding is the project's
  (model slots, GPU memory), and the framework cannot list it. A node declares capacity when
  it has at least one numeric tag, zero included; a resource it does not name is 0.
- **Cost.** An agent declares it through `placementProfile()` on its daemon proxy:
  `ResourceProfile::costs(['ram' => 2.0])`, zeros dropped, a negative value a
  `LogicException`. The default `ResourceProfile::none()` costs nothing. The leader reads the
  cost on its master loop each time it chooses a node, so it comes from the agent type, index
  and constants — no database, file or network I/O
  ([heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)). An agent that does
  not know its appetite in advance (an LLM worker under different models) declares a
  reservation; nothing measures live consumption.
- **Held capacity is derived, not counted.** For a node, it is the sum of the costs of the
  leader's registry records on it in state Placing or Started; Unplaced, Refused, Failed and
  Stopped hold nothing. The record of the agent being placed is left out, so its old
  reservation never blocks its own re-placement. The cost is read from the executor at the
  moment of choice — `PlacementRecord` does not store it.
- **Gate** — `PlacementCandidate::accepts()`: every required tag, a declared capacity, and
  free (declared minus held) at least the cost for every resource the agent costs.
  Oversubscription is refused outright; to oversubscribe, declare more than the hardware has.
  `ClusterPlacement::placeAgentOnNode()` checks the same gate against the same occupancy, so
  a placement by name cannot overfill a node past the accounting.
- **Ranking** — `BestFitPlacementPolicy`, in order: the lower load after placement (the
  highest, over the costed resources, of held-plus-cost over declared; equal within `1e-9`),
  which fills the nodes to equal shares — proportional to capacity — and sends a heavy agent
  where it is the smaller share; a node that is not the leader ahead of the leader (HIL-445
  rule 3, placed before the head count so it keeps biting); fewer live placements; the
  greater total declared capacity; the smaller id. An agent that costs nothing scores 0
  everywhere and is spread by head count, as before costs existed.
- **Release and a new leader need no code.** A stop forgets the record, a move rewrites it,
  a node loss either moves it or degrades it to Unplaced — each frees the reservation by
  moving the record. A dead node is not online, so while its failover grace runs it is no
  candidate and its reservation stands in nobody's way. A fresh leader rebuilds the registry
  (`onBecameLeader()` plus the node reports) and derives the held capacity from it the same way.
- **What spends no capacity.** `NODE`-scope replicas and `CLUSTER`+`LEADER` singletons are
  not in the placement registry — no node is chosen for them. The operator declares a node's
  capacity net of what the node carries by itself.
- **Not here.** The node-level cap, the machine-readable refusal and its visibility —
  HIL-447. What happens to work that fits nowhere, including a retry of Unplaced agents when
  capacity is freed (today `retryUnplaced()` runs only when a node comes online) — HIL-446.
  Moving running agents when a node appears or capacity frees — HIL-443; drain — HIL-444.

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
load after placement over the consumable capacity (HIL-448) and breaks ties toward the node
already running the fewest agents — agents that cost nothing are spread by that head count
alone, so a fleet of equal free agents does not pile onto one node.

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
  (capability gate only). A node back before its grace cancels its own failover: only the
  leader's own handshake can put a node back online, the registry reports that return every
  time, and `noteNodeOnline()` calls the failover off and logs `Failover of <n> agent(s) on
  '<node>' called off` (HIL-1059; a slave's self-fence against its placing leader the same
  way). The deadline
  carries the node whose loss armed it, and firing it re-places only an agent the registry
  still puts there: inside one grace period the fleet's own supervisor may restart the agent
  on a neighbour, and a deadline outliving that move would start a second copy on the node it
  names — which that re-check against the registry is what stops (HIL-719). The HIL-696 guard
  does not: since HIL-913 a claim whose agent id matches the holder reads as the agent having
  MOVED, the older incarnation is evicted from the leader's map, and a report from a node that
  has left the mesh is not folded at all. A second copy of a PLACED agent on a node that stayed
  linked is named by placement itself (HIL-976): a node that takes a `peer_placement_view`
  giving one of its agents to another node sends `peer_placement_report` at once, and the
  leader answers `peer_stop_agent` with the "already placed on" line. That reading is for
  placed agents only. An agent
  declared `AgentScope::NODE` is never placed, so it never moves; the leader keeps the entry of
  every node holding it, and a second whole owner of what it owns is still refused for good.
- **Placement-ack timeout (leader, HIL-930).** A record left `Placing` waits for a status that
  may never come — the target node can be recreated before it answers, and a rejoin inside the
  failover grace clears the failover deadline without anyone judging the `Placing`. So the wait
  is bounded by `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS` (default 16000, at or above two failover
  graces, so an ordinary flap is settled by failover first), and what the timeout fires is a
  QUESTION, not an action: one `peer_placement_query` per record, sent to the node the record
  names. Three outcomes, all on paths that already existed: the node names the agent in its
  snapshot and the record becomes `Started`; it does not name it and the record is forgotten and
  re-placed best-fit; it answers nothing at all, which means the link is dead, and
  `CLUSTER_LINK_TIMEOUT_MS` turns that into the failover above. Re-placing on the timeout itself
  was rejected: `onAgentStatus()` writes the record by SENDER, so a late `started` from the old
  node would point the record back at it while the new copy runs unnamed. Guarding that is the
  other half — a status from a node the record no longer names never moves the record; a late
  `started` gets a `peer_stop_agent` back, a late `stopped` or `failed` is dropped in silence
  (`onStopAgent()` answers `stopped` unconditionally, so a stop sent back at one would loop the
  pair of nodes). Deadlines are derived by sweeping the registry each `tick()` rather than armed
  where the registry is written, because eight paths write it and one that forgot to arm would
  leave its record waiting forever.
- **Slave self-fence (no double-run).** A slave that loses the link to the leader that
  placed its work stops those agents after `CLUSTER_SLAVE_WORK_GRACE_MS`, then reconnects
  via the existing peer dial. The self-fence grace is held **at or below** the failover
  grace, so the old copy of a truth source is stopped before the leader starts a new one.
  On rejoin the node reports what it still hosts (`PeerPlacementReportDTO`) and the leader
  reconciles against its view in both directions. The report is a COMPLETE snapshot and goes
  out on every new link, empty set included, and again whenever the leader's published view
  gives an agent this node hosts to another node (HIL-976). For what it NAMES, leader = truth: a
  `peer_stop_agent` goes back for anything already re-placed elsewhere, so a returning node
  never resurrects a moved agent. For what it does NOT name, the node is truth: an agent the
  leader still tracks `Started` there is running nowhere, so the record is forgotten and the
  agent re-placed best-fit at once, with no grace waited out (HIL-719) — the emptied node is
  the least loaded candidate, so its own fleet comes back to it. A container recreated faster
  than the failover grace is exactly that case, and until it was made to speak up the leader
  reported a dead fleet as started for the rest of the term. `Refused` (HIL-696), `Failed` and
  `Unplaced` records are left alone, and so is a `Placing` one whose place frame may still be in
  flight — but only until `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS` elapses: a `Placing` the leader has
  already asked this node about is judged by the snapshot exactly as a `Started` one, because
  the snapshot is the answer to that question (HIL-930).
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
- `run()` returns a `DaemonDeparture` saying why the node left, and `DaemonApplication`
  turns it into the process code: `0` for an ordinary stop, `ExitCode::ERROR` when there
  was a failure on the node's way out — a failed loop iteration (HIL-569), the entropy
  stop (HIL-568), or one of the three PHP handler hooks. SIGTERM, SIGINT and SIGHUP write
  nothing and leave the ordinary default in place, and the expiry of `shutdownTimeout`
  does not change the reason either: missing the deadline is a slow close, not a failure.

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
