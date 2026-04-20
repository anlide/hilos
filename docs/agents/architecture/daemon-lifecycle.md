# Daemon Lifecycle

**Entry point:** `Bootstrap/daemon.php` → creates `DaemonManager` subclass, registers servers, calls `run()`.

## Startup sequence

1. `DaemonManager::__construct()` → `Hilos::initSignalRouter()`, creates `AgentManagerDaemon`
2. `daemon.php` registers servers: `HttpServer`, `WorkerServer`, `WebSocketServer` (optionally `FrontendHtmlServer`)
3. `daemon->run()` → creates `EventLoop`, sets up error/signal handlers, enters main loop
4. WebSocket server starts **only after** `WORKERS_READY` signal (workers must be up first)

## Main loop (each iteration)

```
processEventLoop()     ← epoll: accept connections, read data
servers->onTick()      ← process buffered client data
onTick()               ← app logic (override in subclass)
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

Register: `$this->addCronRule('name', '*/5 * * * *')`.
Override `onCron(CronRule $rule)` to handle execution.
Cron fires only after `WORKERS_READY` and at most once per minute.
