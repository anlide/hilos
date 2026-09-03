# Logs (The Node Owns Its Files)

Read this before touching the log directory of a node from code, adding a link to
the chain that carries a node's log index to a screen, adding or reading a log
setting, or changing what happens to an archived batch.

The logs feature is the part of the framework that measures, shows, rotates and
carries off the files every Hilos process writes. Its machinery is
`framework/backend/Log/`; the two agents that do the work are `LogStoreAgent`
(`hilos_log_store`, one per node) and `LogAggregatorAgent`
(`hilos_log_aggregator`, one per cluster), and the page agent a project extends
is `AbstractHilosLogsAgent` (`hilos_logs`). The feature is switched on by
`HilosFeature::LOGS`, which requires the overview, keys, workers, rotations and
viewer pages and all three agents together, so an operator never lands on a
dead link inside it.

The document holds what the code does not put in one place: who may open the
directory, how a picture of it travels to a screen without anybody else opening
it, what leaves the archive and on whose word, and where the numbers that govern
all this come from. What each class does is documented on the class.

## Core Rule

A node's log directory has exactly one owner, and that owner runs on that node:
`LogStoreAgent`, declared `AgentScope::NODE` and hosted in a monopolistic worker.
Every read of a file in the directory, every rename into the archive, every
deletion and every marker goes through it, addressed by node id. Nothing else
opens the directory — not the cluster aggregator, not a page, not a table, not
a test driver on some other node. Code that needs a file from another node asks
that node's owner by signal; it never asks for the path.

## The Node Agent Owns The Directory, Not The Lines (HIL-753)

The ownership is of the **directory**. The lines are written by the processes
that log, through `Logger`, each into its own file — `daemon.log` and
`daemon-error.log` for the master, `worker-<n>.log` and
`worker-monopolistic-<n>.log` for the workers, `agent-<type>.log` for an agent.
Routing a line through the owner is forbidden: it would turn logging in the
whole framework upside down, put an agent on the path of every message of every
process, and buy nothing, because the owner measures files and does not care who
writes them.

The registry entry is three keys, and the third is the whole point:

```php
LogStoreAgent::AGENT_TYPE => [
    AgentRegistryKey::WORKER => LogStoreAgent::class,
    AgentRegistryKey::DAEMON => LogStoreAgentDaemon::class,
    AgentRegistryKey::SCOPE => AgentScope::NODE,
],
```

`AgentScope::NODE` is one replica per node, started by
`WorkerServer::onInitialWorkersReady()` once that node's workers are up (see
[../agent-system/adding-agent.md](../agent-system/adding-agent.md)). The daemon
proxy asks for a monopolistic worker, because a directory walk is blocking file
I/O and a node needs exactly one reader of its own directory
([../agent-system/monopolistic-agent.md](../agent-system/monopolistic-agent.md)).
That worker is taken for the life of the node, so a project activating the
feature has to leave room for it in its monopolistic pool minimum — HIL-753 found
that out by an e2e run in which every page sat in `loading` because the fifteenth
monopolistic agent had no worker to go to.

What the owner holds is a `NodeLogIndex` in its own memory, rebuilt by walks:

- a **live walk** of the log root every five seconds
  (`LogStoreReader::readLiveFiles()`), laid over the archive as the last full
  walk saw it;
- a **full walk** of root and archive every minute (`LogStoreReader::read()`),
  the only kind that feeds the day windows;
- a full walk **out of turn** when a live key vanished or shrank — rotation has
  just renamed it into a batch whole, and until the archive is re-read the index
  would deny the batch exists — or when the last full walk failed, so recovery is
  seconds rather than a minute.

The walk classifies a file by name and only by name: the daemon's own streams by
exact basename taken from `DAEMON_LOG_FILE` and `DAEMON_ERROR_LOG_FILE` (each with
its raw twin, see rotation below), then the `worker-monopolistic-`, `worker-`
and `agent-` prefixes in that order. The daemon names are tested first, and a
catch-all "any other `*.log` is the daemon" is forbidden: it would swallow every
stray file in the directory. Files that are not logs — the protected-mode freeze
file, a takeout marker — are not counted and not weighed anywhere.

How much a key grew over the last day is measured continuously, whether or not
anybody is looking (`LogGrowthWindow`): a monotonic counter fed by the key's total
weight, live file plus every batch of it, so a rotation or a cleaned-away batch
contributes zero rather than a negative number. Before a day has passed the
figure is `null` and the column shows a dash. When the store goes unreadable the
series is broken on purpose: what was written while nobody could read the
directory is not measurable afterwards, and carrying the counter across the gap
would report it as last day's growth.

