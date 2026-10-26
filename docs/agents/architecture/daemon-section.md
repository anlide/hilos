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
| Process measurements | `DaemonStatus`, sampled by the master every five seconds; `DaemonStatusSource` serves its last sample | `measurements` part: on change of the rounded sample, once a minute, forced when the node agent starts |
| Leader, quorum, term | [Leadership](../../../framework/backend/Cluster/Leadership.php) and [ClusterCoordinator](../../../framework/backend/Cluster/Consensus/ClusterCoordinator.php) | `standing.consensus`: role, term, recognised leader, and quorum view (live masters of the static set, set size, majority) |
| Node sessions and connections | The master's own handshaked WebSocket clients | `standing.sessions` / `standing.connections`; sessions are distinct session tokens among those clients |
| Registered command listener port | The master's registered `CommandServer` | Required-nullable `standing.commandPort`, read from `CommandServer::getPort()` (0..65535 or null) and carried through the shared `DaemonMasterStandingSignalData` standing serializer into the whole node picture and collector portion |

The master does not read the on-disk `.env` for the section. The node agent reads
the environment and that file itself.
A worker may read the file; the master loop may not.

The master reads `commandPort` from its registered command listener; it does no
I/O or environment scan and adds no environment key for this field. Browser
pages carry it in the `daemon.entryPoints` projection; it does not change the
HTTP picture or the addressed Node Overview wire data.

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

The master sends process measurements as a separate fixed-size part when the rounded
sample changes, once a minute, and when the node agent starts. The
roster parts that grow with the number of agent instances — workers, instances
and placement — leave on change and once a minute to repair a lost frame; they
are never packed with measurements. They are built in bounded
passes, and a changed source revision discards the unfinished build. The small
set of daemon cron rules leaves as a whole part on change and once a minute to
repair a lost frame. Agent schedules leave from their own workers, not through
the master. There may be thousands of agent instances; do not pack their
schedules on the master loop. Apply
[../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md),
*Work proportional to something that grows*.

