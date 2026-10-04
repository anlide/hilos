# Analytics: Node Journals And One Writer

Read this before touching `Hilos::$ac` or anything under
`framework/backend/Core/Analytics/`, before adding an analytics event, and before
writing into an `hilos_analytics_*` table from anywhere.

## The Rule

**The analytics tables are written by one agent, the cluster's writer, and by
nobody else.**
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
| HIL-1155 | the writer collects the files of every node |
| HIL-1156 | the master sends its connections, pages, actions and requests through its node's journal |
| HIL-1157 | a ceiling on the journal and an account of what was lost (not in the code yet) |

## The Chain

```
every process, including master ── Hilos::$ac ──► AnalyticsJournalOutbox
    ──(analytics_journal_append)──►
    AnalyticsJournalAgent (one per node) ── AnalyticsJournalDirectory: open file → ready file
        ◄──(analytics_journal_read / _portion / _loaded / _ready)──► AnalyticsWriterAgent (one per cluster)
            ── AnalyticsJournalLoader ──► AnalyticsStore ──► hilos_analytics_* tables
```

- **The source** is `AnalyticsCollector` (`Hilos::$ac`) in every process. Its
  worker methods describe sessions, signals, browser-session attachments and
  token changes. The master records WebSocket connections, their current pages,
  user actions and completed HTTP requests. It remembers only connection-to-page
  keys and correlation keys captured during synchronous dispatch; it has no
  analytics database access. Records gather in `AnalyticsJournalOutbox`. A batch
  leaves as one frame to the journal agent of the node once a second, at 64 KiB
  gathered, and at worker shutdown (`WorkerManager::cleanup()` sends it with the
  stop hooks' frames). The master queues its batch through its signal router,
  which `dispatchSignals()` delivers to the journal agent of that same node.
  A browser-session rename flushes at once. The payload is masked at the source
  ([../signals/dto-convention.md](../signals/dto-convention.md)): the file on the
  node's disk is storage too.
- **The journal agent** (`AnalyticsJournalAgent`, `AgentScope::NODE`, a monopolistic
  worker) is the one owner of the node's journal. It appends the lines of a batch
  to the open file at once, syncs the file to disk once a second when it was
  written to and at rotation, and rotates it at 1 MiB or 10 seconds after it
  opened. It answers the writer's reads with portions of whole lines of a ready
  file and deletes a file the writer confirmed. A rotation, whether caused by size
  or age, tells the writer that a ready file exists. So does a start that finds
  ready files from a previous life. It sends nothing on stop.
- **The writer** (`AnalyticsWriterAgent`, `AgentPlacement::POLICY`, a monopolistic
  worker) reads the node register (`hilosClusterNodes`) before start and asks all
  online nodes for ready files. A node waits in its queue after a ready notice or
  a meaningful online register change, until it answers that no ready file exists.
  The writer takes one file per node in ascending id order, wrapping round to the
  first; it reads that file in portions, loads it in one transaction and confirms
  it to the same node. An offline node or one silent for ten seconds keeps its
  files on disk while the writer moves on. A database failure pauses only that
  node for five seconds, doubling up to a minute, then retries its same file
  before any later file of that node. Quiet nodes cost no polling frames.

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
at the source, never at the writer. A `key`, `pageKey`, `userActionKey` or
`apiRequestKey` is 32 lowercase hex characters, drawn by the process
(`RandomHelper::hex(16)`) and stored as `UNHEX()` in its binary key column.

```
{"t":"journal","v":2,"node":"<nodeId|''>","openedTs":…}          first line of a file, by the journal agent
{"t":"worker_session","key","workerIndex","monopolistic","startedTs"}
{"t":"worker_session_stop","key","ts"}
{"t":"agent_session","key","workerKey","agentType","agentIndex","startedTs"}
{"t":"agent_session_stop","key","ts"}
{"t":"agent_user_action","agentKey","userActionKey","signal","payload","ts"}
{"t":"agent_system_signal","agentKey","signal","payload","ts"}
{"t":"agent_cron_signal","agentKey","cron","payload","ts"}
{"t":"worker_system_signal","workerKey","signal","payload","ts"}
{"t":"api_agent_action","apiRequestKey","agentKey","signal","payload","ts"}
{"t":"ws_connection_open","acceptKey","ip","ts"}
{"t":"ws_connection_close","acceptKey","ts"}
{"t":"ws_connection_ip_change","acceptKey","ip","ts"}
{"t":"page_session_open","key","acceptKey","page","params","ts"}
{"t":"page_session_update","key","params","ts"}
{"t":"page_session_close","key","ts"}
{"t":"user_action","key","acceptKey","pageKey","action","payload","ts"}
{"t":"api_request","key","sessionToken","method","path","params","userAgent","acceptLanguage","startedTs","status","durationMs","ts"}
{"t":"ws_connection_attach","acceptKey","sessionToken","userAgent","acceptLanguage","ts"}
{"t":"browser_session_rename","oldToken","newToken","ts"}
{"t":"browser_session_identity","sessionToken","identityType","identityValue","ts"}
```

The master's synchronous signal meta carries `userActionKey` or `apiRequestKey`;
an agent response carries that key in its journal record. A parked HTTP request
has no active capture while it waits. A payload is an object already masked, or
null; one that cannot be encoded travels as null. The master's `ip`, `pageKey`,
`params`, `sessionToken`, `userAgent`, `acceptLanguage`, `status` and `durationMs`
are nullable where their builders say so.

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
- A connection opening and its browser-session attachment upsert the same unique
  `accept_key`. Either may arrive first: the opening supplies the address and its
  moment; the attachment supplies `browser_session_id`. A page or user action
  whose connection is still unknown is skipped as `unknown_connection`.
- Page sessions, user actions and API requests upsert by process-drawn key;
  repeating their record in another file adds no row. A page update or close
  finds its key, and an address change compares the stored current address,
  including a previous change loaded in another file.
- Agent facts carry cause keys. Before each multi-row insert, the writer looks
  up known causes in one select per key column of that portion. An unknown cause
  leaves its fact's id column null while the fact stays. Inserting the cause
  later updates those facts by key where their id is null.
- A browser-session token is looked up as a session, then through up to eight
  stored aliases. A rename A→B records A as an alias even when A has no session.
  If A has a row and B does not, it renames A. If both have rows, it merges A
  into B: connections, requests and User-Agent and Accept-Language history move
  to B; first and last moments become min/max; B's identity and current header
  values win where present, otherwise A's. The writer rewires its cached token
  states before it can use an id of the deleted A row again. If A has no row,
  the first later event under either token opens B. No arrival order splits a visit.
- `last_seen_ts` of a browser session never moves back: the batches of different
  processes arrive out of order.
- What cannot be applied — the half line a machine crash left, an unknown type,
  an unknown session key, an unknown connection or a value too wide for its
  column — is passed over and counted, and the writer says it in one
  warning per file. A file is never refused for its records: one poisoned file
  would stop its node's journal for good.
- The numbers the store learns inside the transaction enter its cache only at the
  commit: a number born in a rolled-back transaction never names a row later.

## Across Nodes

Files of **one** node load in their numbered order. Between nodes there is no
load order: the writer visits waiting, online nodes in a round, one file per node.
The files of different nodes can cover overlapping time spans, each file is one
transaction, and their clocks can differ by milliseconds. Every cross-node pair
works in either order: opening ↔ session attachment, user action ↔ agent answer,
HTTP request ↔ agent action, and token rotation ↔ an event under the old token.
The second cause/answer record to arrive completes their link.

The writer learns nodes and connectivity from the node-local `hilosClusterNodes`
register, not from a file name or its own placement. On start it asks every online
node. A changed online row or a `analytics_journal_ready` notice makes that node
waiting again. A notice is not acknowledged or repeated; a writer restart, node
return, later rotation or journal-agent restart supplies another opportunity.
If a node falls offline during a read, the incomplete file is discarded and
read from zero after the node returns. A silent node gets one warning until it
answers again. Late portions from another node, file or offset are ignored.

A journal line may be at most 128 KiB. A larger payload is removed at its source
while its event stays; a record still too long without payload is dropped. A
legacy long line is read to its end by the journal agent, then omitted with one
warning for that portion. The limit keeps a portion safely inside the peer link's
8 MiB outgoing buffer even when an unbounded browser action carried the payload.

## The Freeze

Both agents are in the roster and the freeze stops them like any other agent. The
journal agent, stopped by the freeze, throws the node's whole journal away; the
writer starts again with empty caches. The collector is in no roster and answers
the freeze itself, in the master as in workers: while the node's row silences the
unstopped writers it records nothing and throws its gathered batch away, but the
master keeps its connection-to-page map. An agent stopped meanwhile has its
stop handed over, with its own moment, once the freeze lets go
([protected-mode.md](protected-mode.md)). `forgetReplacedDatabase()` discards the
batch and active captures and forgets the master's connections and pages; browsers
reconnect. Worker-session descriptions travel with every later batch.

## Stopping A Node

The journal agent is the last to leave. Every other worker hands it its last
batch on the way out — `WorkerManager::cleanup()` records the stops and sends the
batch with the stop hooks' frames — so a node stops in two waves (approved by the
owner 30.09.2026): SIGTERM to every worker but the journal's; once their processes
have exited and their connections are closed, the master records closures of its
remaining pages and connections and sends that batch in `dispatchSignals()` of the
same pass. The next pass stops the journal agent with an
ordinary `agent_stop` over its connection, behind everything sent to it before,
and closes its open file as ready and reports itself stopped; only then its worker gets SIGTERM. The daemon marks
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
- **A master after it releases the journal agent** loses events that finish in the
  last milliseconds of node shutdown, including a parked HTTP request abandoned
  after release. A fallen master loses its own unsent batch, up to a second.

Old journal records carrying row numbers are not loaded after this change; they
are counted as malformed. The owner settled compatibility on 03.10.2026:
«Нет. Когда будет продакшн какой-то, мы будем делать обратную совместимость, а пока нет.»
— “No. When there is some production, we will do backward compatibility; not yet.”

## What Is Not Here Yet

- The journal directory has no size ceiling, and nothing counts what was lost
  (HIL-1157).