The index also carries the one fact about a node's store that no other machine
can work out — the absolute log root (`logDirectory`). A page worker holding the
cluster picture knows its own root and nobody else's, so the address an operator
is told to copy from comes from the node that measured it, never from the
settings of whoever draws the screen.

The owner is deliberately quiet. It writes into the very directory it measures,
so only a **change** of availability reaches the log at all — one line per
crossing — and keys and batches coming and going are `DEBUG`, visible under
investigation and nowhere else. A line per walk would be the biggest thing in
the index.

## The Circulation: Node Index, Cluster Picture, Page Portion (HIL-754, HIL-755, HIL-756)

Three layers, three holders, and each layer is a whole copy handed on, never a
difference:

```
LogStoreAgent (per node)          NodeLogIndex, in memory
    │  logs_node_index_report     the whole index, unasked, on the node's tick
    ▼
LogAggregatorAgent (per cluster)  ClusterLogIndex, in memory: one slot per node
    │  logs_cluster_index_portion  whole slots — a snapshot, then changed slots
    ▼
AbstractHilosLogsAgent (page)     ClusterLogIndexMirror, static in the worker
    │  page signals / table windows
    ▼
the six pages of the section
```

**Node to aggregator.** Every node sends its index whole
(`NodeLogIndexSignalData`), on its own tick, and nobody asks for it. A frame is
due when the walk found something to report and `logs.index.push_interval_ms`
has elapsed since the last one; failing that, one frame a minute goes out with
nothing new to say — not as a sign of life, which cluster membership answers,
but so that an aggregator restarted or moved by policy rebuilds its picture of a
quiet system instead of waiting for the next thing to happen. Nothing is
awaited and nothing is retried: the frame is the whole index, so a lost one
costs nothing the next does not repair, and while the aggregator is unplaced or
moving the signal is dropped, which is the same case seen from the other end.
The "changed since last frame" flag accumulates across walks rather than being
read off the latest one, because the walk that happens to be latest when a
frame is due is usually the one that found nothing.

**The aggregator.** One instance for the whole cluster — the default
`AgentScope::CLUSTER` — placed by `AgentPlacement::POLICY` rather than pinned to
the leader, so it survives a re-election instead of dying with the term; not
monopolistic, because it does no blocking work and a monopolistic worker holds a
single agent. It starts with an empty picture and touches nothing else: no
reader, no path, no first walk, and its **only** source of data is the frame
(`applyNodeIndex()`). A node's own index arrives the same way whether that node
is the one hosting the aggregator or not — one behavior, no "is this me"
branch. A frame **replaces** that node's slot; nothing is merged and no
timestamp is compared, because a node's frames travel one link of the mesh that
does not reorder them, while dropping a frame as "older" would stick forever the
first time a node's clock was wound back. Streams are counted per (key, node)
pair and never folded by name: the same `worker-0.log` on two nodes is two
files, rotated and carried off apart.

**Which nodes exist is not the aggregator's to say.** A node is in the picture
because it reported, and the last frame always arrived before the machine fell
over — so liveness cannot be read off the frames. The aggregator keeps no
silence clock; it reads cluster membership out of `HilosClusterNode` (declared
in `READS_RT` on the class, so the worker holds the copy before the first frame
can arrive) at the moment it hands the picture over, and a node the register no
longer sees keeps its slot and its figures with `online` false. Its files are
still on a disk somewhere; saying so is the view's whole job. A second answer
to "which nodes are there" here would be a second truth beside the master's
register.

**Aggregator to section.** The subscription is a claim of interest
(`logs_index_watch`) carrying one number — how many connections are watching
any page of the section — repeated on the page agent's own tick. A non-zero
count opens the subscription and renews its lease (claimed every thirty
seconds, forgotten after ninety); zero cancels it at once, because a subscriber
whose last viewer just closed the tab must stop costing frames immediately.
There is no subscribe/unsubscribe pair to keep in step and no farewell to lose:
a subscriber whose process died is forgotten when the lease runs out, and an
aggregator restarted or moved is repaired by the next ordinary claim. The first
claim from a source is answered on the spot with the whole picture, outside any
window — there is no burst to fold, and making the first screen wait for
something already in memory would be the window charging for work it did not
do. After that only slots that changed travel, coalesced over half a second.
**Nothing goes up while nobody is watching:** a cluster with no administrator
looking at it costs one frame per node per interval and not a byte more.

