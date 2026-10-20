# Architecture: The Status Page

Read this before declaring Status components, changing the candidate and outage
machine, publishing its public files, building its admin section, or reading
`/status` without a connection or under a freeze.

This is the target architecture of HIL-1685, written ahead of implementation.
The names and public JSON shapes below are normative for the implementation
leaves. A future mechanism ends with its owning leaf's marker; this documentation
does not implement Status or close its design promises.

## Core Rule

The machine declares an outage from facts about expected agent instances. A
person may suppress publication or add public words, but cannot declare, close
or delete an outage. One cluster owner holds the durable history.
(not in the code yet — HIL-1688)

The public page reads one sanitized projection from nginx files, with or without
a usable socket. Neither an admin table mirror nor `page_response` supplies
that projection. File publication and its heartbeat continue under a freeze;
the database half obeys the freeze's silence.
(not in the code yet — HIL-1690)

Use the existing [Daemon picture chain](daemon-section.md#the-circulation),
[filesystem](filesystem.md), [protected mode](protected-mode.md) and
[time-zone canon](time-zones.md). They remain the sources for their mechanisms;
Status does not introduce another collector, storage transport or time helper.

## Components And Activation

Declare `HilosFeature::STATUS = 'status'`, requiring `DAEMON` and
`NOTIFICATION_DELIVERY` (and thus `NOTIFICATIONS`). Status is a framework-owned
[Mode 1 feature](admin-features.md): the project activates it, declares its
content and registers thin bindings; it does not copy the outage machine.
Activation is intended for all six demos. HIL-1686 and HIL-1687 prepare demos;
their preparation alone is not Status activation.
(not in the code yet — HIL-1688)

The project Hilos class declares `protected const array STATUS_COMPONENTS = []`
(`Hilos::STATUS_COMPONENTS`). Its catalog boundary has this shape:

```text
map<componentKey, {name: string, description: ?string, agentType: string}>
```

The key is stable; the name and optional description are public project content.
The agent type comes from the project's declared `AGENTS`. Keep the map at this
catalog boundary; the runtime reader uses a typed boundary, as required by
[Settings and catalogs](../framework-development.md#settings-and-catalogs).
An empty declaration means one component: key `service`, name `Service`, type
`HilosAgentType::HILOS_INDEX` — the project's index agent. An index agent is not
an `INDEXED` type. Only permanent, non-indexed types are supported in this
version. Refuse unknown types, `INDEXED` types and duplicate keys; do not turn
an invalid declaration into an operational component or accept groups of
per-object agents.
(not in the code yet — HIL-1688)

The admin section is `/hilos/status`, with Overview, Scheduled works,
Components, Subscribers and History tabs. Overview shows what is open now,
summary tiles and the publication delay; Components is read-only. The admin
surface keeps the `ADMIN` gate, including mutations forwarded to the owner.
Public reading does not turn those mutations into guest agent actions. Apply
[admin view mode](admin-view-mode.md): a viewer reads only allowed fields and
cannot mutate; subscriber addresses remain hidden through `_pii`.
(not in the code yet — HIL-1689)

The project also registers the shared Status directory, mounts the same volume
for every node's nginx, and binds the public page to the framework reader.
Neither a separate writer per node nor a project copy of the reader is an
activation mechanism.
(not in the code yet — HIL-1690)

## Facts And Ownership

The existing `DaemonCollectorAgent` and `DaemonPictureWatchSignalData` carry
whole node pictures through leased interest. The collector distinguishes no
initial picture, a reported empty roster, and a silent node. Scope and placement
are separate axes: `AgentScope::CLUSTER` or `NODE` determines multiplicity;
`AgentPlacement::LEADER` or `POLICY` chooses the host.

Status renews its own internal consumer lease even when no administrator has
the section open. The collector is its sole source for running instances and
silent nodes; the placement table and the page worker's mirror are projections,
not sources. Normalize observations into a continuous component fact before
writing a candidate. Repeated copies of the same fact must not create repeated
candidates or notifications.
(not in the code yet — HIL-1688)

Expected instances follow the declaration:

- `CLUSTER` expects one instance.
- `NODE` expects one on each node in the declared membership until that node
  is explicitly removed. A missing or silent node does not shrink the expected
  membership by itself.
- Before the first complete observation, missing data is unknown, not proven
  zero. Once absence is established, zero live instances means Major outage;
  fewer than expected but more than zero means Partial outage.
- Freeze means Maintenance. Instances stopped by that freeze do not become
  outage facts. There are no external probes, latency measurements or
  performance thresholds in this version.

(not in the code yet — HIL-1688)

Declare `HilosAgentType::HILOS_STATUS = 'hilos_status'` with
`AgentScope::CLUSTER` and `AgentPlacement::POLICY`, without an index or a
monopolistic worker. That one agent owns candidates, outages and their update
timeline. Restarting or moving it must preserve the continuous fact, detection
moment, publication deadline and suppression decision.
(not in the code yet — HIL-1688)

The same owner holds subscriber records.
(not in the code yet — HIL-1691)

The same owner holds scheduled works and the history of actual maintenance
periods. Their exact DB/RT entities, fields and action payloads belong to the
implementation interviews, as do those of candidates and subscribers; this
canon does not invent their storage schemas.
(not in the code yet — HIL-1692)

## Candidate And Outage

`status.publish_delay_seconds` is an integer Settings value, at least zero,
default `300`, named by `StatusSettingsCatalog::PUBLISH_DELAY_SECONDS`. Read
the current value when assigning a new candidate's durable `publishAt` from its
`detectedAt`. Changing the setting affects new deadlines immediately and moves
no deadline already assigned. It is a setting, not an environment value.
(not in the code yet — HIL-1688)

Each new continuous fact creates one candidate. It is public as Checking
immediately. Administrators immediately receive a bell notification and email
through the notification channel. The delay decides whether the candidate
becomes a published outage; it does not delay disclosure of Checking.
(not in the code yet — HIL-1688)

| Event | Durable transition and public result |
|---|---|
| Deadline reached, or **Publish now** | Atomically promote the same candidate to a published outage and append its first automatic update. |
| Fact ends before publication | End the candidate; remove Checking and retain the reason for the admin history. |
| **Do not publish** | Suppress publication for the entire current continuous fact. Keep Checking until the fact ends; retain the manual cancellation reason. A later timer pass cannot recreate the candidate for that same fact. |
| Fact ends after publication | Resolve automatically and append a Resolved update. |
| **Add an update** | Append the administrator's published words, labeled **from the team**. |

Serialize the timer and button through the owner so a race produces one
transition. Announce the event and enqueue its notification only after the
owner's durable write completes. Published outages and their updates are not
deleted; team updates cannot be edited away. No human action declares or closes
an outage.
(not in the code yet — HIL-1688)

Only confirmed subscribers receive publication, team-update and resolution
mail. They receive no candidate mail, including for a short candidate or a
manually suppressed publication. A repeated observation or competing timer and
button must not send the same notification twice.
(not in the code yet — HIL-1691)

Admin history retains ended candidates with their reasons, published outages
and completed works. A short or suppressed candidate never remains in public
history.
(not in the code yet — HIL-1693)

### Subscribers And Scheduled Works

Subscription needs no account: an address receives a confirmation email and
joins delivery only after confirmation. Mail carries an unsubscribe link; an
administrator can remove a subscriber. Throttle by address and IP. Keep all
addresses, confirmation/unsubscribe tokens and session time zones out of the
public files. Subscriber delivery uses the universal recipient-zone choice in
[Server Text And Human-Readable Exports](time-zones.md#server-text-and-human-readable-exports)
(HIL-1578), not a Status-specific time-zone policy. Exact records and guest
action forms belong to the subscriber implementation.
(not in the code yet — HIL-1691)

Scheduling, changing or canceling works publishes the announcement change and
sends mail. Schedule clocks never enter or leave protected mode. Actual work
starts with the freeze: it is announced in advance when the freeze begins
inside the work's window, and unannounced otherwise. It continues if the freeze
outlasts the scheduled end. When the freeze ends, complete the period and send
Completed through delivery when permitted. Full freeze does not permit durable
delivery merely because Status still publishes files.
(not in the code yet — HIL-1692)

## Static Publication

Reserve `FsContext::STATUS = 'status'` with `DirectoryScope::CLUSTER`, reached
as `Hilos::$fs->status`. This is a logical directory name, not a physical path;
the installation chooses the shared disk volume. The one Status owner publishes
the sanitized projection on change and a heartbeat independently of visitors.
(not in the code yet — HIL-1690)

Use existing `FsPath::publish()` to publish each complete file atomically from
a temporary file on that same volume. The public names are exactly
`status.json` and `heartbeat.json`; their filename constants belong to the
implementation. Publish in this order: whole `status.json`, then whole
`heartbeat.json` with the same revision, then the change hint. A heartbeat-only
pass updates `aliveAt` and sends the hint while retaining the last successfully
published content revision. A write failure must not manufacture a fresh
heartbeat for an unsuccessful publication.
(not in the code yet — HIL-1690)

### Public JSON v1

These are JSON shape declarations, not PHP/TS implementation types. All keys
are present: nullable fields serialize as `null`, lists as `[]` when empty.
Every moment below — including `at`, `startsAt` and `endsAt` — is an integer
of epoch milliseconds UTC, following [A Moment On The Wire](time-zones.md#a-moment-on-the-wire).
Every `id` is opaque. `revision` is a unique opaque content revision without a
node id or term; the heartbeat repeats the last successfully published
`status.json` revision. The file contract and serialization are future work.
(not in the code yet — HIL-1690)

```text
status.json = {
  schemaVersion: 1,
  revision: string,
  generatedAt: int,
  config: {pollIntervalSeconds: int, staleAfterSeconds: int},
  components: Component[],
  candidates: Candidate[],
  outages: Outage[],
  scheduledWorks: Work[],
  maintenancePeriods: MaintenancePeriod[]
}
heartbeat.json = {schemaVersion: 1, revision: string, aliveAt: int}

Component = {
  componentKey: string, name: string, description: ?string,
  state: 'operational' | 'checking' | 'partial_outage' |
         'major_outage' | 'maintenance' | 'unknown'
}
Candidate = {componentKey: string, detectedAt: int}
Outage = {
  id: string, componentKey: string,
  severity: 'partial_outage' | 'major_outage',
  detectedAt: int, publishedAt: int, resolvedAt: ?int, updates: Update[]
}
Update = {at: int, source: 'automatic' | 'team', text: string}
Work = {
  id: string, title: string, description: ?string, componentKeys: string[],
  startsAt: int, endsAt: int
}
MaintenancePeriod = {
  id: string, workId: ?string, title: string, componentKeys: string[],
  startedAt: int, endedAt: ?int
}
```

(not in the code yet — HIL-1690)

Apply these publication limits:

- Components retain project declaration order. `agentType` is admin-only.
- Candidates contain current Checking only, with no internal id, publication
  deadline or manual cancellation reason.
- Outages contain published open outages and history intersecting the last
  90 days. Automatic update text is safe public wording selected by fact code;
  team text is the administrator's published wording.
- Works contain upcoming announcements and those belonging to current works;
  canceled announcements are not public.
- Maintenance periods contain active periods and completed periods intersecting
  the last 90 days. `workId: null` means unannounced works; `title` is a public
  label. Their boundaries follow the actual freeze, not schedule clocks.
- Never publish subscribers, email addresses, tokens, session time zones, PIDs,
  node ids/IPs, raw Daemon pictures, private error or exception text, or hidden
  candidates in history. Version, config and revision exist only to read the
  public projection correctly.

(not in the code yet — HIL-1690)

### Intervals, Hint And HTTP Addresses

| Environment constant | Type | Default |
|---|---|---|
| `EnvConstants::STATUS_POLL_INTERVAL_SECONDS` | integer > 0 | 30 seconds |
| `EnvConstants::STATUS_STALE_AFTER_SECONDS` | integer > 0 | 120 seconds |

Publish both values in `status.json.config` so a cold page learns them without
the daemon. The heartbeat interval is `min(30 seconds, staleAfterSeconds / 3)`.
Its 30-second ceiling is a separate named publisher constant, not a reuse of
the polling env value. Defaults yield a heartbeat every 30 seconds; a smaller
stale threshold requires a faster heartbeat. This derived internal cadence is
not a third env key and does not change the public shape. An nginx response
never updates `aliveAt`.
(not in the code yet — HIL-1690)

Declare `HilosSignalConstants::STATUS_FILES_CHANGED = 'status_files_changed'`
with `Hilos\Status\DTO\StatusFilesChangedSignalData`, payload `{}` (no fields).
It is only a hint that publication succeeded: no status, error, address or file
path travels in it. Use existing `sendToAllConnected()` / `WS_ALL_CONNECTED` so
a guest on `/status` receives it without a page subscription; the active Status
reader rereads the files, and other pages ignore it. Do not add a group or
subscription channel. Heartbeat publication sends this hint too, including when
the component state has not changed.
(not in the code yet — HIL-1690)

The public client route is `/status`. nginx serves the built project shell
there, plus exactly two public GET addresses:

- `/status-data/status.json`;
- `/status-data/heartbeat.json`.

Use explicit location allowlists and `Cache-Control: no-store`. Do not expose
directory listings, temporary or hidden files. The existing mandatory
`.hilos-cluster-directory.json` marker stays private: its node identifier is
required for the cluster guard, not public Status data. This restriction does
not remove or change that marker. Every node's nginx reads the same files.
Do not add a backend-fetch endpoint for the projection.
(not in the code yet — HIL-1690)

## Reading /status

On entry, always read the nginx files. Render their contents without waiting
for daemon HTTP, an API, DB, handshake or `page_response`. Accept only a pair
with matching `revision` and supported `schemaVersion`; reread on a publication
race instead of mixing revisions. File mtime, a successful nginx response and
the arrival of a hint cannot turn the last coherent projection into a new one.
(not in the code yet — HIL-1690)

With a usable live connection, reread the same files after every change hint.
Without one, including a connection held by freeze, poll at the file's
`pollIntervalSeconds` (default 30 seconds). On recovery of usable live
connection, reread immediately. Maintain the local heartbeat-age timer even
with a live socket, so a stopped publisher is detected independently of
transport health.
(not in the code yet — HIL-1690)

A fresh heartbeat with a broken socket says **system is up**; it does not erase
an outage or Maintenance in the projection. Once `aliveAt` is older than
`staleAfterSeconds` (default 120 seconds), say **not responding since**
`<aliveAt>` and show components as Unknown. Stale operational data is not proof
of current health. Empty, malformed or missing files mean no trustworthy current
picture; keep the last valid history if available, but invent no Operational
verdict. Without a first valid snapshot, the verdict is Unknown.
(not in the code yet — HIL-1690)

The public surface shows an overall banner, components, current outages and
their timelines, upcoming works and history. Derive the 90-day strip (30 days
in the narrow view) and public history from published outages and actual
maintenance intervals. Each day shows its worst published fact; candidates do
not color days or remain in public history. Show no SLA percentage. Use the
shared time mechanism for calendar boundaries and the core formatter in the
reader's zone, including the browser's zone for a guest; do not reproduce the
mockup's UTC screen labels (D-248). The [time-zone canon](time-zones.md) owns
zone selection and formatting, including recipient-zone mail.
(not in the code yet — HIL-1690)

## Freeze And Disconnection

`/status` is the narrow exception to subscription waiting, the freeze stub,
disconnect blocking and the ordinary stale-data screen. It remains readable on
a cold load during full freeze; the maintenance stub links to it as **Service
status**. A disconnected shell may indicate the break, but cannot cover the
file reader with a blocking overlay. Other pages keep their existing rules.
(not in the code yet — HIL-1690)

Do not show the subscription button without a usable live connection. Public
file reading grants no offline mutation path or permission to bypass a freeze
for subscription actions.
(not in the code yet — HIL-1691)

The publisher's file half must survive full freeze, or normal Maintenance would
become “not responding” after the stale threshold. Its narrow exception covers
both `WorkerServer::protectedModeRefusesStart()` and
`WorkerServer::stopAgentsForProtectedMode()`. Today their continuing-pool
exception is `HILOS_MAIL`; Status is not yet excepted. Follow that raw-mail
precedent only for public projection and heartbeat work, with no new master
I/O and no child/background process.
(not in the code yet — HIL-1690)

While `silencesUnstoppedWriters()` is true, keep only the sanitized public
snapshot and the freeze fact needed for publication. Do not read or write DB,
serve DB actions or handle durable delivery. Discard SQL caches and other
memory tied to the replaced database. After silence lifts, reacquire permitted
DB data and a fresh Daemon picture before resuming normal observation; the
stops caused by freeze must not produce outages. This is no exception for
ordinary ORM writes.
(not in the code yet — HIL-1690)

Serving these files is independent of the daemon, not of nginx or its machine.
If nginx or the whole host is unavailable, the page cannot open. External
hosting and external monitoring are outside this feature.

## Implementation Status And Leaf Owners

Existing foundations are the Daemon collector and watch DTO, scope/placement
declarations, `DirectoryScope::CLUSTER`, `FsPath::publish()` and the private
cluster-directory guard. They are not evidence that Status is implemented.

| Work | Owner and status |
|---|---|
| Activation, component catalog, status owner, candidate/outage machine | (not in the code yet — HIL-1688) |
| Admin section and its gates | (not in the code yet — HIL-1689) |
| Public files, heartbeat, hint, nginx routes, reader and freeze publisher | (not in the code yet — HIL-1690) |
| Subscribers and their mail | (not in the code yet — HIL-1691) |
| Scheduled works, actual maintenance periods and Completed delivery | (not in the code yet — HIL-1692) |
| Admin history | (not in the code yet — HIL-1693) |
| Cluster and failover proof | (not in the code yet — HIL-1694) |
| React UI parity, including public freeze/disconnection behavior | (not in the code yet — HIL-1695) |
| Angular UI parity, including public freeze/disconnection behavior | (not in the code yet — HIL-1696) |

PR-133 through PR-135 and PR-204 through PR-209 remain the code leaves' design
promises. HIL-1685 neither implements nor closes them. Exact storage schemas,
admin and guest action DTOs, and frontend page registry keys remain with their
implementation interviews and the ordinary contract approval gate. Remove a
future marker only in the commit that lands its behavior, following
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Validation

For this documentation, run
`composer run test:framework:unit -- --filter AgentDocGuardTest`, then
`composer ai:install` and `composer ai:install:check`, through the prescribed
test environment. Check `git diff --check` and walk the index and skill links.
No new text test or full frontend/e2e/cluster run is needed for this text layer.

Review all eight contract topics against this canon: feature, component
declaration, setting, agent type, directory, file shapes, hint, and env/routes.
Check markers, privacy, D-248 and the narrow exceptions against the subscription
and freeze rules. Future code validation belongs to the leaf that implements
each mechanism; cluster and failover evidence belongs to HIL-1694.
