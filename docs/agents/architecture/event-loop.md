# Event Loop

Hilos uses **epoll** via PHP `ext-event` (libevent) for non-blocking I/O.

## Class: `EventLoop`

[`EventLoop`](../../../framework/backend/Core/EventLoop/EventLoop.php) owns one
`EventBase`, native watches and a FIFO of process-local owner work. The master
keeps it in `DaemonManager`; native TLS adapters use that same base.

## How it works

| API | Contract |
|---|---|
| `createReadyWork(callable $callback): ReadyWork` | One owner's cancelable, coalesced portion |
| `createTimer(callable $callback): EventLoopTimer` | Initially unarmed, one-shot timer |
| `registerRead($socket, callable $callback): void` | Replace only READ; deferred callback receives `($socket, Event::READ)` |
| `registerWrite($socket, callable $callback): void` | Replace only WRITE; deferred callback receives `($socket, Event::WRITE)` |
| `unregisterRead($socket): void` / `unregisterWrite($socket): void` | Remove that direction and its pending callback |
| `unregister($socket): void` | Remove both directions before closing the descriptor |
| `registerSignal(int $signal, callable $callback): void` | Claim a persistent POSIX watch; deferred callback receives the signal number |
| `unregisterSignal(int $signal): void` | Release that watch and its queued delivery |
| `runOnce(): void` | One native batch and one FIFO portion; may wait only when idle |
| `tick(): void` | The same dispatch, always nonblocking |
| `hasReadyWork(): bool` | Non-consuming peek at queued portions or a possible native remainder |
| `registeredCount(): int` | Distinct watched sockets; excludes timers and signals |
| `getEventBase(): EventBase` | Borrow the existing base for a native adapter; do not drive it |
| `cleanup(): void` / `close(): void` | Release all loop-owned handles, leaving sockets and the reusable base open |

Native callbacks only record facts or call `markNativeReady(ReadyWork $work)`.
They do not parse input, dispatch domain signals or run lifecycle hooks. An
adapter calls `markNativeReady()` once per native notification, even when the
owner is already queued. Owner callbacks run after the native poll, inside the
caller's guard. Recursive dispatch is ignored.

READ and WRITE watches are one-shot. A direction stays disabled until its
deferred portion finishes; replacing or unregistering it invalidates its old
portion. A level-triggered readable socket therefore cannot keep native polling
busy indefinitely before PHP owner work runs. Unregister WRITE when no bytes
are runnable; an `EAGAIN` waits for the watch, without a self-queued retry.

### Ready work, timers and fairness

[`ReadyWork`](../../../framework/backend/Core/EventLoop/ReadyWork.php) exposes
`markReady(): void`, `cancel(): void`, `close(): void` and `isClosed(): bool`.
Repeated marks coalesce. `cancel()` removes a pending ticket immediately and
leaves the handle reusable; `close()` also releases the owner permanently and
ignores late marks. Dispatch consumes the ticket before calling the owner, so
a continuation joins the FIFO tail. Mark that continuation only for work that
can advance now.

[`EventLoopTimer`](../../../framework/backend/Core/EventLoop/EventLoopTimer.php)
exposes `armAt(?float $monotonicAt): void`, `getDeadline(): ?float` and
`close(): void`. Deadlines are absolute monotonic seconds from `hrtime(true)`,
converted by `TimeConstants::NS_PER_MILLISECOND` and
`TimeConstants::MS_PER_SECOND`. `null` cancels both native and queued work;
rearming the same deadline preserves its basis. Cancel, replacement and close
invalidate the old generation, including an already activated callback. Native
firing clears the deadline before the deferred owner runs. The timer is one-shot:
another firing requires an explicit arm. The loop retains handles for cleanup,
without scanning them to discover deadlines.

One dispatch admits at most 64 native notifications and then at most 64 owner
portions from the FIFO prefix present after polling. A continuation enqueued
while dispatching waits for the next dispatch. At the native budget the loop
records a possible remainder, which forces a nonblocking continuation poll.
These limits bound notifications and portions, not all work or bytes in a pass:
DTO packing, snapshot construction, a callback's own work and retained-tree
destruction can still cost their whole input. Keep each owner's portion short.