**The mirror.** `ClusterLogIndexMirror` is static in the page worker: the
picture belongs to the process and outlives any one page dispatch, and every
page of the section reads the same copy. A snapshot frame replaces it, a portion
is laid over it slot by slot — which is why the frame carries that flag, since a
portion missing a slot and a snapshot missing one look the same on the wire.
The mirror distinguishes three states the screens must draw differently: no
picture has arrived yet (`known()` false), the aggregator answered and had no
node to report (an empty picture), and a node that reported an unreadable store.
None of them is zero. The picture outlives the last viewer, so the next one sees
the last known figures at once and the fresh ones a moment later; it is
forgotten only when the page agent stops, so a restarted agent does not serve
the picture of its previous life.

**The pages.** The page agent (`hilos_logs`) is a surface a project implements
by extending `AbstractHilosLogsAgent` with an empty subclass, and it is a
separate agent from the aggregator on purpose: where the picture **comes from**
must not be the same object as what **shows** it, and nothing above the
aggregator ever talks to a node-local owner. The overview projects the tiles and
one row per node into a single header frame; keys, workers and rotations ride
ordinary viewport tables whose window is re-sent whole rather than patched,
because a table delta is built from a `SourceChange`, the mirror is neither a
DB nor a runtime source, and it raises none
([browser-source-fanout.md](browser-source-fanout.md)). Every subscribe to any
page of the section also counts the connection as a viewer of the **section**;
without that the aggregator would send nothing and the screens would be empty
forever rather than merely new. Viewers are reconciled against the connection
roster on the agent's tick, so a tab that went without a word stops holding the
subscription open.

The six pages are `hilos_logs`, `hilos_logs_keys`, `hilos_logs_workers`,
`hilos_logs_rotations`, `hilos_logs_view` and `hilos_logs_settings`. Their
headless halves in `@hilos/core` are `admin/logs/hilosLogsOverview.ts`,
`hilosLogKeys.ts`, `hilosLogWorkers.ts`, `hilosLogRotations.ts`,
`hilosLogViewer.ts` and `hilosLogSettings.ts`; each per-framework view renders
from its module and nothing else. How those modules and views are laid out is
not this document's: see
[../frontend/page-module-structure.md](../frontend/page-module-structure.md) and
[../frontend/multiframework-core.md](../frontend/multiframework-core.md).

## Reading And Following One File Through Its Owner (HIL-757, HIL-389)

The browser never names a path. It names a file **structurally** — a source
(`live` or `batch`), a batch stamp, and a stream basename — and the one place
that turns those into a path under the log root is the owner
(`LogStoreAgent::relativePath()`), with `LogLineReader` refusing anything that
resolves outside the root. So the file system is not addressable from the wire,
and the archive layout is known in exactly one process.

A read goes the long way round, and the page steps out of its own action:

```
browser: logs_read_lines(nodeId, source, batchTimestamp, stream, cursor, level, substring)
  → AbstractHilosLogsViewPage::onAction()   checks the node still exists, DEFERS its ack
  → logs_agent_read_lines                   NODE_FIELD = nodeId → forwarded over the peer channel
  → LogStoreAgent::handleReadLines()        reads the page, acks the browser's socket directly
```

`AgentSignalConfigKey::NODE_FIELD` on the owner's `AGENT_SIGNALS` is what makes
a per-node replica addressable ([../signals/routing.md](../signals/routing.md),
node-addressed agent signals): an id naming another node becomes a delivery over
the peer channel, an empty id means "here", which is what a single-node
installation always sends. The owner is the last step of somebody else's action,
so the frame carries the accept key, the action name and the request id, and
the owner answers the browser itself — over a socket that another node may be
holding. The whole read is guarded for that reason: a failure that only reached
the log would leave the browser waiting out its own timeout with the reason
recorded where the person waiting cannot see it. An unreadable file is not such
a failure — a missing file, a batch carried off, a refused path all come back as
a successful page with `readable` false, the way the whole index answers.

Reading runs backwards and only backwards: the first page and the *Earlier*
button are the same tail query with and without a cursor. Following is a
different mechanism:

