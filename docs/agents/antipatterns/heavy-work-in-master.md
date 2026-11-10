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
- The handlers of `httpRoutes()`: the HTTP server lives in the master, so a
  route handler is master-loop code for every request it answers.

## Anti-Patterns

```php
// ❌ DB lookup in the master while writing the 101 / handshake welcome —
//    freezes the accept loop for every other connecting client:
$session = Hilos::$db->sessions->findByToken($token);

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
- **Work proportional to something that grows is spread over passes.** The
  protected-mode freeze used to stop the node's whole roster inside one call of
  the loop, and a lift brought it back the same way; at nine agents that pass took
  seconds, and every client of the node stood behind it. The roster is now queued
  and stepped one agent per pass (`WorkerServer::advanceProtectedModeRoster()`),
  and whatever the walk owes someone is said when it ends (HIL-1012).
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
  the worker links, `clientDestinationDelivery()` for the WebSocket ones, and
  `PeerServer::fanOutFrame()` for the links to other nodes — the single loop every peer
  broadcast goes through, each of them supplying only its own question of whom to reach.
- **Signal delivery retains its unfinished portion.** `DaemonManager::dispatchSignals()`
  advances at most 64 steps of its original FIFO and physical continuations. Finite stop
  and browser prefixes get validation slots, and blocked-wait discovery keeps its share;
  an unused slot goes to delivery. The ordinary guarded master phase repeats portions
  within a 2ms monotonic budget, checked between portions, before returning to the other
  owners. The master waits on their actual readiness and deadlines; see the
  [event loop](../architecture/event-loop.md#timing). Native callbacks cue this
  phase without delivering ahead of ownership and reader-interest announcements.
  `WebSocketServer::clientTarget()` resolves an address through its accept-key index and
  captures the exact socket slot and key generation. It never scans the browser roster
  for each addressed recipient. Physical fan-outs still spend one step per original
  candidate. Packing remains one atomic operation; the browser's 8 MiB outbound cap does
  not bound worker or peer source payloads.
- **Source hand-overs and replica answers retain their row construction.** The live
  master uses its existing effect FIFO; incoming peer RT writes, DB facts,
  agent signals, and page access re-decision announcements enter that
  same FIFO, so a query sees earlier applied writes and its completed frames precede
  later source writes. Claim reports, reader-interest announcements and queries use
  that FIFO too; inbound reader-interest reports cannot change filtering ahead of an
  older retained answer. A replaced peer cannot inherit its old report.
  Row readers capture exact incarnations without copying the row
  map. A replacement, remove/recreate, whole clear, rollback or remount cannot admit a
  newer incarnation to an old read. Origin admission is separate from insertion order:
  transferring an old row position to another origin is a new admission. Owned snapshots
  publish only after the whole source is built; replica answers keep own-first and then
  original origin/row order. The recipient is the original peer transport slot.
  Immutable report paths capture claim values in O(1), copying O(log agents) nodes on
  a report change. Plans visit one report transition, collection or scope key per step;
  source discovery and scope indexes also advance by individual names or keys. One
  cross-source row admission cutoff precedes this preparation, so a source discovered
  later cannot admit a row created during the plan fold. Completed plans are reused;
  abandoned partial plans cannot poison the same original basis.
  A row or key visit still costs its input's fields and bytes. Changed ownership
  signatures, reader-union and linked-node list construction, complete DTO packing and
  retirement of retained trees remain input-sized costs. Count those inputs separately;
  an outer step counter does not bound their bytes or destruction.
- **Legacy project callbacks are declared.** A router overriding `getDestinations()`
  selects `USE_DESTINATION_CALLBACK`; one overriding `localClientDestinations()` selects
  `USE_LOCAL_CLIENT_CALLBACK`. A manager overriding `updateSubscriptions()` selects
  `USE_SUBSCRIPTION_CALLBACK`. These protected constants default to false; the framework
  uses its portioned paths. An opted-in callback runs once with its existing output and
  must keep its own work bounded. Do not infer an override by comparing callables.
- **The master now also asks** (HIL-619): `onContainedFailure()` tells the project
  about a failure a master guard swallowed, and it is master-loop code like the hooks
  above — a line or a counter, and `MasterSignalSender` for anything more. It is called
  once per contained failure, so in a storm it is called in a storm. See
  [daemon-lifecycle.md](../architecture/daemon-lifecycle.md#answering-a-contained-failure-hil-619).
- **An HTTP address whose answer needs the database or the files is declared by
  an agent** (`AGENT_HTTP_ROUTES`, HIL-138): the master parks the connection,
  the agent decides in its worker and replies, and the master only writes the
  bytes back — see [agent-http-routes.md](../architecture/agent-http-routes.md).
  A handler in `httpRoutes()` that looks up a session or reads a file is the
  ❌ above with a URL in front of it.
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
