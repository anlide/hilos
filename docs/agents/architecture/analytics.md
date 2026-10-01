# Analytics: Node Journals And One Writer

Read this before touching `Hilos::$ac` or anything under
`framework/backend/Core/Analytics/`, before adding an analytics event, and before
writing into an `hilos_analytics_*` table from anywhere.

## The Rule

**The analytics tables are written by one agent, the cluster's writer, and by
nobody else** — the master process excepted until HIL-1156 moves its half too.
Every other process hands its events to the **journal agent of its node**, which
keeps them in files until the writer has them in the database. This is the frame
the owner set when P-415 was taken apart (26.09.2026): analytics is written into
the database by dedicated monopolistic agents, no other process touches its
tables, the node's journal guarantees that what reached it will be written now or
later, and the writer does not care which node it stands on.

The chain is four leaves; this document describes what has landed:

| Leaf | What it does |
|---|---|
| HIL-1154 | the journal on every node, the writer loading the files of its own node |
| HIL-1155 | the writer collects the files of every node (not in the code yet) |
| HIL-1156 | the master stops writing analytics itself (not in the code yet) |
| HIL-1157 | a ceiling on the journal and an account of what was lost (not in the code yet) |

## The Chain

```
worker process ── Hilos::$ac ──► AnalyticsJournalOutbox ──(analytics_journal_append)──►
    AnalyticsJournalAgent (one per node) ── AnalyticsJournalDirectory: open file → ready file
        ◄──(analytics_journal_read / _portion / _loaded)──► AnalyticsWriterAgent (one per cluster)
            ── AnalyticsJournalLoader ──► AnalyticsStore ──► hilos_analytics_* tables
```

- **The source** is `AnalyticsCollector` (`Hilos::$ac`) in every process but the
  master. Its worker methods — the sessions of the worker and its agents, the
  signals delivered to them, a connection joined to its browser session, a
  session renamed or identified — build records of the journal and gather them
  in `AnalyticsJournalOutbox`. The batch leaves as one frame to the journal agent
  of the node once a second, at 64 KiB gathered, and at the end of the process
  (`WorkerManager::cleanup()` sends it with the stop hooks' frames, before the
  connection closes) - and at once after a browser session is renamed, so the
  rename reaches the journal ahead of the new token's first record from another
  worker. The payload is masked here, at the source
  ([../signals/dto-convention.md](../signals/dto-convention.md)): the file on the
  node's disk is storage too.
- **The journal agent** (`AnalyticsJournalAgent`, `AgentScope::NODE`, a monopolistic
  worker) is the one owner of the node's journal. It appends the lines of a batch
  to the open file at once, syncs the file to disk once a second when it was
  written to and at rotation, and rotates it at 1 MiB or 10 seconds after it
  opened. It answers the writer's reads with portions of whole lines of a ready
  file and deletes a file the writer confirmed.
- **The writer** (`AnalyticsWriterAgent`, `AgentPlacement::POLICY`, a monopolistic
  worker) asks the journal agent of its own node once a second, while it holds no
  file, for the oldest ready file; reads it in portions of up to 128 KiB, asking
  again when an answer is ten seconds late; loads the whole file in one
  transaction and confirms it. A database failure throws what was read away and
  asks for the same file again after 5 seconds, doubling up to a minute: the files
  of a node are loaded strictly in order.

Turning it on is `HilosFeature::ANALYTICS`: it requires both agents, the framework
starts the collector in every process of the project, `Hilos::initAnalytics()`
refuses a project without the feature, and the start refuses the feature without
an `analytics_journal` directory ([filesystem.md](filesystem.md)).

## The Directory