- `logs_follow_start` is one action, not two, because "show me the end, now
  follow it" as two calls would lose whatever was written between them. The
  owner takes the file size **before** the first read and continues from there;
  taken after, it would skip what the writer appended during the read, and a
  read from the end followed by the size would send those lines twice.
- Once a second the owner reads what each followed file gained and sends the one
  thing that happened as a `logs_lines_appended` frame — lines appended, the
  file rotated away and reading restarted at the start of the new one, the
  viewer so far behind that the owner jumped to the end and says how much it
  skipped, or the follow ended on the owner's side. A file that has not grown
  sends nothing: silence under a level filter is the right answer, not a fault.
  Every frame is stamped with the request id of the start, so a viewer that has
  since switched stream or node drops the frames of the follow it left behind.
- The frame goes **straight to the socket**, not back through the page: the page
  has nothing to add, and the browser may be attached to a different node
  altogether, which the router already knows how to reach.
- A stop (`logs_follow_stop`) is answered synchronously by the page and
  addressed to the node the page recorded at the start, not the one the browser
  names now — a viewer that switched nodes still releases the reader it left
  behind. A follow is also dropped when the page unsubscribes, when the socket
  closes, and — checked before the file is touched — when the connection is no
  longer on the node's roster, which is the only thing that catches a viewer who
  left without a word.
- A stack trace cut by a tick boundary keeps the level of its entry: the reader
  carries the running level across the cut (`inheritedLevel`), so an `ERROR`
  filter still shows the trace that belongs to the error.

The viewer has no *Refresh* button, and none is to be added: freshness arrives
as a push, never as a re-request.

## Rotation Runs Under The Owner (HIL-480)

Rotation is a rename and nothing else: `LogRotator::rotate()` creates
`archive/<Y-m-d-H-i-s>/` under the log root and renames each live `*.log` into
it. The batch directory is created only once there is something to put in it,
so a run with nothing to move leaves no empty folder for the cleanup to carry
around. It has two callers, and they deliberately do not share a factory:

- **The start path.** The container watchdog rotates before the daemon is
  started (`LogRotator::forStartup()`, called from `DockerManager`), when no
  descriptor is open on any of these files, so it takes everything.
- **The runtime path.** The owner rotates while the node runs
  (`LogRotator::forRuntime()`), and it leaves the daemon's raw output pair live
  — `daemon-raw.log` and `daemon-error-raw.log`, named by `DaemonRawStream`.
  `proc_open` gives the daemon files rather than pipes, and a descriptor follows
  the inode it was opened on: rename `daemon.log` away and every fatal, warning
  and trace PHP prints past the Logger lands in the closed batch while the live
  file stays empty. So the raw output has files of its own, derived from the two
  env paths in exactly one place — three callers name them, and three
  computations would part ways on the first edit — which only a daemon restart
  replaces. The owner says one line when a raw stream has grown past a fixed
  size, and nothing more while it stays grown.

The trigger rides the walk and has no clock of its own: the size axis reads a
weight the walk just measured instead of globbing the directory a second time,
and the moment a batch appears becomes known exactly rather than inferred from
a live key that shrank. Three axes, any of which fires: a cron schedule
(`logs.rotation.cron`), an age since the last attempt
(`logs.rotation.max_age_seconds`), a summed size of the live files
(`logs.rotation.max_live_size_bytes`). A numeric axis of zero is off, an empty or
malformed expression is off, all three off is the start-only rotation the
daemon has always done. Every **attempt** resets the age baseline, including one
that moved nothing — an empty directory would otherwise stay past its age
forever and call for a rotation on every walk — and the baseline starts at the
agent's start, because the daemon rotated on its way up a moment ago. The size
axis leaves the raw pair out: counted in, a raw file grown past the threshold
would hold it exceeded for good.

The policy is re-read from the settings on every check, so an edited threshold
is obeyed within seconds. The schedule is the one exception: its `CronRule`
remembers when it last ran, so it is rebuilt only when the expression itself
changes — rebuilt every check, the schedule would never fire.

Before every rotation, and not once at start, the owner checks that the archive
sits on the device of the live logs (a mount point can be put under a running
node): across a device boundary a rename is not a rename, and doing it anyway
would copy every byte, which is the cost this design exists to avoid. The gate
says one line per crossing, and a directory that cannot be measured leaves it
open — the rename reports its own failure, and refusing on a reading that never
arrived would stop rotation for a reason nobody could name.

