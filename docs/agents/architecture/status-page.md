# Architecture: The Status Page

Read this before declaring Status components, changing the candidate and outage
machine, publishing its public files, building its admin section, or reading
`/status` without a connection or under a freeze.

This document records the Status backend built by HIL-1688 and the admin
surface built by HIL-1689. Public files and their reader, subscribers and mail,
scheduled work and completed history, separate cluster/failover proof, and the
React and Angular views remain with HIL-1690 through HIL-1696 where their
markers say so.

## Core Rule

The machine declares an outage from facts about expected agent instances. A
person may suppress publication or add public words, but cannot declare, close
or delete an outage. One cluster owner holds the durable history.

The public page reads one sanitized projection from nginx files, with or without
a usable socket. Neither an admin table mirror nor `page_response` supplies
that projection. File publication and its heartbeat continue under a freeze;
the database half obeys the freeze's silence.
(not in the code yet — HIL-1690)

HIL-1688 builds the framework feature, component catalog, collector consumer,
durable incident machine, candidate notices, internal owner operations and a
typed backend read projection. HIL-1689 adds the Overview and Components admin
pages, the read-only component viewport, the Overview's incident actions and
the Vue views mounted by Chat and Binance BTC Tracker. Later leaves own public
files, the public reader and the publisher's freeze exception (HIL-1690);
subscribers or their mail (HIL-1691); scheduled works or actual maintenance
history (HIL-1692); the admin history UI (HIL-1693); separate cluster/failover
proof (HIL-1694); and React and Angular views (HIL-1695 and HIL-1696).

