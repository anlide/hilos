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
- Each client's `onTick()`, once per master tick: `AbstractServer::onTick()` runs
  `read()`, `onTick()`, `write()` for every client in turn and drops the one that
  answers `shouldClose()`. A browser socket keeps its liveness there, see
  [Browser Socket Liveness](#browser-socket-liveness)

Callbacks are wrapped in try/catch — exceptions are logged but do not crash the loop.

## Read Budget And Upload Memory

`SOCKET_READ_BUFFER_SIZE` (default 65,536 bytes in `EnvCatalogStub`) bounds
**one `AbstractClient::read()` call**. That call gathers transport reads up to
the budget or until would-block, EOF or error, then parses the gathered bytes
once, before handling EOF/error. TLS may return only a record's worth of data
(up to 16 KiB) per transport read; several reads can fill the same budget.

`EventLoop::tick()` uses `EventBase::LOOP_NONBLOCK`. Libevent can call a
persistently readable socket's callback repeatedly before returning to the
master's `dispatchSignals()`. The read budget therefore bounds neither all
callbacks in a pass, the signal queue, nor accumulated upload memory. It is
not a per-tick allowance; the loop does not use `LOOP_ONCE`.

`WorkerDaemonClient::read()` has a different job: drain the daemon socket
until would-block, in 65,536-byte chunks, then parse the gathered input before
handling EOF or reset. Already parsed messages remain available after link
loss. This does not change the worker's idle/readability timing below.

The string XOR in `WebSocketClient::parseFrame()` repeats the mask key over
the payload. The structural `strcspn()` scan in
`AbstractSocket::extractCompleteJsonMessage()` skips spans that cannot change
JSON framing state. Both reduce CPU work while preserving payload bytes,
escape/string/depth state and existing framing limits; neither creates a
flow-control budget.

Upload memory is bounded by the per-node raw-byte pool described in
[uploads.md](uploads.md#the-raw-byte-pool). The master reserves capacity before
granting a browser window; accepted bytes stay charged through queues and
partial writes until processing or final discard. A worker reports the live
setting and completed receipts; a remote receiver first reserves its own
capacity. These are byte obligations, not RSS measurements: base64/JSON,
string copies and transport overhead occupy additional memory. Reducing the
setting preserves issued windows and stops new grants while the pool has no
room. Keep reading worker, peer and control sockets when it is full: their
facts are what release the capacity.

## Browser Socket Liveness

An open TCP connection says nothing about the person behind it. The master pings
every browser socket that goes quiet, doubts one that stays quiet, and closes one
that stays quiet too long (HIL-1566). The deadlines are decided by
`Socket/WebSocket/Liveness/WebSocketLiveness`, which reads no clock and no
environment; `WebSocketClient::onTick()` hands it the socket's monotonic
milliseconds and carries out what it answers. The state is private to the socket
and dies with it: no worker and no peer reads it, and the master writes no runtime
row for it. `onTick()` runs from the completed handshake to the decision to close
and does no blocking work: arithmetic, one frame appended to the write buffer, a
queued signal and an array read of a copy the process already holds.

**Four spans, read at every handshake.** `WebSocketLivenessConfig::fromEnv()` reads
them when the upgrade request arrives, before anything of that handshake is minted,
and the socket keeps them for its life. All four are milliseconds, counted from the
last frame the browser sent, and they are independent deadlines from that moment,
not a sum:

| Environment value | Default | Meaning |
|---|---|---|
| `WEBSOCKET_PING_IDLE_MS` | 40000 | silence before the first ping |
| `WEBSOCKET_PING_INTERVAL_MS` | 50000 | spacing of the pings while the silence lasts |
| `WEBSOCKET_PROBABLY_OFFLINE_MS` | 120000 | silence from which the socket is doubted |
| `WEBSOCKET_CLOSE_TIMEOUT_MS` | 600000 | silence at which the master closes it |

The idle and interval spans must be positive and the order `WEBSOCKET_PING_IDLE_MS`
< `WEBSOCKET_PROBABLY_OFFLINE_MS` < `WEBSOCKET_CLOSE_TIMEOUT_MS` must hold. A
combination that breaks either throws `EnvInvalidValueException` at the handshake,
which refuses that connection: a check that switched itself off, or refused again
on every tick, would hide the same mistake. The test stands shorten the spans; see
[testing.md](../testing.md) under scenario 46.

**What counts as the browser speaking.** A whole frame of any opcode, taken by the
parser and then handled (`processReadBuffer()`). A malformed frame throws and a
partial one still waits in the read buffer, so neither counts. A pong is the whole
proof of life, and a browser answers an RFC ping on its own, with no page code
involved. Nothing this side writes counts, a ping it sent included.

**What the master does with the silence**, in the order `onTick()` checks it:

- **Close.** At `WEBSOCKET_CLOSE_TIMEOUT_MS` the socket is marked with
  `markShouldClose()` and the same pass of `AbstractServer::onTick()` takes it
  through `dropClient()` (see above) without waiting for its outbound bytes, as
  `closeOnceWritten()` would: a browser silent this long does not read them. From
  that moment the socket is pinged no more, changes no flag and is checked against
  no row.
- **Doubt.** At `WEBSOCKET_PROBABLY_OFFLINE_MS` the socket settles as probably
  offline, and the tick after a whole frame settles it back. Either way the
  socket queues a `connection_liveness` signal naming its accept key and the new
  state, routed to the owner of the connections collection, which writes the
  `probablyOffline` flag of that connection's row
  ([../signals/routing.md](../signals/routing.md),
  [../runtime/rt-state.md](../runtime/rt-state.md)).
- **Check of the row.** Every `WEBSOCKET_PING_INTERVAL_MS` from the handshake, and
  not moved by frames, the socket reads its connection's row from the copy of the
  connections registry every process holds. When the flag there disagrees with the
  socket's, it queues the signal again. The check writes nothing and costs no
  database: it repairs a transition the owner missed because it was stopped,
  restarted or cut off from this node. A row or registry that is not there is
  passed over until the next check. A transition on the same tick has just told
  the owner the value a disagreeing row would repeat, so that tick does not check.
- **Ping.** After `WEBSOCKET_PING_IDLE_MS` of silence an RFC ping with an empty
  payload, then one every `WEBSOCKET_PING_INTERVAL_MS` from the send, so a late
  tick sends one ping and not the ones it missed. A frame from the browser moves
  the next ping a whole idle span away. The ping is written straight to the
  transport buffer past the delivery queue, as the pong is, so it leaves the
  queue, its window and any held subscription as they were.

**Outbound cap: 8 MiB.** A socket whose browser stays alive but does not read is
not caught by silence, because it keeps answering pings. `WebSocketClient` therefore
caps what is queued to it at `MAX_WRITE_BUFFER_BYTES` (8 MiB), counted as the
transport buffer plus `DeliveryQueue::getPendingBytes()`: what `drain()` would hand
the socket for everything held now, a held subscription included, with batch
wrappers, separators and frame headers. The queue keeps that count as it changes,
so asking walks nothing. `AbstractClient::enforceOutboundCap()` does the drop:
exactly at the cap the client stays; past it the master logs a warning, empties the
write buffer and marks the socket to close, and the server's ordinary `dropClient()`
releases what the queue held. It is called wherever the bytes grow: in `write()`
before the drain, because with the queue held or not yet due the transport buffer
may be empty and `AbstractClient::write()` would return before its own check; after
a frame is queued; and after a ping, a pong or a binary frame is appended. A socket
already marked to close takes nothing more. An uncapped client (`0`, the default of
an HTTP response) is never dropped here; the cluster peer link and the browser
socket set a cap.

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
