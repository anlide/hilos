# Worker Lifecycle

Workers are forked by `WorkerServer` on demand. Two types exist: **regular** and **monopolistic**.
A warm-up of each is started ahead of time (`WORKER_MIN_REGULAR`, `WORKER_MIN_MONOPOLISTIC`, one
worker a second); past it, regular workers scale up to `WORKER_MAX_REGULAR` and monopolistic ones
are raised one per agent that finds none free.

## Startup

1. `worker.php` (Bootstrap) runs in forked process
2. `WorkerManager::__construct()` → `Hilos::initSignalRouter()`, creates `AgentManager`
3. Worker connects back to daemon via `WorkerDaemonClient` (TCP)
4. Worker sends `WORKERS_READY` signal when initialized
5. Main loop starts: reads messages from daemon socket, ticks agents

Initial process-wide source snapshots arrive before agent starts (HIL-1232).
The worker holds starts and their addressed frames behind its registration-time
source wait; snapshot and readiness frames pass through to release it. This keeps
a late initial snapshot from replacing an index an owner's `onStart()` already
rebuilt, including the backup index after placement moves to another node.

## Message types from daemon

| Message | Handler |
|---|---|
| `DaemonAgentMessageDTO` | Route signal to target agent |
| `DaemonWorkerSignalDTO` | Hand a project signal to `onDaemonSignal()` (HIL-618) |
| `AgentStartDTO` | Create & start agent |
| `AgentStopDTO` | Stop & remove agent |
| `WorkerDbSyncCreated/Updated/Deleted/ClearedMessageDTO` | Apply DB sync to local context |
| `WorkerRtSyncCreated/Updated/DeletedMessageDTO` | Apply RT sync to local context |
| `WorkerPageAccessReassessMessageDTO` | Sweep this worker's page subscriptions for the named user (HIL-644) |
| `SystemSignalDTO` | System signals |
| `CronSignalDTO` | Route cron to agent |

`onDaemonSignal(string $signalName, SignalDataInterface $data)` is the receiving half
of the master's `sendToWorkers()` door (see
[daemon-lifecycle.md](daemon-lifecycle.md#handing-work-out-of-the-master-hil-618)):
every worker of the node gets the call, and the payload arrives as the class the
master sent when this process knows it. It is empty by default and carries no guard of
its own — the call lands inside the tick's guard, so a reaction that raises is
contained as a `DAEMON_MESSAGE` failure and reaches `onTickFailure()`.

The card `onTickFailure()` takes, `ContainedFailure`, is shared with the master since
HIL-619: the same three facts describe a failure wherever it was caught, and only the
enumeration of units is per process (`WorkerTickUnit` here, `MasterFailureUnit` there,
both `FailureUnit`). The master's side of it is
[daemon-lifecycle.md](daemon-lifecycle.md#answering-a-contained-failure-hil-619).

Off the master's loop is not off every loop. The hook runs on the worker's tick, so it
is bound by the same bar as `onTick()` and every other signal handler — see
[blocking-in-ontick.md](../antipatterns/blocking-in-ontick.md). What the worker buys
you is the database and the project's own state, not permission to block: work that
cannot finish promptly belongs on a queue drained an item per tick, or in a
monopolistic agent.

## Agent management in worker

- `AgentManager::startAgent(type, index)` — creates agent, calls `onStart()`
- Agent stop messages call `onStop()`, unregister DB/RT truth sources in
  `finally`, then remove the agent from the map
- Each tick: iterate agents → call `agent->onTick()`
- If `agent->shouldStop()` → same stop flow as an agent stop message

## Regular vs Monopolistic

- **Regular**: handles WebSocket/page signals, multiple instances possible
- **Monopolistic**: single instance per cluster, handles shared state (DB truth source, context)

A monopolistic worker holds exactly one agent. `WORKER_MIN_MONOPOLISTIC` is a warm-up, not a
ceiling: an agent that finds no free monopolistic worker has one raised for it at once and waits in
the master's register until it registers, with the frames addressed to it held meanwhile; a wait
past `AgentConstants::START_DEADLINE_SECONDS` is refused like any start refused on the node
(HIL-998). A monopolistic agent may not be per-instance — a start with an index is refused rather
than grown for, so the pool follows agent types, never entities. The pool does not shrink: a
worker lives until the node stops, and a freed one goes to the next agent that needs one.

Set via `$isMonopolistic` property in `WorkerManager` subclass.

## Graceful shutdown

A worker leaves through one door, `WorkerManager::cleanup()`, whatever made it leave: SIGTERM from
the master stopping the node, an error or an exception that ends the loop, a lost daemon connection,
or an orphaned worker. On the way out it runs the ordinary stop flow for every agent — `onStop()`,
truth sources taken back, the release of its RT sources reported — then records its own analytics
session stopped and hands the last analytics batch over (HIL-1154), then sends the master what the
stop hooks queued together with that batch, writes its buffer out until it is empty, and only then
disconnects (HIL-1136).

A node stops its workers in two waves when one of its agents asks for it
(`AgentDaemonInterface::stopsAfterOtherWorkers()`, today the analytics journal agent of the node —
[analytics.md](analytics.md)). The first wave is SIGTERM to every other worker. Once their processes
have exited and their connections are closed — the master reads a connection a buffer at a time, so
an exited worker's last batch may still sit in its socket — the pass that saw it dispatches their
last frames and the master's batch closing remembered pages and connections; the next pass stops the held
agent with an ordinary `agent_stop` over its connection — behind every frame sent to it before —
and its worker gets SIGTERM once it has reported that agent stopped — a worker reads its connection
a buffer at a time, and a SIGTERM sent on a count of passes cut off the batches still unread behind
the stop (HIL-1314). The master's shutdown ceiling covers both waves.
A node with no such agent stops in one wave, as before.

Sending those frames is an attempt, not a guarantee:

- A master that has already gone takes them with it: the write fails, and whatever was not written
  is lost. An orphaned worker — its master gone without the connection closing — does not write at
  all.
- The worker keeps no clock of its own for the write. When the master stops the node, it kills a
  worker still writing once its shutdown timeout runs out.
- A stopping node closes its browser entrance at once, stops reading the browsers already connected,
  and keeps writing to them. They close only once the workers are gone — processes exited and
  connections closed — and what was sent to them is written, so a frame a stop hook addresses to a
  browser connected here reaches it (HIL-1207). DB/RT sync and what the master passes on to its peers
  travel on; the node leaves only once its peer links have written it.
  A frame a stop hook addresses to another agent leaving in the same wave finds it gone and is
  dropped ("Signal skipped during shutdown"): a holder the others report to on their way out
  settles what it holds on its own stop instead - the sessions library ends the sign-ins still
  going (HIL-1207).

A worker that leaves while its master keeps serving — an error or an exception — is read like any
running worker, and its frames reach their addressees.

Stopping one agent — a stop message, `shouldStop()`, the idle window — is not a departure of the
worker: the frames its `onStop()` queued go out in the same tick.
