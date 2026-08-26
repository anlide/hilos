# Anti-pattern: Heavy Work in the Master Process

Read this before adding database access, filesystem access, network calls, or any
other potentially slow or blocking work to the master daemon process: the
connection-accept loop, the WebSocket 101 / handshake-welcome path, signal
routing, or any per-connection code that runs after `$daemon->run()`.

## Core Rule

The master daemon process runs the single event loop that accepts every
connection and routes every signal. One blocking call there freezes all clients
at once, so the master must stay light and responsive. Keep durable or slow work
— DB queries, file I/O, network calls, CPU-heavy work — out of the master's
runtime path; do it in a worker (handshake, action, and signal handlers already
run there) or in a monopolistic agent.

This is also an interaction rule: do not propose heavy work in the master as an
option — not even a "simple" one the user could accept by mistake. Treat it as
off the table, and if it ever comes up, say why it is wrong before anything else.
Only an explicit, weighed reason from the user puts it back on the table. The bar
is higher in demos: people copy demo code into real projects, so a heavy master
in a demo teaches the wrong pattern.

## Where This Applies

- Connection-accept and other event-loop callbacks in the master.
- The WebSocket 101 upgrade and the handshake-welcome frame.
- Signal routing in the master before it hands a signal to a worker.
- Any code reachable per-connection or per-signal after `$daemon->run()`.

## Anti-Patterns

```php
// ❌ DB lookup in the master while writing the 101 / handshake welcome —
//    freezes the accept loop for every other connecting client:
$user = Hilos::$db->users->findBySession($token);

// ❌ File read on each connection in a master callback:
$config = json_decode((string)file_get_contents($path), true);

// ❌ Synchronous network call on the connection path:
$response = file_get_contents('https://example.com/verify');
```

Resolve identity that needs the DB in the worker handshake handler
(`onSignalHandshake`), where `Hilos::$db` already runs. If the master must put a
value on the 101 response (for example an httpOnly session cookie), mint a value
that needs no I/O — a random token — and let the worker persist and verify it.

## Preferred Shape

- The worker does the DB and heavy work: `onSignalHandshake`, action handlers,
  and signal handlers run in the worker, off the master's loop.
- A monopolistic agent isolates long-running work with its own timing.
- The master only mints in-memory values and moves bytes.
- **The door out is named: `MasterSignalSender`** (HIL-618), implemented by
  `DaemonManager`. `sendToAgent()` reaches one named agent, `sendToWorkers()`
  every worker of this node; both put a frame in a write buffer and return. Master
  code that discovers work says what happened through one of them and lets the
  receiver do it. This rule and that facade are not in tension — the facade exists
  so the work can leave. The facade does not exempt anything from this rule: sending
  to a stopped agent starts it, and the project's agent-daemon factory then runs
  right here, on the master loop.
- **A frame going to several addressees is packed once per broadcast**, not once per
  link: the string is the same for all of them, and the second `json_encode` is work the
  master pays for nothing. `DaemonManager::writeFrameToWorkers()` is where this is done for
  the worker links, and `encodeSignalFrame()` / `sendToAllClients()` for the WebSocket ones.
- **The master now also asks** (HIL-619): `onContainedFailure()` tells the project
  about a failure a master guard swallowed, and it is master-loop code like the hooks
  above — a line or a counter, and `MasterSignalSender` for anything more. It is called
  once per contained failure, so in a storm it is called in a storm. See
  [daemon-lifecycle.md](../architecture/daemon-lifecycle.md#answering-a-contained-failure-hil-619).
- Routing is still the ordinary way to move a signal: `SignalRouter::queueSignal()`
  routes by sender, and the facade is for the case where the addressee is known by
  name and there is no route to declare. See
  [daemon-lifecycle.md](../architecture/daemon-lifecycle.md#handing-work-out-of-the-master-hil-618).

## Exceptions

One-time bootstrap, before `$daemon->run()`, may read configuration and build
artifacts. Reading `dist/build-timestamp.txt` once at startup to set
`HILOS_BUILD_TIMESTAMP` is fine: it runs once, not on the event loop. The rule
targets the runtime path, not bootstrap.

## Validation

- For what blocks the loop and how to move work off it, see
  [event-loop.md](../architecture/event-loop.md) and
  [blocking-in-ontick.md](blocking-in-ontick.md).
- Obeying this rule introduces no new DB/RT/signal/route surface, so no contract
  gate applies. If your fix relocates work into a worker handler in a way that
  changes a DTO or route, follow that surface's gate.
