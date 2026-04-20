# Event Loop

Hilos uses **epoll** via PHP `ext-event` (libevent) for non-blocking I/O.

## Class: `EventLoop`

Wraps `EventBase` from the event extension. Lives in `DaemonManager`.

## How it works

```
EventLoop::registerRead($socket, $callback)  ← watch socket for incoming data
EventLoop::tick()                            ← process all ready events (non-blocking)
EventLoop::unregister($socket)               ← MUST be called before closing socket
```

**Critical order for socket close:**
```php
$this->eventLoop->unregister($socket); // 1. unregister FIRST
$client->close();                      // 2. close after
```
Never close a socket before unregistering — event loop will hold a dangling reference.

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

After each main loop iteration, daemon sleeps to maintain a precise tick interval.
This keeps CPU usage low while still being responsive.