After a rotation the owner writes the line under its own name (`LogRotator`
reports and never logs, because a rotator running inside a worker used to write
the rotation line into that worker's log rather than the journal of whoever
asked), then walks and pushes out of turn, so the batch is on the screen now
rather than a full walk and a push interval later.

## Nothing Leaves The Archive Silently (HIL-381, HIL-483, HIL-382)

Three parts, three owners, and the order between them is the rule: a policy
**recommends**, an operator **confirms**, and the pruner **deletes what was
confirmed**. Nothing is deleted on a recommendation, and nothing is deleted
without a line in the journal.

**The policy recommends** (`LogArchiveRetentionPolicy::selectEvictionCandidates()`).
A pure predicate — no I/O, no clock read, the instant is injected — over two
criteria: a batch is a candidate only when it is **both** outside the newest
`logs.archive_retention.keep_batches` **and** older than
`logs.archive_retention.max_age_seconds`. Either at zero disables that
criterion; both at zero means nothing is ever a candidate. A value the
environment cannot answer makes the whole policy inert rather than half
configured, because here a disabled criterion widens eviction instead of
narrowing it, and a typo must never hand the pruner more batches than the
operator asked for. The verdict is judged per node in `HilosLogRotationsTable`,
never across the cluster: `keep_batches` means "the newest N of **this**
archive", and one list across the cluster would spend the whole protection on N
batches in total and send a neighbour's freshest batch to the recommended pile.

**The operator confirms** (`logs_takeout_confirm`, forwarded as
`logs_agent_takeout_confirm` the way a read is). The fact that a batch has been
carried off is a **marker file inside the batch directory**
(`LogBatchTakeoutMarker`, `.hilos-taken.json`, carrying `takenAt` and
`takenBy`) and not a row anywhere: the fact belongs to a directory on one
machine, that machine may be cut off from the cluster at the moment its
administrator confirms, and deleting the directory takes the fact away with it
instead of leaving an orphaned row behind — the same reasoning the backup keep
pin is stored by, files as truth. The basename deliberately does not end in
`.log`, so the marker is counted in no file count and weighed in no batch
weight. It is written the way that pin is: a temp file beside it, then a
rename, so a walk in progress never reads half a marker. The owner re-judges the
batch before it writes, in an order that keeps the promise the screen made: is
the directory still there (a batch cleaned away between the click and the frame
is gone, and saying so is the honest answer); is it already confirmed (then
that **is** the answer, with the stamp on the disk, so a second tab and a second
administrator are told what the first was told rather than an error about a
fact they were right about); only then, is the policy still recommending it,
because a batch that came back under protection while the modal was open must
not be confirmed. The stamp is the node's clock, not the browser's.

The confirmation travels back in the index — `takenAt` on the batch — and it is
the one change that moves nothing else: same batches, same files, same weights,
one marker the walk does not weigh. So it is its own axis of the delta
(`confirmedBatchTimestamps`), and without that line the frame carrying the
operator's own click would be judged empty and never sent. On the screen the
confirmed state overrules the verdict and survives an administrator raising the
retention period afterwards; the row repaints when the node's next index reaches
the mirror, not when the ack does — which is why the ack carries a sentence, so
the person knows their click landed.

**The pruner deletes** (`LogArchivePruner::prune()`), and it asks one question
of a batch: does its directory hold a readable takeout marker. It **never asks
the retention rule**. The rule protects what has not been carried off; once a
batch has, there is nothing left to protect, and a batch brought back under
protection by an edited setting is still a batch that is already saved
elsewhere. The marker is read from the disk in the pass itself, not taken from
the walk's snapshot, because a confirmation can be withdrawn between the walk
and the pass and a deletion cannot. The pass rides the rotation **attempt**
(not its success — a node quiet enough to have nothing to move makes no batch,
and a cleanup waiting for one would never run there) and the agent's start,
because the daemon rotated on its way up.

Within a batch the order is files, then the marker, then the directory. An
interrupted pass so leaves a batch that is still confirmed, which the next pass
finishes; removing the marker first would turn the leftovers back into a batch
nobody has carried off and offer it for carrying off a second time. A batch is
emptied file by file and never swept as a subtree: the recursive removal next
door in the backup subsystem is deliberately not the model, because a backup can
be taken again and a log cannot. What the pass put there — the `*.log` files and
the marker — it removes; anything else keeps the whole directory alive.