Do not await a reply or retry a frame: the next whole frame repairs a lost one.
A restarted node agent gets a new full frame after it starts; until then its
`processes` field is null, distinct from a known `workers: []`. The Workers and
Agents pages each say the node has not reported their rows yet while `processes`
is null; an empty reported roster says there are none.

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
expression, the last actual firing (`lastRunAt`) as a nullable epoch-millisecond
moment in report and picture rows, and the next matching local minute
(`nextRunAt`) as a nullable epoch-millisecond moment in the picture row. The
project time zone (`HILOS_PROJECT_TIME_ZONE`) still determines that minute,
using the same field rules as the runner: the day of month and weekday must
both match. `CronRule::nextRunAfter()` remains an internal seconds API; the node
agent converts its non-null result to milliseconds once when building a picture
row. See
[Daemon Cron and Node Heading Moments](time-zones.md#daemon-cron-and-node-heading-moments).
Null means no match in the bounded search or an invalid expression. Daemon rules
have no next run on a node that is not the leader; `idleReason: not_leader` says
why. Agent rules continue to have next runs on that node.

Until the master's cron part arrives, the `cron` section is null rather than an
empty rule list. Rows are sorted with daemon rules first, then by agent id and
rule name. A new process roster removes rows of agents no longer on this node.
The Cron page carries `cronReported`: while the section is null it says the node
has not reported its schedule, or is silent and never reported it; only a
reported empty list says there are no rules. Its non-leader notice comes from
daemon rows with `idleReason`, and only a standby node gets the second sentence
about starting here if leadership moves to this node. The view keeps the picture's
original order, with no search or sort controls; adding them is the shared D-219
debt outside the Angular port.
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

The HTTP page reads its summary from the full node slot in the page worker's
mirror, summing every registered route and `unrouted`, independently of the
visible table window. Its `http` data carries `host`, `port`, `countingHours`,
`requests`, `serverErrors` and `slow`. A missing node, slot or HTTP part gives
null, drawn as a dash in all four tiles; reported zero counts remain zero.
`countingHours` is the number of full hours from `countingSince` to the picture's
`sampledAt`, clamped to 0–24. The period belongs to that report, not the browser's
clock: “Since start, <1 h”, “Since start, N h”, or “Last 24 h”.
The address says “Listening” for a current report and “Last report” for a silent
node with a retained summary. The latter keeps its values and period and says
when the node fell silent; silence without a summary says no HTTP metrics have
been reported. Neither waiting nor silence invents zero counts.

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

Status adds a permanent internal consumer: it renews its own collector lease
independently of admin viewers. With that consumer present, “nobody watches”
does not mean there is no interest or that the collector may stop its delivery.
Use the existing whole-node picture chain, not the placement table or page
mirror as a source; see [status-page.md](status-page.md#facts-and-ownership).
(not in the code yet — HIL-1688)

Keep the collector and page agent separate: the source of the cluster picture
and the surface that shows it are different owners. On a standalone installation
`standing.clustered` is false and carries no consensus, so the picture has no
state word.

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

## The Node Line

Each node is an entity page at `/hilos/daemon/{nodeId}`. Its catalog entry opts
into the shared `HilosPageHead`, drawn by `HilosDaemonNodeShell` in Vue, React,
and Angular. The owner is the node, not the Daemon root section. One head and
one row serve Overview, Workers, Agents, Cron, HTTP, and Environment; the row's
breadcrumb ends at `nodeId`, not at the current child tab. WebSockets remains an
unbuilt catalog key, and the `.env` orphans page is not built. The root's Nodes
diagram links to each addressed Node Overview; the selected node remains its
own entity and head owner.

For mirror-backed node pages, `DaemonNodeHeading` carries `clustered`, `state`,
`silentSince`, and `role`. `state` remains the picture's verdict;
`silentSince` is present only for a silent node. `role` is the role in the last
report and stays null until a report exists. On standalone installations the
head shows “Single installation” without state or role. The environment page
builds its own node line from installation mode, `nodeSilent`, and the existing
RT declared node identity; it has no section-mirror viewer lease. Environment
value ownership, masking, and fallback remain as described below.

`AbstractHilosDaemonNodePage` uses the existing per-page, per-node publisher and
fingerprint. With no page-wide delivery debt, changed data goes through
`BrowserContext::publishPageData()` as a data-only `page_response` carrying only
changed `payload.data`. It does not resend browser tables or replace their held
windows. The ordinary subscription still answers in one complete first-render
`page_response`; when a page is owed whole, the existing complete-answer
recovery takes priority. The Overview page remains with the section agent and
its mirror, rather than a node-param owner. Its typed
`HilosDaemonNodeOverviewData` selects the addressed `nodeId` and waits for a
full snapshot. One answer carries page identity and head, the node line, and
Overview data. The complete addressed projection preserves unknown report
sections as null and known zeroes as zero, retains the last report for an
offline node, and derives uptime from that report's sample time. It carries no
raw environment values. APP_ENV opens that same node's Environment page; a Node
log link appears only when the application can resolve its route.

Table rows travel separately through `BrowserContext::publishTableMutations()`
and the existing viewer-scoped table classifier. When the daemon agent handles
one `DAEMON_CLUSTER_PICTURE_PORTION`, it captures the immutable mirror before
the portion, applies the portion, then runs the page publishers in that same
worker callback. Its seven mirror-backed viewport tables — Placement, Workers,
Agents, Cron, HTTP routes, Env diverged and Env sources differ — implement
`DaemonPictureViewportTable::pictureMutations()`. They compare typed complete
subject projections with `DaemonPictureRowChanges::between()`; this is a subject
diff, not a diff of the currently paginated window. The normal classifier turns
those mutations into its existing deltas, counts, facets, announcements and
eligible tail appends. Picture batches settle counts from the final set once,
so replaying an already-held picture after a mirror restart does not add create
arithmetic to the count; unsolicited picture mutations use `originRequestId: null`,
while DB/RT changes keep their existing origin. If a row leaves a search
but remains in the subject, an update followed by `containsRow() === false`
becomes `left_set`; a row that leaves the subject is deleted. When an unordered
new row has no declared place, the count may change without inventing a
position. No row-body history is cached, and derived pictures remain outside
RT. See
[`AbstractHilosDaemonAgent`](../../../framework/backend/Core/Agent/Hilos/AbstractHilosDaemonAgent.php),
[`DaemonPictureRowChanges`](../../../framework/backend/DaemonSection/DaemonPictureRowChanges.php),
[`DaemonPictureViewportTable`](../../../framework/backend/DaemonSection/DaemonPictureViewportTable.php),
and [`BrowserContext`](../../../framework/backend/Core/Browser/Context/BrowserContext.php).

Workers, Agents and Cron carry the shared node line; Cron adds its own
`cronReported` flag. HTTP carries both `node` and `http`: a change to either is
a data-only page publication, while changed routes use the table mutation path
independently. Existing child-table actions and restarts, and their viewer locks,
remain in place. Data-only node and Overview publications do not resend tables
or replace viewport windows.
Node Overview has no browser table of its own; Placement is published by its
separate section page, and the child tables keep their existing table paths.

## What Never Leaves A Node

A sensitive value never leaves the node's processes: neither for the collector,
the page, the browser, a log line nor CLI output. Only “set · N chars” or
“not set” may leave.
The environment catalog declares `sensitive`; do not guess it from the key's name.
No orphan `.env` key exposes its value: nothing declares that it may be shown.

The environment page of node X is served by node X's agent. It answers the
subscription with one whole `page_response`, directly to the asking socket. The
collector's picture contains counts and value-free labels. For a silent node the
section agent answers “node silent” with no values. A directory watch and
periodic rescan make the node agent re-answer open environment pages after
`.env` or `.env.example` changes.
The page's tiles and alarms are computed from that same response (the full
masked key list), not from the table window. The page's two tables — catalog
keys and `.env` orphans — are windows of that same agent: rows are built in its
process from the masked view, so a re-answer re-sends them, and a search or the
attention filter reads only what the reader is shown.

The environment catalog opens a value to an admin view-mode viewer only with
`admin_view_visible => true`; no current key is open, and `sensitive` forbids
opening one. Hide every other value, as for settings.
The node agent asks the view-mode verdict when it answers. It sends a hidden
mark for a closed key's set value and omits that key's drift entirely; an orphan
is hidden for a viewer and length-only for an administrator. See
[admin-view-mode.md](admin-view-mode.md).

A fingerprint — type, source and a 16-hex SHA-256 hash of the catalog key and
its value in the catalog type — travels only from node to collector. Boolean
`0`/`false`/`no`/`off` have one canonical value. The collector replaces each
hash on receipt with a 16-hex HMAC label using a salt minted for its lifetime;
it does not retain the node hash. The mirror receives a whole snapshot after a
collector restart, so labels from two collector lifetimes do not mix. Equal
labels mean equal values within one picture. An unsalted short hash of a small
set of candidates (`prod`/`staging`, `true`/`false`, a short password) can be
matched by trying those candidates. The comparison must reveal what differs
without revealing the value; the short label in the cell still serves that
comparison. `ClusterDaemonPicture::environmentComparison()` compares the labels,
types and sources of answering nodes. It separates divergent keys, equal values
from different sources, and `per_node` keys, which differ by design. Each key
has a `known`, `unknown` or `undeclared` cell for every node; a silent node is
unknown, while a missing declaration on an answering node is a discrepancy.

The Env mismatch page holds two tables, `hilosDaemonEnvDiverged` and
`hilosDaemonEnvSourcesDiffer`, built by the page agent from that comparison:
per node a label, a type and a source. The one value they carry is the serving
node's own, read in the page agent's process as the environment page reads its
node's: never for a `sensitive` key, hidden for a view-mode viewer unless the
catalog opens it. Their rows use the same complete-subject mutation path above,
under the viewer's held viewport and its existing privacy verdicts. The page's
own part, `envMismatch`, names the cluster mode, the serving node and, once a
picture exists, the picture's nodes, the answering ones, the count of each
bucket and the `per_node` keys. With no page-wide debt, a changed summary is a
data-only `page_response`; it does not resend either table window. A page owed
whole follows the complete-answer recovery path.

Before the mirror has a picture, `AbstractHilosDaemonEnvMismatchTable::query()`
refuses with the existing `HilosException`. That state is unknown, not a
known-empty set, so the page records the refused windows as owed. A later known
picture lets the ordinary recovery send authoritative rows and totals for both
tables; a genuinely empty bucket then returns its known zero. This null-mirror
refusal belongs to these Env mismatch tables and does not describe every other
Daemon table's source semantics. Window debt and its recovery follow
[Coming Back Without A Reload](browser-source-fanout.md#coming-back-without-a-reload).
See [`AbstractHilosDaemonEnvMismatchTable`](../../../framework/backend/Tables/Daemon/AbstractHilosDaemonEnvMismatchTable.php).

The September 16 decision carried fingerprints in synchronized RT; the October 4
decomposition moved them to the node frame, preserving the boundary on values.

## Restarting One Agent

A restart stops that one instance and brings it back by the same placement rule
that put it there; the master answers `agent:restart` itself after the work, and
the CLI prints the result. Keep that process boundary from
[../cli/command-execution.md](../cli/command-execution.md).
The command answers when the agent is up again, or refuses in words: no such
agent, node silent, or start refused by the freeze or leadership gate. A caller's
wait can end while a slow restart continues; the master's restart record stays
open until the agent starts or the restart fails.

`WorkerServer::stopAgent()` already stops one agent without stopping its worker
neighbors. Do not offer a worker restart: it takes down every agent on that worker.

| Kind | Where it comes back | Restart path |
|---|---|---|
| Node replica | On the same node, started by that node's master | The master stops and starts the instance directly. |
| Leader singleton | On the leader | The leader stops and starts it directly; this does not wait for the next bootstrap pass or address. |
| Policy-placed | On the node selected by the leader's policy, possibly a different one | The leader checks best fit before stopping it. If that is its current host, the restart stays in place and retains placement across the stop report. Otherwise the leader waits for the old host's stop status before placing it on the selected node. |
| Indexed | The same type:index under its declared placement rule | The command carries the index into the restart; it does not wait for another addressed frame. |
| Idle (“lazy”) | Immediately, with the idle window counted from the new start | Waiting for the next address could leave the restart open indefinitely. |
| Monopolistic | Under its placement rule, on a suitable monopolistic worker | The old worker is preferred when still able to take the agent; otherwise a free one is used or one is raised. The worker pool does not shrink when its agent stops. See [monopolistic-agent.md](../agent-system/monopolistic-agent.md). |

For a local restart, `WorkerServer::restartAgentHere()` prefers the instance's
former worker when it is still suitable. If the old worker has not reported the
stop, the start waits for that report or for the worker link to close. A POLICY
restart to another node uses the leader's placement status to finish, including
a best-fit retry if the selected target cannot take the agent. With cluster mode
off, a POLICY agent restarts here.

The CLI speaks to its local daemon. A replica on another node is restarted by
that node's master; a cluster-scoped agent is restarted through the leader that
holds placement. The master doing the restart owns its open record: the local
host for a replica or an in-place restart, and the leader for a POLICY move.

That master's node frame carries `restartingAgents`: each entry names the agent
and the node that hosted it when the restart began. It remains until the agent
starts or the restart is refused, even if a command caller's wait has ended.
The Agents table projects marks from every slot of the cluster picture onto
their original node, including marks owned by the leader during a POLICY move.
Everyone watching that node, including a new subscriber, sees `restarting`.
While no live instance is reported, its row keeps its registry declarations
and log stream with null `workerIndex` and `workerKind`, drawn as a dash.
When the mark ends, that temporary row disappears unless the instance is live
on that node again. Marks are part of the page's roster fingerprint; metrics
and report times do not resend agent windows.

The Vue, React and Angular confirmations use the shared
`createHilosDaemonAgentRestart` lifecycle. Each names the agent and the page's
node, and says the restart starts immediately under the placement rules and
leaves neighboring agents alone. Only a clustered `POLICY` agent gets a warning
that it may come back on another node. The equivalent CLI includes `--node`
only in cluster mode.
Restart is disabled while a request is pending, the node is silent, the agent
is restarting, or the viewer is in the admin view mode. The ordinary ADMIN
action gate refuses a viewer's forged submit too; restart is not a reading action.

The page sends the typed request through its registered worker's control
connection to its own master. The master bridge invokes the existing
`agent:restart` machinery, correlates its outcome with the web waiter and sends
the page a handover answer. The browser receives the ordinary tracked
`action_success` or `action_error` only after that outcome. A failed request
keeps the confirmation open; the client never sets a restart mark optimistically.
The bridge's thirty-second expiry abandons the caller's waiter without canceling
the restart itself. Late replies cannot answer a newer request.

## Measuring The Daemon

- **Node cores:** the smaller of the cgroup v2 `cpu.max` quota (the minimum
  quota/period along its ancestor chain) and CPUs allowed to the process (the
  answer `nproc` gives). Name the limiting source, `quota` or `cpus`.
- **Daemon load:** sum the master's and every live worker's increases in
  `utime + stime` from `/proc/<pid>/stat`, converted from clock ticks to CPU
  seconds, and divide by elapsed seconds. The result is occupied cores; show
  the master separately and the machine's `/proc/stat` load for context. The
  first sample and a newly seen process establish a baseline; their CPU load is
  unknown until the next sample. A dead process contributes no final interval.
- **Memory:** resident memory of the master and live workers comes from those
  stat rows. The ceiling is the smaller of cgroup v2 `memory.max` along its
  ancestor chain and physical memory, named `cgroup` or `machine`. PHP
  `memory_limit` is a limit for one process and is shown for reference.

Occupied cores are rounded to 0.01, machine load to a whole percentage, and
resident bytes down to whole MiB at the source. Unavailable values stay null.

[DaemonStatus](../../../framework/backend/Core/Daemon/Master/DaemonStatus.php)
is the master's sampler. `GET /status`, `daemon:status`, and the node frame read
its last sample. `ProcessMetricsReader` reads process stat rows, while
`NodeLimitsReader` reads node limits and machine CPU counters. `ProcessStat`
remains the parent PID and zombie parser used by the orphan reaper.

Unknown is a dash, never zero: an unavailable `/proc` or cgroup measurement must
stay unavailable. This is the second rule in [logs.md](logs.md), *Rules This
Feature Proved, Wider Than Logs*; use a named fallback above only when available.

## What The Master May And May Not Do

Apply [../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)
to every frame-producing path. The master may read its own memory and kernel
pseudo-files under `/proc` and `/sys/fs/cgroup`: the kernel produces them, with
no ordinary disk file to wait for. The master samples kernel pseudo-files every
five seconds; the status doors read the completed sample. Pack a frame once and
put it
on one local worker connection. HTTP request accounting is an increment in memory.

No database access, ordinary file read, network work beyond that local worker
connection, waiting for an answer, or packing instance-sized rosters on every
measurement interval. The `.env` on disk belongs to the node agent.

## Switching The Section On

The project declares `DAEMON` in `Hilos::FEATURES`. Startup requires ten
abstract Daemon pages and three agents, refusing a partial activation. The
required pages include Overview, Agent placement, Node Overview, Workers,
Agents, Cron, WebSockets, HTTP, Environment and Env mismatch. Thin backend page
subclasses and `PAGE_TABLES` bindings activate the feature in all six demos:
chat, binance-btc-tracker, tasks, polls, online-testing and ecommerce-shop.
`hilosDaemonPlacement` belongs to the Agent placement page in each project;
table registration, row shape and ACL are unchanged. Node Overview has
no browser table of its own, and the existing child-table paths stay with their
pages. The section agent serves Overview and Agent placement; in chat it also
replaces the former index agent. The Environment subclass keeps its
node-addressed `SUBSCRIPTION_AGENT_TYPE`. Six node child addresses require a
node ID, including on standalone installations.

The section owner `HILOS_DAEMON` uses the shared `HilosPageHead`: the `bi-cpu`
icon, the lead “Nodes and their processes, with agent placement across them.”,
and the Hilos › Daemon breadcrumb. Its row contains ordinary addressed links
for Overview and Agent placement, plus Env mismatch in clustered mode. The
mismatch link is inapplicable when mode is unknown or reading it raises
`EnvException`; that does not change direct subscription behavior. On a
standalone direct URL, Env mismatch still answers with `clustered: false` and
no comparison, and keeps its existing notice. Node remains a separate entity
and head owner with its own row, not a section tab.

Agent placement is page key `hilos_daemon_placement` at the static address
`/hilos/daemon/placement`; `placement` is not a node ID. The root Overview has
no browser Placement table, controller or local toggle. It shows the Nodes
diagram, consensus, database summary and entry-point line; node links still
open the addressed Node Overview. The data projection stays lean: `daemon`
carries `clustered`, `nodes`, `consensus`, `placement` and `entryPoints`, while
the root database summary is unchanged. The node roster keeps its required
measurements and `standing.connections`; Node pages retain their own full
measurements, standing counts, worker and environment diagnostics.

In the whole-picture projection, `entryPoints` is null until its source is
known; otherwise it contains `{nodeId, commandPort, httpPort}` with nullable
ports. Clustered mode selects the live source from the existing picture leader;
standalone mode selects only its sole live node. A missing, silent or unresolved
leader does not fall back to the page agent or another consensus responder.
Missing standing or HTTP sections leave their own port null, and a known zero
remains zero. A missing full snapshot leaves `daemon` null. The Overview's
metrics link resolves to the HTTP page for the source node, not to a listener
URL; if that page has no HTTP section yet it can show its existing waiting state.

The root entry line shows Browsers, CLI on `:port`, HTTP `:port` and a metrics
link when the page resolver supplies an address. Agent placement reuses
`HilosDaemonOverviewData` without the database summary. Its successful initial
`page_response` carries the shared head, breadcrumb, tabs, `daemon` projection
and first `hilosDaemonPlacement` window. The browser subscribes to this page
directly; there is no root preload, header action or summary action. Its viewer
lease starts after the successful reply and follows the existing unsubscribe
cleanup on leave or connection close.

`AbstractHilosDaemonPage` publishes only its Overview data. The separate
`AbstractHilosDaemonPlacementPage` owns the Placement page's fingerprint, data
and table mutations. The Overview has no Placement window, viewport or table
mutation traffic. After applying each picture portion, the daemon agent runs
both publishers with the immutable before-picture. They use the existing
worker-local `ClusterDaemonPictureMirror`; no database, new runtime state,
cache or SQL stores the projection or ports. An unchanged summary is deduplicated
and data-only changes do not replace table windows; full-page recovery and
viewport debt keep their existing behavior.

Agent placement keeps the existing four totals, unknown/empty/unplaced values,
alarms and search semantics. Running instances count every copy on every online
node; any silent node, missing roster or empty node list makes that total null.
Agent types count the project registry; cluster types count declared LEADER and
POLICY types, even on standalone. Unplaced types come from the leader or the
sole standalone node: null means unknown, an empty list means All placed, and
each reported type gets one alarm. Search narrows the matrix window, not its
totals or alarms.

Search and the expanded cell are local to the lifetime of the Agent placement
page. Expansion reads current ids from the selected row/node pair and uses the
viewport's ordinary row detail: beneath the type's row across the table, or in
the type's card on a narrow screen. Only one panel is open; clicking the same
cell or Collapse closes it, another node changes its contents, and another type
moves it. Replacing a window through search, filter, order or page closes the
panel and clears selection; the new window does not reopen it. Missing rows,
empty or unknown cells, silent or removed nodes, an unknown picture, refusal or
freeze also clear selection. Admin view-mode readers have the same controls.
Data-only updates while Placement stays open preserve search and selection;
navigation away or unmount ends their local state, so returning starts fresh.
Both table rows and cards use the viewport's ordinary slots, whose ids wrap.
Changed rows still use the existing table classifier and held viewport, even
when the summary is unchanged; a page-wide recovery answer takes priority over
partial publications.

All three SDKs build nine pages: Overview, Agent placement, Node, Workers,
Agents, Cron, HTTP, Environment and Env mismatch. WebSockets remains unbuilt.
The frontend mounts those pages in chat, binance-btc-tracker, tasks, polls and
ecommerce-shop. Online-testing enables the backend feature without a UI.
Ecommerce activates `DAEMON` without `LOGS`; its root and every node head
suppress log links, as do its Workers and Agents pages.

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
The section's e2e live in one spec per kit
(`demo/binance-btc-tracker/tests/e2e/tests/daemon.spec.ts`,
`demo/tasks/tests/e2e/tests/daemon.spec.ts`,
`demo/polls/tests/e2e/tests/daemon.spec.ts`), with shared checks in
`framework/frontend/e2e/daemon.ts`.