Use the existing [Daemon picture chain](daemon-section.md#the-circulation),
[filesystem](filesystem.md), [protected mode](protected-mode.md) and
[time-zone canon](time-zones.md). They remain the sources for their mechanisms;
Status does not introduce another collector, storage transport or time helper.

## Components And Activation

`HilosFeature::STATUS` is a framework-owned [Mode 1 feature](admin-features.md):
the project activates it and declares its component content; it does not copy
the outage machine. `StatusFeature` requires `DAEMON` and
`NOTIFICATION_DELIVERY`, the `HILOS_STATUS` agent, the `StatusSettingsCatalog`
fragment, the `hilos_status_incident` and `hilos_status_incident_update`
tables, both Status pages, `HilosStatusComponentsTable`, and the
Components-to-table binding. It mounts the feature-owned runtime collection.

### Activation Recipe

In the project's `Hilos::FEATURES`, include `HilosFeature::STATUS`. Register the
framework pair in `Hilos::AGENTS` under `StatusAgent::AGENT_TYPE`:

```php
StatusAgent::AGENT_TYPE => [
    AgentRegistryKey::WORKER => StatusAgent::class,
    AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
    AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
    AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
],
```

This is one cluster owner on a regular worker: it is non-indexed, has no idle
timeout, and does not require a monopolistic process. Scope determines how many
instances a component expects; placement determines which node hosts the owner.
Merge `StatusSettingsCatalog::getCatalog()` into the project's settings catalog.
Then run `db:framework:pull` for that project. It copies the missing primary
schema migration pairs from the framework's `status_incident` and
`status_incident_update` units; the second unit depends on the first. The pull
does not apply SQL. Commit the generated pairs with the project and apply them
through the ordinary `db:migration:up` command. Do not hand-write or copy these
migrations.
All six demos in this repository activate the feature, register the pair and
merge the settings fragment; their schema pairs came from the same framework
pull. Each also registers thin project subclasses for both Status pages,
registers `HilosStatusComponentsTable`, and binds that table only to the
Components page in `PAGE_TABLES`. Feature activation validates these
requirements at startup.

The project Hilos class declares `protected const array STATUS_COMPONENTS = []`
(`Hilos::STATUS_COMPONENTS`). Its catalog boundary has this shape:

```text
map<componentKey, {name: string, description: ?string, agentType: string}>
```

The key is stable; the name and optional description are public project content.
The agent type comes from the project's declared `AGENTS`. Keep the map at this
catalog boundary; `Hilos::statusComponents()` and
`Hilos::statusComponentsOf(class-string<Hilos>)` expose it as a typed
`StatusComponentCatalog`, following
[Settings and catalogs](../framework-development.md#settings-and-catalogs).
An empty declaration
means one component: key `service`, name `Service`, type
`HilosAgentType::HILOS_INDEX` — the project's index agent. An index agent is not
an `INDEXED` type. Only permanent, non-indexed types with no idle timeout are
supported. Each entry must contain exactly `{name, description, agentType}`;
the key matches `[a-z][a-z0-9_.-]{0,127}`, the name is nonblank UTF-8 of 1 to
255 characters, the description is null or UTF-8, and the agent type is
nonempty and registered. The catalog refuses duplicate keys, unknown types,
indexed types and idle types. Scope and placement are resolved from the agent
registry and remain separate axes.

### Admin Pages

The built admin section has two routed pages: `hilos_status` at `/hilos/status`
and `hilos_status_components` at `/hilos/status/components`. Both have empty
route params, use `PageReach::ROUTE`, and inherit the `ADMIN` access level from
`AbstractHilosPage`. `Status` is a child of Dashboard and is opted into the
shared page head with label and breadcrumb `Status`, icon `bi-activity`, and
lead `A public status page: service health, incidents, and planned work.`
`Components` is its child and the only other current tab. Scheduled works,
Subscribers and History are not admin pages in this build.

Both first renders use the existing `page_response`. Overview data is
`page_response.data.status`, assembled temporarily by
`StatusOverviewData::read()` from the catalog, current component counts, open
incidents and their updates, the publication-delay setting, and protected mode:

```text
status = {
  healthKnown: bool,
  components: {operationalCount: int, totalCount: int},
  publishDelaySeconds: int | null | HiddenValue,
  maintenance: null | {
    phase: string, operation: string | null, startedAt: int | null
  },
  incidents: null | Incident[]
}
Incident = {
  incidentId: int, componentKey: string, componentName: string,
  agentType: string, severity: 'partial_outage' | 'major_outage',
  detectedAt: int, publishAt: int, publishedAt: int | null,
  suppressedAt: int | null, expectedInstances: int | null,
  liveInstances: int | null, observedAt: int | null, updates: Update[]
}
Update = {
  id: int, kind: 'published' | 'severity_changed' | 'resolved' | 'team',
  severity: 'partial_outage' | 'major_outage',
  text: string | HiddenValue, at: int
}
```

Nullable keys are present. `incidents: []` means no open facts are known;
`incidents: null` means their DB source is unavailable. All moments are epoch
milliseconds UTC, including `maintenance.startedAt`, which converts the
existing protected-mode seconds once when the DTO is built. Incidents follow
component declaration order; each update timeline is newest first by `(at, id)`.
`operationalCount` counts only components whose state is strictly
`operational`. Unknown counts or facts never become healthy or zero, and
maintenance takes display priority while available incident cards remain
visible. Only open incidents and their own updates are read; there is no ended
history, subscriber data, delivery count or public-file data in this payload.

`StatusIncidentData` and `StatusIncidentUpdateData` build only the open incident
and its own timeline projection. The DTO uses `WireField::column()` for
persisted origins, `WireField::setting()` for the delay, and
`WireField::notPersonal()` for computed/catalog/runtime fields. The table
likewise marks only its catalog and computed fields nonpersonal. In admin view
mode, personal incident-update text becomes `HiddenValue`; the row and timeline
remain. Viewer permissions and hidden-field behavior follow
[admin view mode](admin-view-mode.md).

The two `BROWSER.SIGNAL` declarations use
`subscription_page_hilos_status` and
`subscription_page_hilos_status_components`. These are metadata names, not
additional frames: page state still arrives in the existing `page_response`.

The Components page's first viewport is
`page_response.windows.hilosStatusComponents`. Its row key is `componentKey`;
`slots.component` carries `componentKey`, `name`, nullable `description`,
`agentType`, and one of `operational`, `checking`, `partial_outage`,
`major_outage`, `maintenance`, or `unknown`. The read-only
`MultiRowViewportTable` uses a server window of 25 rows in declaration order,
searches `name`, `description` and `agentType`, and offers the `state` facet.
It declares no sortable fields or sort orders. Because the common empty sort
whitelist currently means no opinion, the table removes an unsupported sort
locally in `getPage()` and logs a warning; it does not change the shared sort
API. Its counts, incidents and freeze dependencies are declared through
`ROWS`, so ordinary multi-row deltas, pending/Apply and source-staleness
behavior apply.

The three write actions — `hilos_status_cancel_publication`,
`hilos_status_publish_now`, and `hilos_status_add_update` — belong to Overview
and are not reading actions. The page keeps their browser action names and
`ADMIN` gate, then forwards typed asks to the existing Status owner with
`HandoverGatekeeperTrait`. All three use the page-owned
`hilos_status_write_done` reply route and `HandoverAnswerSignalData`; the
first two use `HilosStatusIncidentActionDTO {incidentId: int > 0}`, while Add
update uses `HilosStatusAddUpdateActionDTO {incidentId: int > 0, text: string}`.
The tracked action's `requestId` travels in the existing envelope, not these
DTOs. The exact `incidentId` target is preserved, and the page acknowledges
only after commit or refusal. Components declares no actions. The six thin
project bindings use the existing `HILOS_INDEX`; there is no new agent type. No
DB/RT fields or truth-source ownership changed; the new action and reply
declarations reuse existing page and agent signal envelopes.

Overview source changes use the existing guarded `PageResender` /
`resendPageWhole()` path. The narrow `hilos_status` branches in
`BrowserContext::addBrowserChange()` and `addBrowserStaleness()` reuse the
subscription guards and `publishedWholeThisFlush`; they add no timer, cache or
generic fan-out. Components remains a separate page subscription, so an
Overview re-answer does not reset its viewport.

The framework's core module binds `page_response.data.status` through strict
schemas and the existing `pageDataSignal`; it performs no mount fetch. Its
state and incident controllers retain the captured `incidentId`, draft, busy,
refusal and timeout lifecycle. A live update cannot retarget the dialog to a
new incident for the same component. A suppressed candidate remains `Checking`
with “Publication canceled manually”; reaching countdown zero does not publish
it or create a server fact. Moments use `formatMoment()` in the reader's zone;
the countdown uses the server clock through `toLocal()` and
`formatCountdown()`.

The SDK exports the implementation lazily from `@hilos/core/admin/status` and
`@hilos/vue/admin/status`, while keeping compatibility-root exports. Status is
not imported by either package's eager `base` entry or Vue's default
`hilosAdminViews()` map. Chat and Binance BTC Tracker mount thin Vue wrappers
over the framework pages with their existing scope, connection and action
singletons. React and Angular intentionally remain unbuilt until HIL-1695 and
HIL-1696.

The project also registers the shared Status directory, mounts the same volume
for every node's nginx, and binds the public page to the framework reader.
Neither a separate writer per node nor a project copy of the reader is an
activation mechanism.
(not in the code yet — HIL-1690)

## Facts And Ownership

The existing `DaemonCollectorAgent` and whole-node picture DTOs carry node
pictures through leased interest. Status has its own permanent consumer pair:
`status_daemon_picture_watch` and `status_daemon_picture_portion`. Its owner
renews a 90-second collector lease every 30 seconds and retries the first full
snapshot every second. The lease remains active with no browser viewers. The
collector is its sole source for running instances and silent nodes; the
placement table and a browser/page mirror are not sources.

`StatusAgent` keeps the whole picture, baseline, freshness flags, cursor and
deadline cursor in private rebuildable memory. A new owner first establishes a
full-snapshot baseline; online process facts count only after a usable report is
newer than that node's baseline. Offline membership proves absence, but an
online node with no process roster or no fresh report makes the observation
unknown. Foreign report clocks are not compared with the Status owner's local
clock. A `NODE` component expects one slot per member, including offline
members; duplicate copies on one node do not fill another node's slot. A
`CLUSTER` component expects one instance.

Expected instances follow the declaration:

- `CLUSTER` expects one instance.
- `NODE` expects one on each node in the declared membership until that node
  is explicitly removed. A missing or silent node does not shrink the expected
  membership by itself.
- Before the first complete observation, missing data is unknown, not proven
  zero. Once absence is established, zero live instances means Major outage;
  fewer than expected but more than zero means Partial outage.
- An empty node set is still unknown; zero is established from known offline
  member slots, not from having no member picture.
- Freeze means Maintenance in the typed projection. The owner stops observation,
  DB writes and notification delivery during every occupied freeze phase, so
  instances stopped by that freeze do not become outage facts. Start and lift
  both require a new full/fresh baseline. There are no external probes, latency
  measurements or performance thresholds in this version.

`HilosAgentType::HILOS_STATUS` uses `AgentScope::CLUSTER` and
`AgentPlacement::POLICY`, without an index, idle timeout or monopolistic
worker. That one agent owns candidates, outages and their update timeline.
Restarting or moving it preserves the continuous fact, detection moment,
publication deadline and suppression decision in the primary database; it
rebuilds observations from a fresh full picture.

The same owner holds subscriber records.
(not in the code yet — HIL-1691)

The same owner holds scheduled works and the history of actual maintenance
periods. Their exact DB/RT entities, fields and action payloads belong to the
HIL-1692 implementation interview; this canon does not invent their storage
schema.
(not in the code yet — HIL-1692)

## Candidate And Outage

`status.publish_delay_seconds` is the integer setting named by
`StatusSettingsCatalog::PUBLISH_DELAY_SECONDS`: nonnegative, default `300`.
`StatusAgent` reads
`Hilos::$setting[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]->int()` when it
creates a candidate and persists `publish_at` from that detection moment. A
settings change affects new deadlines only; it does not move an existing
deadline. The overflow guard refuses an unsupported deadline.

### Durable And Runtime State

`hilos_status_incident` and `hilos_status_incident_update` are non-journaled
tables on the primary database. With `STATUS` enabled, the framework mounts
their full ORM chains at `Hilos::$db->statusIncidents` and
`Hilos::$db->statusIncidentUpdates` using `LAZY_STRATEGY_KEY`. The incident has
a positive integer primary id, `component_key`, its detection-time
`component_name`, nullable `active_component_key`, current `severity`, nullable
`peak_severity`, `detected_at`, `publish_at`, nullable `published_at`,
`suppressed_at`, `resolved_at` and `outcome`. A unique nullable
`active_component_key` permits only one open record per component. Resolving
the fact clears that key in the same item write, allowing a later absence to
create a new id. These internal integer ids do not change the future public
JSON contract's opaque string ids.

Each update row stores `incident_id`, `kind`, `severity`, immutable `text`,
millisecond `at` and nullable `request_id`. The incident-to-updates relation is
queried directly by its foreign key, backed by an
`(incident_id, at, id)` timeline index; tracked request ids have a unique
`(incident_id, request_id)` index. Request replay uses the bounded
`Hilos::$db->statusIncidentUpdates->forRequest($incidentId, $requestId)` lookup.
Do not load all incident history through a lazy full-table read.

The DB write API is:

```php
Hilos::$db->statusIncidents->actions->candidate(
    $componentKey, $componentName, $severity, $detectedAt, $publishAt,
);
Hilos::$db->statusIncidents[$id]->actions->suppress($at);
Hilos::$db->statusIncidents[$id]->actions->publish($at);
Hilos::$db->statusIncidents[$id]->actions->changeSeverity($severity);
Hilos::$db->statusIncidents[$id]->actions->resolve($at);
Hilos::$db->statusIncidentUpdates->actions->append(
    $incidentId, $kind, $severity, $text, $at, $requestId,
);
```

The append takes `StatusUpdateKind`, `StatusSeverity` and `StatusUpdateText`.
These actions join the transaction opened by `StatusAgent`; the owner alone
claims incident Add and Update and timeline Add. It finds the current incident
with `whereColumnIn(EntityStatusIncident::active_component_key, ...$portionKeys)`
and reads a known id by collection offset. The typed `$incident->updates`
relation reads that incident's timeline by foreign key. Do not add a repository,
service or owner-side SQL path.

The feature-owned `Hilos::$rt->hilosStatusComponents` collection carries only
`componentKey`, `expectedInstances`, nullable `liveInstances` and nullable
`observedAt`. Null means unknown, not zero. The framework feature mounts the
collection and its representation/actions. Its owner uses
`$collection->actions->ensure($componentKey, $expectedInstances)` and
`$component->actions->update($expectedInstances, $liveInstances, $observedAt)`;
`StatusAgent` is the only writer. Per-component DB reconciliation and these RT
writes share one primary transaction, including the automatic timeline append. A failed
transaction rolls back the RT and ORM memory too. Each pass handles at most 32
components; a durable publication deadline schedules a pass even without a
new Daemon picture.

`StatusSnapshot::read()` is the existing typed backend projection. It reads the
ordered component catalog and unconditional protected-mode runtime row. With
`STATUS` enabled and sources available, it reads current component RT rows and
current open incident DB rows selected by
`whereColumnIn(active_component_key, ...catalogKeys)`. Counts are usable only while
the RT item's `staleSince()` is null. Its
`StatusComponentSnapshot` entries expose the component, `StatusComponentState`,
expected/live counts, `observedAt` and optional DB incident. The enum states
are `OPERATIONAL`, `CHECKING`, `PARTIAL_OUTAGE`, `MAJOR_OUTAGE`, `MAINTENANCE`
and `UNKNOWN`. An unpublished open incident projects as `CHECKING`; a published
incident projects its current severity. Without an incident, fresh counts at or
above expected project `OPERATIONAL`. Its top-level `incidents` is `null` when
DB facts are unavailable and `[]` when none are known. A reader must declare DB
and RT source interest through the existing
[truth-source mechanism](truth-source.md) before calling it. While
protected mode silences unstopped writers,
it does not read the incident DB; every occupied freeze phase overlays each
component as Maintenance.
This projection is not a public file format or a ready `/status` page.

### Continuous Fact And Timeline

Each new continuous abnormal observation creates one durable candidate with a
fixed detection moment and publication deadline. `StatusSnapshot::read()` can
project an available unpublished incident as `CHECKING`; the public page/file
surface is still future work. When live count is zero the current severity is
Major; a positive count below expected is Partial. Partial↔Major changes update
the same open record. Until publication they do not raise `peak_severity`; on
publication the peak starts at the then-current severity. After publication,
the peak records the greatest proven severity and a severity change appends one
automatic `severity_changed` update. Automatic text is `Partial outage.`,
`Major outage.` or `Resolved.` according to the transition.

| Event | Durable transition and typed projection |
|---|---|
| Deadline reached, or **Publish now** | Promote the same open candidate and append its one automatic `published` update in the same transaction. |
| Fact ends before publication | End the candidate with `recovered_before_publication`; append no timeline update. |
| **Do not publish** | Set `suppressed_at`. Keep the same candidate and its Checking projection until complete recovery; retain suppression for the whole continuous fact. |
| Suppressed fact recovers | End it as `canceled_publication`; a later timer cannot recreate it while the fact remains continuous. |
| Fact ends after publication | Resolve it and append one automatic `resolved` update in the same transaction. |
| **Add an update** | Append immutable team text with kind `team` to an open published incident. |

Recovery means every expected slot is healthy. The item's `resolve()` records
the outcome and releases the active key in one write. Thus a new absence after
full recovery receives a new incident id. The three durable outcomes are
`recovered_before_publication`, `canceled_publication` and `resolved`. Automatic
timeline kinds are `published`, `severity_changed` and `resolved`; unpublished
candidates have no timeline rows. Timer publication and **Publish now** use the
same idempotent publication path, so either order creates one first update.

The owner receives three internal `AGENT_SIGNALS`: the
`HilosSignalConstants::STATUS_CANCEL_PUBLICATION` signal
(`StatusCancelPublicationSignalData`), `HilosSignalConstants::STATUS_PUBLISH_NOW`
(`StatusPublishNowSignalData`) and `HilosSignalConstants::STATUS_ADD_UPDATE`
(`StatusAddUpdateSignalData`). The first two carry `incidentId`, `replySignal`,
`acceptKey`, nullable `requestId`, `action` and nullable `successMessage`; the
last also carries `text`. Nullable keys remain present with `null`; request ids
hold at most 128 characters. They implement `HandoverAskInterface`. The owner
answers with `HandoverAnswerSignalData::to(...)` only after the transaction
commits or the owner has a refusal. In a freeze or without runtime state, the
owner refuses before DB access. The target is the internal positive integer
incident id, never a component key. These asks are not browser `ACTIONS`,
`AGENT_ACTIONS`, `AUTH_ACTIONS` or client-signal routes. The Overview page
remains the ADMIN gatekeeper and owns the browser action names and reply route
described in [Admin Pages](#admin-pages).

Cancel is idempotent for an open unpublished incident; a published or ended
incident refuses. Publish-now is idempotent for an open already-published
incident, and refuses a suppressed or ended one. A team update requires an open
published incident. `StatusUpdateText` trims plain UTF-8 text and accepts 1 to
5000 Unicode characters. A tracked request id replay with the same text returns
the existing update; replay with different text refuses. A null request id is
untracked and is not assigned an invented correlation id.

### Candidate Notices

After a new candidate commits, `Database::afterCommit()` queues one typed
`StatusCandidateNotice` in the owning agent. The queue drains at most 32 drafts
per tick, including while the machine has no new picture. For each recipient it
uses `Hilos::adminAudienceClass()::all()` and `Hilos::$notify->emit()` through
the existing notification library. The warning type is
`status.possible_outage`, the title is `Possible outage: <name>`, and the body is
`Detected at <detected>. Publication scheduled for <deadline>. See /hilos/status.`
It narrows channel delivery to email while retaining the normal bell
notification and notification policy (`channels=[email]`). That link resolves
to the built Status Overview page.

`StatusCandidateNotice::draftFor(userId)` formats both moments with
`TimeHelper::serverTextTimeZone(userId)` and `Y-m-d H:i:s P (e)`; stored and
wire moments remain epoch milliseconds. Its data contains the URL, internal
integer `incidentId`, `componentKey`, `detectedAt` and `publishAt`, with no
personal or node data. The queue and recipient cursor are private best-effort
work, not a durable outbox or exactly-once promise. Stop and freeze clear them;
an attempted recipient is consumed before emit. A delivery failure after commit
does not roll back or recreate the candidate, and repeated frames, ticks,
restarts or severity changes do not enqueue another candidate notice.

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
(HIL-1578), not a Status-specific time-zone policy. For a no-account recipient
with a saved browser zone, select it with
`TimeHelper::serverTextTimeZone(browserTimeZone: $savedBrowserTimeZone)` and
render the text with the selected zone's offset and name. Exact records and
guest action forms belong to the subscriber implementation.
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
cluster-directory guard. Those foundations alone are not the Status
implementation; the backend core below landed with HIL-1688, while later
surfaces remain separately owned.

| Work | Owner and status |
|---|---|
| Framework activation, component catalog, status owner, DB/RT machine, candidate notices and typed backend read | Landed by HIL-1688 |
| Admin Overview and Components pages, read-only viewport, gatekeeper actions, shared page head and Vue views | Landed by HIL-1689; React and Angular views remain with HIL-1695 and HIL-1696 |
| SDK packaging | `framework/frontend/core/test/packaging/coreEntryPoints.test.ts`, `framework/frontend/core/test/bin/initialBundleBudget.test.mjs`, `framework/frontend/vue/test/packaging.smoke.mjs` | Core's 14-section entrypoint set includes Status; the current Core and Vue manifests export `/admin/status`, with compatibility-root identity. The built base closures stay free of Status, and Vue's default view map does not eagerly include it. React and Angular have no Status view entry yet. |
| Public files, heartbeat, hint, nginx routes, reader and freeze publisher | (not in the code yet — HIL-1690) |
| Subscribers and their mail | (not in the code yet — HIL-1691) |
| Scheduled works, actual maintenance periods and Completed delivery | (not in the code yet — HIL-1692) |
| Admin history | (not in the code yet — HIL-1693) |
| Cluster and failover proof | (not in the code yet — HIL-1694) |
| React UI parity, including public freeze/disconnection behavior | (not in the code yet — HIL-1695) |
| Angular UI parity, including public freeze/disconnection behavior | (not in the code yet — HIL-1696) |

PR-133 through PR-135 and PR-204 through PR-209 remain the code leaves' design
promises. HIL-1685 neither implements nor closes them. Exact HIL-1691 and
HIL-1692 storage schemas, admin and guest action DTOs, and frontend page
registry keys remain with their implementation interviews and the ordinary
contract approval gate. Remove a future marker only in the commit that lands
its behavior, following
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Validation

For this documentation, run
`composer run test:framework:unit -- --filter AgentDocGuardTest`, then
`composer ai:install` and `composer ai:install:check`, through the prescribed
test environment. Check `git diff --check` and walk the index and skill links.
No new text test or full frontend/e2e/cluster run is needed for this text layer.

Review the built feature, component declaration, setting, agent pair, DB/RT
operations, notices and typed projection against current source. Keep sibling
markers, public JSON v1, privacy, D-248 and the narrow file/freeze exceptions
aligned with the future owners. Cluster and failover evidence belongs to
HIL-1694.