Every outcome of a pass is named in the journal under the owner's name: a batch
removed (`INFO`, with the stamp of its confirmation), a path that would not go
(`ERROR`, worth retrying), a batch left whole because it holds a file that is
not a log (`ERROR`, waiting for a person), a batch whose marker cannot be read
(`WARNING` — it has quietly gone back to being un-carried-off, and it would be
offered for carrying off twice if this were silent). A pass that found nothing
to do says nothing at all, which on a live node is most of them.

## The Three Logging Modes (HIL-762, HIL-391)

The Logs section is the first consumer of setting presets, and the mechanism is
documented on its own in [setting-presets.md](setting-presets.md). What is the
section's is the composition: `LogSettingsPresets` declares the group `logs`
whose selection is stored under `logs.preset`, with three modes drawn as cards
in this order.

| Mode | `logs.write_level` | `logs.rotation.cron` | `logs.rotation.max_live_size_bytes` | `logs.archive_retention.max_age_seconds` |
|---|---|---|---|---|
| `frugal` | `WARNING` | `0 3 * * *` | 256 MiB | 7 days |
| `normal` | `INFO` | `0 3 * * *` | 512 MiB | 30 days |
| `investigation` | `DEBUG` | `0 */6 * * *` | 1 GiB | 90 days |

Every mode names a fifth key the cards never mention:
`logs.rotation.max_age_seconds`, held at zero throughout. A card names exactly
two rotation axes, a schedule and a size, and an age axis left switched on from
an earlier configuration would rotate against what the card says. A preset has
to be a complete statement about its own subject or it is not a mode at all.

Two keys of the fragment are outside every mode on purpose.
`logs.archive_retention.keep_batches` is a safety net rather than a loudness,
and `logs.index.push_interval_ms` is transport between nodes and has nothing to
do with how much a node writes. A mode that set them would be making a
statement about a subject that is not its own.

The installation starts on `normal`: the catalog default of `logs.preset` is a
literal of the recipe and not an environment value, because the environment
says what a node logs, not which mode an administrator picked.
`LogPresetNameRule` accepts a declared name or the empty string — "no mode
applied" is a state the screen has a drawing for, unlike the write level, where
no step of the scale means "write nothing" — so a typo made on the general
settings screen is refused at the moment of writing, while a name that stopped
existing *after* it was written is read tolerantly by the page as "none
applied". The page itself (`AbstractHilosLogsSettingsPage`) is declarations
only: page key, reach, group provider, subscription signal. That emptiness is
the proof that the mechanism is general. The composition is the owner's, taken
from the Logs mockup of 27.08.2026; the mechanism decides nothing about it.

## Settings Above The Environment (HIL-760, HIL-761)

Every number that governs the feature is a setting with the environment
**beneath** it, not beside it. `LogSettingsCatalog` is a catalog fragment of
eight keys — `logs.rotation.max_age_seconds`, `logs.rotation.max_live_size_bytes`,
`logs.rotation.cron`, `logs.archive_retention.keep_batches`,
`logs.archive_retention.max_age_seconds`, `logs.index.push_interval_ms`,
`logs.write_level`, `logs.preset` — that a project folds into its own settings
catalog with `array_replace(...)`, the way the chat demo's `SettingsCatalog`
folds it beside the delivery-channel fragment. The keys then appear in the
settings table as ordinary rows.

With no row written, a key reads exactly what the node's environment says
(`LOG_ROTATION_*`, `LOG_ARCHIVE_RETENTION_*`, `LOG_INDEX_PUSH_INTERVAL_MS`,
`LOG_WRITE_LEVEL`): the environment is the catalog default, so an installation
that configured nothing keeps behaving as it did. Writing a row overrides that
for **every node of the cluster** — the database is shared, the environment is
per node. A project that never folded the fragment in has no such keys, the
settings are not consulted, and nothing is wrong: that is the plain env
installation, and it stays one.

Every key names the rule its values must pass, so a schedule that would never
fire, a negative threshold, a push interval under the floor or a level that is
not one is refused at the point of writing, on the general settings screen and
under a preset alike. The floor on the push interval is its own rule
(`LogIndexPushIntervalRule`, one hundred milliseconds) rather than the
non-negative one the numeric keys share: under that one zero means "this axis
is off", and off here would mean a node sends a frame every time it notices
anything. A catalog default is kept inside its own rule for the same reason —
an administrator must never be shown a value they cannot save back.