`analytics_journal` is a reserved `$fs` name, and a **node** directory: the start
refuses it declared `CLUSTER`. Inside it the journal agent owns the subdirectory
`<APP_ENV>/<node>` — the cluster node id, or `node` off a cluster — so the
environments that mount one data directory (chat's do) and the nodes of one
volume never see each other's files. Directories are `0700`, files `0600`.

- the open file: `<number, 12 digits>-<16 hex>.open`;
- a ready file: the same stem with `.jsonl`, pattern `^\d{12}-[0-9a-f]{16}\.jsonl$`.

The number is the order — the largest on disk plus one. The random tail keeps a
name unique when a freeze emptied the directory and the numbers began again,
while the database still remembers the files it loaded under the old ones. A
name that comes over the wire is checked against the pattern; only
`AnalyticsJournalDirectory` turns a name into a path.

At its start the journal agent closes a file a previous life left open as ready
(its last line may be cut in half by a machine crash) and continues the numbers.

## The Records

A line is one JSON object and a line break, encoded with
`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`; the keys live in
`AnalyticsJournalRecord`. `ts`, `startedTs` and `openedTs` are unix milliseconds
at the source, never at the writer. A session key is 32 lowercase hex characters,
drawn by the process (`RandomHelper::hex(16)`) and stored as `UNHEX()` in
`session_key`.

```
{"t":"journal","v":1,"node":"<nodeId|''>","openedTs":…}          first line of a file, by the journal agent
{"t":"worker_session","key","workerIndex","monopolistic","startedTs"}
{"t":"worker_session_stop","key","ts"}
{"t":"agent_session","key","workerKey","agentType","agentIndex","startedTs"}
{"t":"agent_session_stop","key","ts"}
{"t":"agent_user_action","agentKey","userActionId","signal","payload","ts"}
{"t":"agent_system_signal","agentKey","signal","payload","ts"}
{"t":"agent_cron_signal","agentKey","cron","payload","ts"}
{"t":"worker_system_signal","workerKey","signal","payload","ts"}
{"t":"api_agent_action","apiRequestId","agentKey","signal","payload","ts"}
{"t":"ws_connection_attach","acceptKey","sessionToken","userAgent","acceptLanguage","ts"}
{"t":"browser_session_rename","oldToken","newToken","ts"}
{"t":"browser_session_identity","sessionToken","identityType","identityValue","ts"}
```

`userActionId` and `apiRequestId` are row numbers the master wrote and sent in the
meta of the signal (HIL-1156 replaces them with keys). A payload is an object
already masked, or null; one that cannot be encoded travels as null.

**A batch stands on its own.** It opens with the description of every session its
records name — the worker first, then the agents — so a batch lost on the way
loses only its own events, and no later event of the same agent is orphaned. A
session just opened is described in the next batch even if nothing happens in it.

**The journal does not write about itself.** The sessions of the two analytics
agents and the signals delivered to them are not recorded: every batch would
otherwise produce a record of itself.

## Loading

`AnalyticsJournalLoader` loads one file in one transaction
([../orm/transactions.md](../orm/transactions.md)): the records in order, the facts
in multi-row inserts of up to 500, then the mark in `hilos_analytics_journal_file`
(`node_id` — `''` off a cluster — and `file_name`, unique), then the commit. A file
already marked is only confirmed again: a lost confirmation or a writer that moved
writes nothing twice. All SQL lives in `AnalyticsStore`.

- A session description is an upsert on its key — a repeat costs nothing; a stop
  stamps `stopped_ts` once.
- The master's rows a portion of facts names are checked in one select per
  table: a vanished user action leaves the reaction uncorrelated, a vanished API
  request takes its fact with it.
- A connection attach upserts the browser session by its token (and keeps the
  history of user-agent and accept-language changes), then gives the connection
  row, found by its accept key, its owner.
- A rename onto a token another session already holds is passed over: the visit
  stays split rather than the unique token refusing the whole file.
- `last_seen_ts` of a browser session never moves back: the batches of different
  processes arrive out of order.
- What cannot be applied — the half line a machine crash left, an unknown type,
  an unknown session key, a value too wide for its column, a rename conflict, a
  vanished API request — is passed over and counted, and the writer says it in one
  warning per file. A file is never refused for its records: one poisoned file
  would stop its node's journal for good.
- The numbers the store learns inside the transaction enter its cache only at the
  commit: a number born in a rolled-back transaction never names a row later.

## The Freeze

Both agents are in the roster and the freeze stops them like any other agent. The
journal agent, stopped by the freeze, throws the node's whole journal away; the
writer starts again with empty caches. The collector is in no roster and answers
the freeze itself: while the node's row silences the unstopped writers it records
nothing and throws its gathered batch away, and an agent stopped meanwhile has its
stop handed over, with its own moment, once the freeze lets go
([protected-mode.md](protected-mode.md)). Nothing is reopened after a swap: the
descriptions travel with every batch.

## Stopping A Node

The journal agent is the last to leave. Every other worker hands it its last
batch on the way out — `WorkerManager::cleanup()` records the stops and sends the
batch with the stop hooks' frames — so a node stops in two waves (approved by the
owner 30.09.2026): SIGTERM to every worker but the journal's; once their processes
have exited, their connections are closed and their last frames were dispatched,
the journal agent is stopped with an
ordinary `agent_stop` over its connection, behind everything sent to it before,
and closes its open file as ready; then its worker gets SIGTERM. The daemon marks
this with `AnalyticsJournalAgentDaemon::stopsAfterOtherWorkers()`; the mechanism
is `WorkerServer`'s ([worker-lifecycle.md](worker-lifecycle.md)). The master's
shutdown ceiling does not change. The stop of the journal's own worker session is
lost — one line per stop of a node, by consequence.

## Settled — Do Not Reopen

**Frames that went into the socket of a journal process that then fell are lost.
There is no delivery with acknowledgement, and there will be none.** The
framework raises the node's replica again at once and holds the frames meant for
it while it starts (`parkUntilAgentUp`, HIL-629, HIL-1040), so the window is what
was already written into the fallen process's socket. The owner, 30.09.2026, asked
whether to accept the loss or build acknowledgement with retries: «Принять
канешна. И запиши в документации, чтобы этот вопрос больше не подымался.» — "Accept
it, of course. And write it down in the documentation, so this question is never
raised again."

The other losses the owner accepted, with the same standing:

- **A machine crash** loses up to a second of the node's events: lines reach the
  operating system at once and the disk once a second.
- **A restore** loses the journal of every node at the moment of the freeze — what
  the writer had not loaded by then (counted by HIL-1157).
- **A source process that crashes** loses its unsent batch, up to a second, as it
  lost its insert buffer before.

## What Is Not Here Yet

- The files of other nodes wait on their disks: the writer reads its own node only
  (HIL-1155).
- The master writes its facts itself, through `AnalyticsStore`, and its row numbers
  travel in the meta of the signal (HIL-1156). Its meta also stamps the frames it
  forwards while an HTTP request is parked, which is why the journal's own signals
  are excluded at the source.
- The journal has no ceiling, and nothing counts what was lost (HIL-1157).
