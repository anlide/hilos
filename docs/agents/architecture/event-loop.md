# Event Loop

Hilos uses **epoll** via PHP `ext-event` (libevent) for non-blocking I/O.

## Class: `EventLoop`

Wraps `EventBase` from the event extension. Lives in `DaemonManager`.

## How it works

```
EventLoop::registerRead($socket, $callback)  ← watch socket for incoming data
EventLoop::tick()                            ← process all ready events (non-blocking)
EventLoop::unregister($socket)               ← master client-detach or browser-entrance close
EventLoop::registeredCount()                 ← how many sockets are on the watch
```

**One way out for a client.** A socket must come off the watch before it is closed,
or libevent keeps a dangling reference to a closed descriptor — and to the dead
client behind its read callback. That order is not repeated at each exit; every one
of them goes through the server's door:
```php
$server->dropClient($client); // off the watch, closed, forgotten — in that order
```
`AbstractServer::dropClient()` announces the departure through
`ClientSocketDetacher`, the seam `DaemonManager` implements and hands to every server
in `registerServer()`. A departing master also calls `unregister()` before closing
the WebSocket server's listening socket in `closeBrowserEntrance()` (HIL-1207).
Client sockets still leave only through `dropClient()`; do not close one outside that door.

## What runs in the event loop

- Server accept events (new connections)
- Client read events (incoming data)
- Write buffer flush after read

Callbacks are wrapped in try/catch — exceptions are logged but do not crash the loop.

## What blocks the loop

Any synchronous blocking operation in a callback or `onTick()` blocks all I/O:

- `sleep()` / `usleep()`
- Synchronous HTTP calls (`file_get_contents`, `curl_exec` without async)
- Long DB queries
- CPU-heavy computation

→ Move blocking ops to **monopolistic agents** with their own timing, or use async LLM client.

## Timing

The master: after each main loop iteration it sleeps the rest of a 10 ms tick
(`BaseManager::sleepWithPreciseTiming()`).

A worker has no event loop of its own and does not sleep on a clock while it is
idle. A pass that handled a message, left bytes for the daemon, or has a
tick-driven operation in flight keeps the 10 ms tick. Any other pass waits on the
daemon socket (`WorkerDaemonClient::awaitReadable()`) until the daemon sends
something, at most 100 ms (`WorkerManager::waitForWork()`). A worker that woke a
hundred times a second for nothing cost about 1 % of a core per process.

That wait is libevent's, like the master's, and never `socket_select()`: a select
cut short by a signal raises a warning of its own, PHP calls the error handler for
it even under `@`, and an asynchronous signal handler then runs inside that call,
where its own warning bypasses the handler.

A tick-driven operation is one that moves only when its owner ticks it — an
`AsyncHttpClient`, `AsyncCommandClient`, `SmtpMailTransport` or child `Process`.
Each registers itself with `TickDrivenIoRegistry` when built and answers
`isInFlight()`; nothing wakes the worker's wait when its socket or pipe becomes
ready, so while one is in flight the worker keeps the short tick.