`LogSettingsResolver` reads the rotation and retention policies out of the
settings, and it is asked on every throttled check, so an edit takes effect
within seconds and never waits for a restart. The environment answers instead
when the settings layer is not initialized in this process, the read throws, or
the stored value does not pass its own rule — and then a line is owed to the
journal, because rotation is not something to stop over. That line is written
when the **outcome changes**, not on every check (see the rule below), and a
recovery clears the memory silently so a fault that comes back is reported
again. One reader is deliberately narrower: the interval a node waits between
two index frames is the **written** setting and nothing beneath it, because the
catalog default resolves out of each node's own environment, and walking the
full ladder would let three nodes of one cluster report at three different
rates with nothing on any screen to explain why.

**The write level** is the one setting that reaches every process that logs.
`Logger` holds the threshold as a static read on every line — a process logs
hundreds of times a second, and asking the settings that often would cost more
than the lines saved — and `LogWriteLevelApplier` is the one place that writes
it, so the three moments it can change say the same thing the same way:

- `applyFromEnv()` runs first in every process that logs, before the first line
  of its journal: the environment is readable long before the database, and a
  name that is not a level falls back to `INFO` rather than refusing to start.
- `applyFromSettings()` runs once the settings are reachable — in a worker at
  start, in a CLI command that needs the database — and again on every edit that
  arrives afterwards: `LogWriteLevelSubscriber` sits on the source bus, and a
  settings row written anywhere in the cluster travels the sync every process
  already listens to. Create, update and delete are one event there; an update
  carries only the columns that changed and so names no key, in which case the
  level is re-read rather than guessed at.
- The master is forbidden the database
  ([../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)),
  so it cannot read the setting: every worker reports its level over the worker
  link it already has (`WorkerLogWriteLevelReporter`), and the master applies
  what it hears, writing a line only when the value differs from what it holds.
  The container watchdog lives by the environment alone; a process that gets no
  frames about changes is better off honest than half-obedient.

The journal line about the level changing is written **past** the threshold,
because it explains the silence that follows it. `LogWriteLevelRule` refuses
the empty string: the top of the scale is `ERROR`, and an installation silent
about its own errors is indistinguishable from a dead one.

## Rules This Feature Proved, Wider Than Logs

Three constraints came out of this feature that hold anywhere in the framework.
They live here, beside the facts they were derived from; the documents that own
each subject route to this section.

**A picture that the whole cluster shares but one process produces lives in
that process's memory, not in runtime state.** The runtime is one truth per
cluster and its collection is shared by every node
([../runtime/rt-context.md](../runtime/rt-context.md)); a node's log index or
the cluster picture put there would spill a full replica onto every node,
duplicate the one agent that owns it, and hand the writes to a second owner.
Hold it in the owner, hand it on by signal — whole, so a lost frame is repaired
by the next — and keep a copy in the process that reads it (the mirror), never
a second source. Runtime state is for rows that need a truth source and a
sync, not for a derived view somebody already owns.

**Unavailability is a state, not an exception, and never a zero.** A directory
that could not be read produces an index with `available` false and empty
projections; a day window that has not filled produces `null`; a cluster nobody
has reported for is an empty picture, and a mirror nothing has reached is a
third thing again. Each of those is drawn as a blank tile or a dash, and the
count of what is not known is named beside the total (`unavailableNodeCount`,
`keysWithoutGrowthWindow`) rather than folded into it. A zero would claim there
were no rotations and nothing was written, where the truth is that we do not
know. Do not mint a default where a value did not arrive; carry the absence.

**A complaint about a value that cannot be used is written on the change of
outcome, not on every check.** The same unreadable setting asked about every
five seconds would flood the very journal it configures; the same failing level
asked on every incoming settings frame would do the same. Remember the last
outcome per scope, say one line when it differs, clear the memory silently on
recovery so a fault that returns is reported again. This is what an `onTick()`
that reads configuration owes the journal, whatever it is reading.

## Anti-Patterns

```php
// Wrong: a page, a table or the aggregator opening the log directory itself.
$lines = file(dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]) . '/' . $stream);
```

That page runs wherever the browser is attached and could only ever see the
node it happens to run on, shown as though it were the whole installation.
Send `logs_agent_read_lines` to the node's owner, addressed by `NODE_FIELD`, and
let the owner answer the socket.

```php
// Wrong: a path from the wire.
$reader->read($dto->path, $query);
```

The browser names a source, a batch stamp and a stream; the owner turns them
into a path, and nothing else does.

```php
// Wrong: the picture in runtime state.
final class NodeLogIndexState extends RtState { ... }
```

