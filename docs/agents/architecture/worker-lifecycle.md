# Worker Lifecycle

Workers are forked by `WorkerServer` on demand. Two types exist: **regular** and **monopolistic**.

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
| `AgentStartDTO` | Create & start agent |
| `AgentStopDTO` | Stop & remove agent |
| `WorkerDbSyncCreated/Updated/Deleted/ClearedMessageDTO` | Apply DB sync to local context |
| `WorkerRtSyncCreated/Updated/DeletedMessageDTO` | Apply RT sync to local context |
| `SystemSignalDTO` | System signals |
| `CronSignalDTO` | Route cron to agent |

## Agent management in worker

- `AgentManager::startAgent(type, index)` — creates agent, calls `onStart()`
- Agent stop messages call `onStop()`, unregister DB/RT truth sources in
  `finally`, then remove the agent from the map
- Each tick: iterate agents → call `agent->onTick()`
- If `agent->shouldStop()` → same stop flow as an agent stop message

## Regular vs Monopolistic

- **Regular**: handles WebSocket/page signals, multiple instances possible
- **Monopolistic**: single instance per cluster, handles shared state (DB truth source, context)

Set via `$isMonopolistic` property in `WorkerManager` subclass.

## Graceful shutdown

On SIGTERM: drain pending messages, run the same stop flow for all agents, disconnect from daemon.
