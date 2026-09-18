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

On SIGTERM: drain pending messages, run the same stop flow for all agents, disconnect from daemon.