Runnable parser continuations, released deliveries and due verdicts keep the
master active. Partial input, held deliveries, pending answers, write
backpressure and future deadlines wait for their actual I/O, source fact or
timer. Occupancy alone is not readiness. The master composes its owners'
non-consuming predicates before waiting: an empty native FIFO is insufficient
proof that it may sleep. See the [main loop](daemon-lifecycle.md#main-loop-each-iteration).

### Detach and failure

**One way out for a client.** A socket must come off the watch before it is closed,
or libevent keeps a dangling reference to a closed descriptor — and to the dead
client behind its read callback. That order is not repeated at each exit; every one
of them goes through the server's door:
```php
$server->dropClient($client); // off the watch, closed, forgotten — in that order
```
`AbstractServer::dropClient()` announces the departure through
`ClientSocketDetacher`, the seam `DaemonManager` implements and hands to every server
in `registerServer()`. The client detaches its own transport, timer and ready
handles first. A failed detach does not permit closing the descriptor underneath
its native watch. The master also unregisters the exact listening socket before
closing an entrance. `AbstractServer::closeListener(): void` closes only the
entrance; `PeerServer` additionally retires pending dials. Client sockets still
leave through `dropClient()`; do not close one outside that door.

`ReadyWork::run(): void` closes a throwing owner's handle, including any ticket
it queued for itself, and rethrows the original failure. Other owners' tickets
remain intact. This does not detach every resource that owner holds: its guard
must retire its exact watches, timers and bindings. Manager duty phases use
their existing detach on failure, so a failed generation cannot run repeatedly
during drain. READ retains its originating handler's logging and containment
contract; `EventLoopException` is propagated.

`EventLoopPollException extends EventLoopException` specifically means native
polling returned false or threw. An owner callback failure is outside that poll
catch. The master reports the failure before requesting exit; a failed base,
or inability to arm its shutdown wait, ends through terminal retirement without
another poll or a sleep/polling fallback. Responsive graceful drain requires a
healthy base. See [graceful shutdown](daemon-lifecycle.md#graceful-shutdown).

## What runs in the event loop

- Server accept portions, client I/O and buffered parser continuations.
- A ready client's guarded `read()` → `onTick()` → `write()` → close check.
- Protocol and delivery deadlines, including browser liveness.
- POSIX cues and owner-specific timers; the manager applies their lifecycle
  effects at its existing guarded phase.

`AbstractServer::onTick()` skips clients bound to the master loop. Its full
client walk remains only for unbound consumers; worker and peer server duties
keep their own phases. Bound clients run when their handle is ready, not on
every master pass. Their connection guard logs and drops a failed client;
entropy refusal requests a node stop. There is no blanket catch that makes a
failed native base healthy.

## Read Budget And Upload Memory

`SOCKET_READ_BUFFER_SIZE` (default 65,536 bytes in `EnvCatalogStub`) bounds
**new transport input in one `AbstractClient::read()` call**. A call first
resumes actionable buffered input, or gathers transport reads up to that budget
or until would-block, EOF or error. Parsing yields after its message allowance
and queues a continuation only for actionable remainder. Accepted input is
handled before its deferred EOF/error; incomplete or stopped parsing does not
delay a close. These are separate byte and message limits, not a bound on the
whole signal queue or all packaging work.

A watched client continues consuming ready input after `stopReading()`,
discarding each bounded portion without parsing it; later `read()` calls
continue until would-block, EOF or a read failure.

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

## Native TLS buffers and lifetime

[`TlsSocketTransport`](../../../framework/backend/Socket/Transport/TlsSocketTransport.php)
uses `EventBufferEvent::sslSocket()` on the owner's existing base. Native
read, write and handshake callbacks record status and cue the guarded client
portion; `advanceHandshake()` reads that status without pumping or polling TLS.
There is no permanent raw WRITE watch or polling handshake loop.

The native input watermark uses the configured read-buffer size. The read
callback disables READ until the bounded client portion consumes input and
reenables it; actionable decrypted remainder queues another portion. Accepted
decrypted bytes precede a terminal error or EOF.

Output admits one prefix of at most 16,384 bytes into the native buffer. That
prefix remains unchanged in the client's application buffer, with its debt
still charged, until native output drains. The write callback records drain
and disables WRITE; a guarded `write()` then acknowledges the prefix exactly
once. Appending a tail does not acknowledge or replace it. Native enqueue
returns zero bytes acknowledged. `closeOnceWritten()` waits for actual drain;
final discard releases undelivered debt through the ordinary close path.
The prefix limit bounds the extra native copy, not the full application queue.

The required patched `sslGetPeerCertificate(): ?string` returns the leaf PEM;
it establishes no trust. The context's CA verification, required client
certificate on mutual TLS ports and expected CN checks still apply after a
successful handshake. A public TLS port makes no authenticated peer claim.

`detachEventLoop()` is nonterminal: it invalidates the generation, disables
native I/O, replaces captures with inert callbacks and closes queued
notifications, retaining the TLS session on its original base. A live session
cannot move to another base. Terminal `close()` drops the transport's native
reference before closing its PHP-owned socket. Retained native aliases follow
the patched extension's ownership rules; do not force-free a shared base or
walk all aliases to dispose them. Loop cleanup releases its own handles and
remains reusable. The patch repairs specified buffer-event lifetimes; it is
not a guarantee that every native allocation is leak-free. Installation,
rebuild provenance and the retained-owner checks are in the
[native build guide](../../../framework/docker/native/event/README.md).

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
The socket arms one timer for the nearest liveness or delivery deadline and
cues its ready portion on a frame or queue change. Silence needs no roster walk
and does not depend on unrelated I/O waking the master.

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
on every tick, would hide the same mistake.

**Tests bring the moment to now; the stands keep the defaults.** No test stand sets
these spans, and no test waits one out: it runs the test-only command
`test:connection:liveness <sessionToken> <probably-offline|close|check>`
([../cli/commands.md](../cli/commands.md#time-based-features-no-universal-clock)).
The session token names the browser, and the master takes the moment on every socket
of that browser it holds past the handshake and not marked to close. `probably-offline`
and `close` put the last frame exactly the probably-offline span or the close timeout
back, and the next ping moves with it; a silence already longer is not made younger.
`check` makes the check of the row due now and leaves the silence alone. The socket
then runs its own `WebSocketClient::onTick()` at once, so the reply — how many sockets
took the moment — means the master has already told the owner, pinged, marked the
socket to close or checked the row; no such socket on the node is an error. Only the
node asked answers: a socket of the same browser on another master is brought to now on
that master. The production values are held by the unit test `WebSocketLivenessTest`;
[testing.md](../testing.md), under scenario 46, has the cluster scenario and the
single-node spec that use the command.

**What counts as the browser speaking.** A whole frame of any opcode, taken by the
parser and then handled (`processReadBuffer()`). A malformed frame throws and a
partial one still waits in the read buffer, so neither counts. A pong is the whole
proof of life, and a browser answers an RFC ping on its own, with no page code
involved. Nothing this side writes counts, a ping it sent included.

**What the master does with the silence**, in the order `onTick()` checks it:

- **Close.** At `WEBSOCKET_CLOSE_TIMEOUT_MS` the socket is marked with
  `markShouldClose()` and the same guarded client portion takes it
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

The master has no fixed tick or global maximum idle sleep. After reconciling
its current owners, it makes one guarded poll: `tick()` when a finite portion
or durable due fact is runnable, otherwise `runOnce()` waits on the same base
for I/O, POSIX or an owner's one-shot timer. Both use `LOOP_ONCE`; the active
path adds `LOOP_NONBLOCK`. There is no global periodic polling backstop. Process waits
use monotonic deadlines; saved epoch fields and calendar cron retain the
[UTC and project-zone contracts](time-zones.md). A project role hook schedules
its own next wake through
[`armRoleTickAt()`](daemon-lifecycle.md#scheduling-a-role-hook).

The container watchdog waits in libevent for `SIGCHLD`, `SIGTERM`, `SIGINT`,
`SIGHUP`, or a one-shot timer. Its persistent signal watches are installed
before the first daemon check and remain active between waits; callbacks only
mark signals for the watchdog to apply on its ordinary pass. Each wait is
capped at 60 seconds and shortened to the nearest monotonic
`DAEMON_MIN_RESTART_INTERVAL` deadline, which permits a restart after failure
or resets the early-failure series after a sufficiently long run. When a daemon
is present, the pass takes one status snapshot through `Process::observe()`;
`Process` preserves the exit code or terminating signal before closing an
exited child, and a shutdown requested on that pass uses the same snapshot to
send SIGTERM. After an exited daemon has been observed and closed, `OrphanReaper`
collects leftover children before a new start; it does not collect a live
supervised daemon.

A worker runs its ordinary work pass, then waits on the daemon socket when no
short work is due. Its maximum wait is the earliest of the live agents'
validated `MAX_IDLE_SLEEP_SECONDS` allowances, prepared cron/report deadlines,
and any declared instance idle-stop deadline. With no agents, or for a direct
`AgentInterface` implementation without an allowance API, the fallback is
100 ms. The allowance is measured from the pass start, so work in that pass
already spends it; see the canonical
[onTick rule](../agent-system/ontick-rule.md#idle-wait-permission). This bounds
idle waiting, not hook duration or a promised tick frequency.

A handled daemon message, pending daemon write, or in-flight
`TickDrivenIoRegistry` operation keeps the active 10 ms path. The worker-level
`onTick()` and existing maintenance guards cap the wait at 100 ms. These guards
include worker frames and upload receipts, cron-fire waits and acknowledgements,
deferred page-router work, held facet counts, analytics data, failure-log
windows, and queued `SignalRouter` output. Reading those guards does not drain
their queues. An already-due healthy cron deadline skips sleep, even when a
short-work guard is also set. A failed agent unit gets a short retry without
reusing its stale cron deadline, so an overdue schedule does not spin.

The wait wakes for daemon socket readability, including EOF, and for worker
`SIGTERM`, `SIGINT`, or `SIGHUP`. One receiver on the worker's `EventBase`
handles those signals; callbacks only record the signal, and the manager
applies lifecycle work outside the callback. The one-second parent/`ppid`
fallback is checked inside the wait without running agent or project ticks,
and each service wait remains bounded by the final deadline. These are service
wakeups, not an `onTick()` cadence. Worker and watchdog mechanisms are separate
from the master's owner-driven wait above.

That wait is libevent's, like the master's, and never `socket_select()`: a select
cut short by a signal raises a warning of its own, PHP calls the error handler for
it even under `@`, and an asynchronous signal handler then runs inside that call,
where its own warning bypasses the handler.

A tick-driven operation is one that moves only when its owner ticks it — an
`AsyncHttpClient`, `AsyncCommandClient`, `SmtpMailTransport` or child `Process`.
Each registers itself with `TickDrivenIoRegistry` when built and answers
`isInFlight()`; nothing wakes the worker's wait when its socket or pipe becomes
ready, so while one is in flight the worker keeps the short tick.