One truth per cluster means a full replica on every node and a second owner of
the rows. The index lives in the owner's memory and travels whole by signal.

```php
// Wrong: routing a log line through the owner.
$this->sendToAgent(HilosSignalConstants::LOGS_WRITE_LINE, new LogLineSignalData($message));
```

The owner owns the directory, not the lines. `Logger` writes the file of the
process that logs.

```php
// Wrong: the pruner asking the policy.
if (in_array($batch, $policy->selectEvictionCandidates($batches, time()), true)) {
    $this->removeBatch($batch);
}
```

A recommendation is not an instruction. Only a readable takeout marker, written
by the owner on an operator's confirmation, lets a batch go — and files, then
marker, then directory, never a recursive sweep.

```php
// Wrong: a mode that names only the keys its card mentions.
new SettingPreset(self::FRUGAL, [
    LogSettingsCatalog::WRITE_LEVEL => LogLevel::Warning->value,
    LogSettingsCatalog::ROTATION_CRON => self::ROTATION_NIGHTLY,
]);
```

An axis left switched on from an earlier configuration would rotate against
what the card says. A preset names every key of its group, the switched-off
axes included.

```php
// Wrong: the aggregator deciding for itself that a node is gone.
if ($now - $slot->receivedAt > self::SILENCE_SECONDS) {
    $index = $index->withoutNode($slot->nodeId);
}
```

Which nodes exist is the master's register, read out of `HilosClusterNode` at
handover. A second answer here is a second truth, and the files on a dead
node's disk still exist.

```php
// Wrong: the same complaint on every tick.
public function onTick(): void
{
    if (!$this->policyReadable()) {
        $this->logAgentError('logs.rotation.cron is unusable');
    }
}
```

Remember the outcome, speak on its change, clear on recovery.

## Validation

- `composer run test:framework:unit` — the whole of `framework/tests/Unit/Log/`:
  the owner's index and walks (`LogStoreAgentIndexTest`, `LogStoreReaderTest`),
  the read and the follow through the owner (`LogStoreAgentReadLinesTest`,
  `LogStoreAgentFollowTest`, `LogLineReaderTest`, `LogLineReaderAppendedTest`),
  rotation under the owner and its trigger (`LogStoreAgentRotationTest`,
  `LogRotatorTest`, `LogRotationTriggerPolicyTest`, `DaemonRawStreamTest`),
  the recommend/confirm/delete chain (`LogArchiveRetentionPolicyTest`,
  `LogBatchTakeoutMarkerTest`, `LogStoreAgentTakeoutConfirmTest`,
  `LogArchivePrunerTest`), the circulation (`LogIndexPushTest`,
  `LogIndexPushIntegrationTest`, `LogAggregatorAgentTest`,
  `ClusterLogIndexMirrorTest`, `LogIndexFanOutTest`,
  `LogIndexFanOutIntegrationTest`), the settings and their rules
  (`LogSettingsResolverTest`, `LogSettingsPresetsTest`, `LogPresetNameRuleTest`,
  `LogIndexPushIntervalRuleTest`, `LogWriteLevelResolverTest`,
  `LogWriteLevelRuleTest`, `LogWriteLevelSubscriberTest`,
  `WorkerLogWriteLevelMessageTest`) and the pages of the section
  (`HilosLogsPageSubscribeTest`, `HilosLogsRotationsPageSubscribeTest`,
  `LogsViewPageReadLinesTest`, `LogsViewPageFollowTest`,
  `HilosSettingPresetsPageSubscribeTest`). The same suite runs
  `AgentDocGuardTest` over this file's links.
- `composer run test:framework:integration` — the write level crossing
  processes without a restart (`LogWriteLevelPropagationTest`).
- `demo/chat` `composer run test:phpunit` — a preset applied through the settings
  doors (`SettingPresetApplyTest`) and the topology snapshot that pins the two
  agents' scope and placement (`ChatTopologyRegistryTest`).
- `composer run test:framework:frontend` — the six headless modules
  (`framework/frontend/core/test/admin/logs/`) and the preset screen's common
  half (`admin/settings/hilosSettingPresets.test.ts`).
- `demo/simple-poll` e2e `logs.spec.ts` — every screen of the section rendered
  over the live socket. A follow driven end to end from a browser is
  `(not in the code yet — HIL-395)`; rotation, takeout and pruning driven end to
  end are `(not in the code yet — HIL-763)`.
