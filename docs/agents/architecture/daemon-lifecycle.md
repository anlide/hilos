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
onTick()               ← app logic (override in subclass)
tickReadiness()        ← open the WS once required startup agents are ready
checkCronJobs()        ← once per minute, after workers ready
dispatchSignals()      ← drain SignalRouter queue → workers / WS clients
Hilos::$ac->tick()     ← analytics flush
pcntl_signal_dispatch()
sleepWithPreciseTiming()
```

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
