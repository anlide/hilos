# Architecture: The Daemon Section

Read this before putting something into a node's picture in the Daemon section,
changing the master's handoff, the collector or the mirror, showing an environment
value, restarting an agent, measuring the daemon's cores, load or memory, or
switching the section on in a project.

This is the target architecture of HIL-358, written ahead of its code. Each
unfinished clause names the leaf that will land it; that leaf clears its marker
in the same commit ([../rule-authoring.md](../rule-authoring.md), *A Rule Written
Ahead Of Its Code*). Signal names, DTOs, frame fields, agent classes and types,
the feature case and frame intervals belong to those leaves.

## Words

- **The master frame** is what the master hands to the agent of its own node.
- **The node agent** runs once per node.
- **The collector** runs once per cluster.
- **The page agent** serves the section's pages; the framework class is abstract
  and the project's subclass is empty.
- **The node picture**, **the cluster picture** and **the mirror** name the copies
  held by the node agent, the collector and the page agent's worker, respectively.
- **Node replica** means `SCOPE = NODE`; **leader singleton** means `CLUSTER +
  LEADER` without an index; **policy-placed** means `CLUSTER + POLICY`.
- **Indexed** means `INDEXED`; **idle** (the screen's “lazy” agent) means
  `INDEXED + IDLE_TIMEOUT`.
- **Monopolistic** is the worker kind, independent of placement, not another
  placement kind; the existing restrictions on indexed agents still apply.

For the declarations and valid combinations, read
[../agent-system/adding-agent.md](../agent-system/adding-agent.md) and
[../agent-system/monopolistic-agent.md](../agent-system/monopolistic-agent.md).

## Core Rule

Keep what a node's processes produce in the producing process's memory and hand
it on as a whole frame. The master hands its frame to its node agent. The node
agent hands its picture to the collector, and the collector hands whole node
slots to the page agent.

None of these pictures belongs in RT: it would duplicate an owner and replicate
a derived view. This is the rule in [logs.md](logs.md), *Rules This Feature Proved,
Wider Than Logs*. The environment page of a node is served by that node's own
agent through [node-addressed page subscriptions](../signals/routing.md); the
collector receives counts, never values.
The master says what it already knows; it does not search for data for the section.

## What The Node's Processes Know

The last column names how a fact enters the node picture. Most facts are
master-owned; agent schedules come directly from the agents that run them.
An unfinished delivery names the leaf that will add it.

| Fact | Where it lives today | Delivery to the node agent |
|---|---|---|
| Workers | `WorkerServer::$workers` in [WorkerServer](../../../framework/backend/Socket/Server/WorkerServer.php) | Sorted live worker roster with nullable PID and RSS |
| Agent instances and their workers | [AgentManagerDaemon](../../../framework/backend/Core/Agent/Daemon/AgentManagerDaemon.php) | Started agents joined to live workers, with declared scope and placement |
| Placement and “unplaced” | [PlacementRegistry](../../../framework/backend/Cluster/Placement/PlacementRegistry.php), held by the leader | Sorted `runsNowhere()` ids in the leader's frame; null on other nodes |
| Worker crashes in the last 24 hours | In-memory loss and replacement tracker in [WorkerServer](../../../framework/backend/Socket/Server/WorkerServer.php) | `workerRestarts24h` counts successful replacements after unexpected loss, not a daemon or agent restart |
| Cron rules and last firing | [CronRule](../../../framework/backend/Core/Daemon/Cron/CronRule.php) and its `lastFiredAt`: daemon rules belong to the master and run only on the leader; agent schedules belong to the agents and run wherever those agents live | Master cron part on change and once a minute; agent schedules from the agents themselves |
| HTTP server, including agent addresses | [HttpRouter](../../../framework/backend/API/Router/HttpRouter.php) counts every answer per registered route in hourly cells (`HttpRouteTraffic`) | `http` part: host, port, countingSince, routes and unrouted; on change and once a minute |
| Process measurements | [DaemonStatusSource](../../../framework/backend/Core/Daemon/DaemonStatusSource.php), implemented by `DaemonManager::daemonStatusSnapshot()` | Process samples (not in the code yet — HIL-1373) |
| Leader, quorum, term | [Leadership](../../../framework/backend/Cluster/Leadership.php) and [ClusterCoordinator](../../../framework/backend/Cluster/Consensus/ClusterCoordinator.php) | `standing.consensus`: role, term, recognised leader, and quorum view (live masters of the static set, set size, majority) |
| Node sessions and connections | The master's own handshaked WebSocket clients | `standing.sessions` / `standing.connections`; sessions are distinct session tokens among those clients |

The master does not read the on-disk `.env` for the section. The node agent reads
the environment and that file itself.
A worker may read the file; the master loop may not.

## The Master Frame

The master hands the whole frame to the agent of its own node through
`MasterSignalSender::sendToAgent()`. The node agent accepts that frame only from
its own master and for its own node ID. The existing precedent is
`AnalyticsJournalOutbox::flush()` and the master's dispatch of that batch to its
own journal agent — [analytics.md](analytics.md), *The Chain*. Routing remains
declarative; see [daemon-lifecycle.md](daemon-lifecycle.md), *Handing work out
of the master*.

`DaemonManager::publishClusterNodes()` already writes the node-local
`hilosClusterNodes` register. That does not make RT the master frame's carrier:

- The frame has one reader on the node; RT would give every worker a replica.
- Measurements change every interval; membership changes rarely.
- A picture produced by one process belongs in that process's memory. The
  master's special RT keys are a closed exception, not an extension point —
  [../runtime/rt-context.md](../runtime/rt-context.md), *The truth source is unique
  per cluster, not per process*.

Process measurements leave every interval (not in the code yet — HIL-1373). The
roster parts that grow with the number of agent instances — workers, instances
and placement — leave on change and once a minute to repair a lost frame; they
are never packed on every measurement interval. They are built in bounded
passes, and a changed source revision discards the unfinished build. The small
set of daemon cron rules leaves as a whole part on change and once a minute to
repair a lost frame. Agent schedules leave from their own workers, not through
the master. There may be thousands of agent instances; do not pack their
schedules on the master loop. Apply
[../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md),
*Work proportional to something that grows*.

Do not await a reply or retry a frame: the next whole frame repairs a lost one.
A restarted node agent gets a new full frame after it starts; until then its
`processes` field is null, distinct from a known `workers: []`. The future
screen says “no picture yet” for an absent node picture
(not in the code yet — HIL-1392).

While the node's freeze row holds, the master sends no frames: the freeze has
stopped the node agent. The precedent is the collector in the master in
[analytics.md](analytics.md), *The Freeze*. Report a delivery refusal once when
the outcome changes, not on each frame, following the third rule in
[logs.md](logs.md), *Rules This Feature Proved, Wider Than Logs*.

## The Schedule

Two producers fill one cron section of the node picture. The master sends its
whole set of daemon rules to its own node agent on change and once a minute.
Each agent holding its own `CronRule` reports its current set after its tick,
on change and once a minute while non-empty. A project without the Daemon
feature sends no such agent reports. An empty report withdraws a former set.

The node agent combines these reports into flat rows: `agentId` is null for a
daemon rule and names its agent otherwise. Each row carries the rule's name,
expression, last actual firing (`lastRunAt`), and the next matching local minute
as Unix seconds (`nextRunAt`). The node agent computes that next minute with
`CronRule::nextRunAfter()`, using the same field rules as the runner: the day of
month and weekday must both match. Null means no match in the bounded search or
an invalid expression. Daemon rules have no next run on a node that is not the
leader; `idleReason: not_leader` says why. Agent rules continue to have next
runs on that node.

Until the master's cron part arrives, the `cron` section is null rather than an
empty rule list. Rows are sorted with daemon rules first, then by agent id and
rule name. A new process roster removes rows of agents no longer on this node.
The node agent does not expire a report by elapsed time: after its own restart,
agent rows return on the agents' next changed or minute repair report, which can
take up to a minute. The master forces its part as soon as the node agent
starts.

## The HTTP Counters

The master counts each completed HTTP request on its registered method and path,
including a route template rather than a raw address. Unmatched addresses and
refusals before route selection share one `unrouted` line. An answer with status
500–599 is a server error. A request lasting more than 1000 milliseconds is slow;
`slowestMs` is the longest duration, or null when no request was counted.

Each route keeps 24 hourly cells: the current hour and the 23 before it. The
master counts an agent address when its parked request ends. If the browser leaves
first, the request and its waiting time still count, but no server error does.
Restarting the master clears the counters and begins a new `countingSince`.

The master sends the whole `http` part on change and once a minute, and forces it
after the node agent starts or a freeze lifts. Until it arrives, `http` is null;
a section full of zero counts is a known, different state. A daemon without an
HTTP server sends no part.

## The Circulation

Use the whole-copy chain in [logs.md](logs.md), *The Circulation*:

- The node agent sends its whole picture on its own tick, unasked.
- The collector is one cluster instance, placed by `POLICY`, not monopolistic.
  It holds one slot per node and replaces that slot with the whole incoming
  frame.
- At delivery time the collector reads membership from `HilosClusterNode`.
  It does not infer membership from silence; a node the register no longer sees
  keeps its last slot marked “silent”.
- The page agent declares interest with a lease, receives a snapshot followed
  by changed whole slots, and holds the mirror in worker memory.
- “No picture yet”, “empty” and “node silent” are three distinct states. None
  is folded into zero.
- While nobody watches the section, no frames travel above the node.

Keep the collector and page agent separate: the source of the cluster picture
and the surface that shows it are different owners. On a standalone installation
`standing.clustered` is false and carries no consensus on a standalone
installation, so the picture has no state word.

## The Node's State

`ClusterDaemonPicture::stateOf()` derives one of four words from a current
picture: a silent node is `silent`; a live slave is `data`; the picture's leader
is `leader`; every other live clustered master with standing is `standby`.
An unknown node, a live node without a report or master standing, and a
standalone node have no word yet. The page reads this verdict rather than
deriving its own.

`ClusterDaemonPicture::leader()` considers live masters with consensus. A
master claiming leadership wins only if its term is at least the term of every
other live master. If several claim leadership in the highest term, the lowest
node id wins. A newer candidate means there is no leader in the picture until
the election resolves.

## What Never Leaves A Node

A sensitive value never leaves the node's processes: neither for the collector,
the page, the browser, a log line nor CLI output. Only “set · N chars” or
“not set” may leave.
The environment catalog declares `sensitive`; do not guess it from the key's name.
No orphan `.env` key exposes its value: nothing declares that it may be shown.

The environment page of node X is served by node X's agent. It answers the
subscription with one whole `page_response`, directly to the asking socket.
The collector's picture contains counts and value-free labels. For a silent
node the section agent answers “node silent” with no values. A directory watch and periodic
rescan make the node agent re-answer open environment pages after `.env` or
`.env.example` changes.

The environment catalog opens a value to an admin view-mode viewer only with
`admin_view_visible => true`; no current key is open, and `sensitive` forbids
opening one. Hide every other value, as for settings.
The node agent asks the view-mode verdict when it answers. It sends a hidden
mark for a closed key's set value and omits that key's drift entirely; an orphan
is hidden for a viewer and length-only for an administrator. See
[admin-view-mode.md](admin-view-mode.md).

A fingerprint — type, source and a 16-hex SHA-256 hash of the catalog key and
its value in the catalog type — travels only from node to collector. Boolean
`0`/`false`/`no`/`off` have one canonical value. The collector replaces each hash
on receipt with a 16-hex HMAC label using a salt minted for its lifetime; it
does not retain the node hash. The mirror receives a whole snapshot after a
collector restart, so labels from two collector lifetimes do not mix. Equal
labels mean equal values within one picture. An unsalted short hash of a small
set of candidates (`prod`/`staging`, `true`/`false`, a short password) can be matched
by trying those candidates. The comparison must reveal what differs without
revealing the value; the short label in the cell still serves that comparison.
`ClusterDaemonPicture::environmentComparison()` compares the labels, types and
sources of answering nodes. It separates divergent keys, equal values from
different sources, and `per_node` keys, which differ by design. Each key has a
`known`, `unknown` or `undeclared` cell for every node; a silent node is unknown,
while a missing declaration on an answering node is a discrepancy.

The September 16 decision carried fingerprints in synchronized RT; the October 4
decomposition moved them to the node frame, preserving the boundary on values.

## Restarting One Agent

A restart stops that one instance and brings it back by the same placement rule
that put it there; the daemon does the work, and `agent:restart` asks and prints
the result (not in the code yet — HIL-1380). Keep that process boundary from
[../cli/command-execution.md](../cli/command-execution.md).
The command answers when the agent is up again, or refuses in words: no such
agent, node silent, or start refused by the freeze or leadership gate
(not in the code yet — HIL-1380).

`WorkerServer::stopAgent()` already stops one agent without stopping its worker
neighbors. Do not offer a worker restart: it takes down every agent on that worker.

| Kind | Where it comes back | Today's mechanism |
|---|---|---|
| Node replica | On the same node, started by that node's master (not in the code yet — HIL-1380) | `WorkerServer::onInitialWorkersReady()` starts replicas once when local workers are ready; an ordinary stop has no automatic restart. |
| Leader singleton | On the leader (not in the code yet — HIL-1380) | An address can start it; `DaemonManager::ensureSingletonsStarted()` also runs the bootstrap after worker death re-arms it. Stopping one agent does not re-arm it. |
| Policy-placed | Forget the stopped instance's placement; the leader places it by best-fit again, possibly on another node (not in the code yet — HIL-1380) | `ClusterPlacement::stopAgentOnNode()` forgets placement; `DaemonManager::ensurePolicyAgentsPlaced()` reconciles it. With cluster mode off the same declaration lands here. |
| Indexed | The same type:index, placed as an address to it would place it (not in the code yet — HIL-1380) | An address supplies the index; the unindexed policy reconciliation does not rebuild an indexed pool. |
| Idle (“lazy”) | Immediately, with the idle window counted from the new start (not in the code yet — HIL-1380) | Waiting for the next address would leave “restarting” open indefinitely. |
| Monopolistic | Apply its placement kind's rule and use a free monopolistic worker or raise one (not in the code yet — HIL-1380) | The worker stays in the pool when its agent stops; the pool does not shrink. This is a worker kind, subject to the existing restrictions in [monopolistic-agent.md](../agent-system/monopolistic-agent.md). |

The CLI speaks to its local daemon. A replica on another node is restarted by
that node's master; a cluster-scoped agent is restarted through the leader that
holds placement (not in the code yet — HIL-1380).

The node picture keeps “restarting” until the agent starts or the restart is
refused (not in the code yet — HIL-1380). Everyone watching the section sees
that mark (not in the code yet — HIL-1397). The confirmation dialog warns that
a `POLICY` agent may come back on a different node
(not in the code yet — HIL-1397). The restart button is disabled for an admin
view-mode viewer (not in the code yet — HIL-1397).

## Measuring The Daemon

- **Node cores:** the cgroup quota from `cpu.max`, quota divided by period;
  without a quota, use the CPUs allowed to the process (the answer `nproc`
  gives), read without starting an external process
  (not in the code yet — HIL-1373).
- **Daemon load:** sum the master's and every live worker's increases in
  `utime + stime` from `/proc/<pid>/stat`, converted from clock ticks to CPU
  seconds, and divide by elapsed seconds. The result is occupied cores
  (not in the code yet — HIL-1373). Show the master separately
  (not in the code yet — HIL-1373). Show the machine's `/proc/stat` load
  separately for context (not in the code yet — HIL-1373).
- **Memory:** resident memory of each process from that same stat row
  (not in the code yet — HIL-1373). The ceiling for the daemon's sum is cgroup
  `memory.max`, falling back to physical memory without that ceiling
  (not in the code yet — HIL-1373). Show PHP `memory_limit` per process as
  reference information (not in the code yet — HIL-1373).

Today [DaemonStatus](../../../framework/backend/Core/Daemon/Master/DaemonStatus.php)
reports the machine's CPU load — the host's inside a container.
[ProcessStat](../../../framework/backend/Core/Daemon/ProcessStat.php) holds the
parent PID and zombie flag parsed from `/proc/<pid>/stat` by
`OrphanReaper::statOf()`; it does not yet hold CPU or resident-memory samples.
`GET /status` and `daemon:status` already share `DaemonStatusSource`: two sources
would give different uptime anchors and CPU deltas for one daemon.
The master frame reads that same source (not in the code yet — HIL-1373).

Unknown is a dash, never zero: an unavailable `/proc` or cgroup measurement must
stay unavailable. This is the second rule in [logs.md](logs.md), *Rules This
Feature Proved, Wider Than Logs*; use a named fallback above only when available.

## What The Master May And May Not Do

Apply [../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)
to every frame-producing path. The master may read its own memory and kernel
pseudo-files under `/proc` and `/sys/fs/cgroup`: the kernel produces them, with
no ordinary disk file to wait for. `DaemonStatusSource` already restricts its
sample to in-memory counters plus `/proc/stat`. Pack a frame once and put it
on one local worker connection. HTTP request accounting is an increment in memory.

No database access, ordinary file read, network work beyond that local worker
connection, waiting for an answer, or packing instance-sized rosters on every
measurement interval. The `.env` on disk belongs to the node agent.

## Switching The Section On

The project declares the feature in `Hilos::FEATURES`. Startup requires the
section's pages and its three agents, refusing a partial activation. The section
is enabled in the demos that already enable `LOGS`: chat, tasks, polls,
binance-btc-tracker and online-testing. The page agent serves the section,
replacing the index agent in chat. The environment child page is the exception:
its thin project subclass inherits the framework's node-addressed
`SUBSCRIPTION_AGENT_TYPE` and declares none of its own. The six node child
addresses carry a required node ID, including on standalone installations; Env
mismatch is a cluster-wide address without one.

The framework has eight abstract Daemon pages, with thin concrete subclasses in
all five demos. All eight keys remain in
[hilosUnbuiltPages](../../../framework/frontend/core/src/routing/hilosUnbuiltPages.ts).
Keep WebSockets unbuilt after this section lands: it is outside this section's
scope. For the layer-by-layer activation recipe, read
[admin-feature-scaffold.md](admin-feature-scaffold.md), *daemon — the Daemon
section*.

## Anti-Patterns

```php
// Wrong sketch: a derived node picture turned into an RT row.
final class HilosDaemonNodeState extends RtState { /* picture */ }
```

That gives each worker a replica and the picture a second owner. Keep it in
the producing process and send the whole frame.

```php
// Wrong sketch: the master reads a disk file or queries the database for a frame.
$environment = file_get_contents('.env');
foreach (Hilos::$db->users as $user) { /* fill the frame */ }
```

Reading ordinary files or fetching rows on the master loop stalls every
connection. Let the node agent read its file; hand off only the master's own data.

```php
// Wrong sketch: a short unsalted value hash reaches the screen.
$label = substr(hash('sha256', $value), 0, 4);
```

Trying candidate values reveals small-domain secrets. The collector supplies a
label salted for its lifetime; the value hash stops at the collector.

```php
// Wrong sketch: call a stop a restart and leave the next address to start it.
$this->stopAgent($agentType, $agentIndex);
```

An idle agent may never receive another address. Complete the restart now and
close its mark on success or refusal.

```php
// Wrong sketch: the collector invents membership from frame silence.
if ($now - $lastFrameAt > $timeout) {
    unset($nodes[$nodeId]);
}
```

That is a second answer beside the master's membership register. Read membership
at delivery, keep the last slot, and mark the silent node.

## Validation

Run `composer run test:framework:unit`; `AgentDocGuardTest` checks this page's
links. Each implementation leaf brings its mechanism's tests and clears the
markers that its code makes true in that same commit.
