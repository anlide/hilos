# Architecture: The Status Page

Read this before declaring Status components, changing the candidate and outage
machine, publishing its public files, building its admin section, or reading
`/status` without a connection or under a freeze.

This document records the Status backend built by HIL-1688, the admin surface
built by HIL-1689, the public file publisher and reader built by HIL-1690, and
the subscriber lifecycle and outage mail built by HIL-1691. Scheduled works
and actual maintenance periods, admin history, separate cluster/failover proof,
and the React and Angular views remain with HIL-1692 through HIL-1696 where
their markers say so.

## Core Rule

The machine declares an outage from facts about expected agent instances. A
person may suppress publication or add public words, but cannot declare, close
or delete an outage. One cluster owner holds the durable history.

The public page reads one sanitized projection from nginx files, with or without
a usable socket. Neither an admin table mirror nor `page_response` supplies
that projection. File publication and its heartbeat continue under a freeze;
the database half obeys the freeze's silence.

HIL-1688 builds the framework feature, component catalog, collector consumer,
durable incident machine, candidate notices, internal owner operations and a
typed backend read projection. HIL-1689 adds the Overview and Components admin
pages, the read-only component viewport, the Overview's incident actions and
the Vue views mounted by Chat and Binance BTC Tracker. HIL-1690 adds the public
file pair, its browser reader and the publisher's narrow freeze exception.
HIL-1691 adds account-independent email subscriptions, the Subscribers admin
page, and best-effort outage mail to confirmed recipients. Scheduled works and
actual maintenance periods remain with HIL-1692; the admin history UI with
HIL-1693; separate cluster/failover proof with HIL-1694; and React and Angular
views with HIL-1695 and HIL-1696.

Use the existing [Daemon picture chain](daemon-section.md#the-circulation),
[filesystem](filesystem.md), [protected mode](protected-mode.md) and
[time-zone canon](time-zones.md). They remain the sources for their mechanisms;
Status does not introduce another collector, storage transport or time helper.

## Components And Activation

`HilosFeature::STATUS` is a framework-owned [Mode 1 feature](admin-features.md):
the project activates it and declares its component content; it does not copy
the outage machine. `StatusFeature` requires `DAEMON`, `NOTIFICATION_DELIVERY`
and `AUTH_THROTTLE`, the `HILOS_STATUS` agent, the shared mail-delivery agent,
the `StatusSettingsCatalog` fragment, the `hilos_status_incident`,
`hilos_status_incident_update` and `hilos_status_subscriber` tables, all three
Status pages, both Status tables, and the Components and Subscribers
page-to-table bindings. It mounts the feature-owned runtime collection.

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
schema migration pairs from the framework's `status_incident`,
`status_incident_update` and `status_subscriber` units; the update unit depends
on the incident unit. The pull also includes the generated `auth_block` scope
upgrade pair that adds the `email` value to its existing enum. It does not apply
SQL. Commit the generated pairs with the project and apply them through the
ordinary `db:migration:up` command. Do not hand-write or copy these migrations.
All six demos in this repository activate the feature, register the Status
owner pair and merge the settings fragment; their schema pairs came from the
same framework pull. Each also registers thin project subclasses for all three
Status pages and both Status tables, then binds Components and Subscribers to
their pages in `PAGE_TABLES`. Feature activation validates these requirements
at startup.

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

### Startup Paths

A component declaration also carries a startup obligation: its registered agent
type must have a start path for its `scope` and `placement`. The catalog names
the component; the project's `AGENTS` entry still determines where its agent
runs:

- `NODE` components use the existing per-node start after local workers are
  ready. The protected-mode resume hook repeats that start.
- `CLUSTER` + `POLICY` components use the daemon's existing placement pass. In
  standalone mode, its throttled local fallback remains in force.
- `CLUSTER` + `LEADER` components use the existing singleton-host hook. When
  Status is enabled, the base `onBecameSingletonHost()` queues
  `INITIAL_AGENTS_START` first, then requests each cataloged agent type once
  through `startAgent()`. The ordinary placement and protected-mode gates still
  apply, and each failed component start is contained independently. A project
  override of this hook calls the parent first so the catalog start remains.

The empty-catalog `service` fallback still names `HILOS_INDEX`; a request from
both the bootstrap list and the Status helper is safe because `startAgent()` is
idempotent. Repeated entries for one agent type are coalesced within a Status
pass. The daemon re-runs the singleton-host hook after promotion and after a
worker dies hosting agents; it does not scan all leader components for liveness
on every tick. Leadership loss stops the local `CLUSTER` + `LEADER` agents
through the existing loss hook.

After the remembered roster is replayed, the existing protected-mode resume
hook starts `NODE` agents and checks the enabled Status catalog again on the
leader. This also requests a component whose earlier start was refused by the
freeze and so was absent from the stopped roster. No Status-specific freeze
bypass is added.

### Admin Pages

The built backend section has three routed pages: `hilos_status` at
`/hilos/status`, `hilos_status_components` at `/hilos/status/components`, and
`hilos_status_subscribers` at `/hilos/status/subscribers`. All have empty route
params, use `PageReach::ROUTE`, and inherit the `ADMIN` access level from
`AbstractHilosPage`. `Status` is a child of Dashboard and is opted into the
shared page head with label and breadcrumb `Status`, icon `bi-activity`, and
lead `A public status page: service health, incidents, and planned work.`
`Components` and `Subscribers` are its children and current tabs. Scheduled
works and History are not admin pages in this build.

Both first renders use the existing `page_response`. Overview data is
`page_response.data.status`, assembled temporarily by
`StatusOverviewData::read()` from the catalog, current component and subscriber
counts, open incidents and their updates, the publication-delay setting, and
protected mode:

```text
status = {
  healthKnown: bool,
  components: {operationalCount: int, totalCount: int},
  publishDelaySeconds: int | null | HiddenValue,
  maintenance: null | {
    phase: string, operation: string | null, startedAt: int | null
  },
  incidents: null | Incident[],
  subscribers: null | {confirmedCount: int, pendingCount: int}
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
history, subscriber address, delivery count or public-file data in this payload.
The separate subscriber count object is null when its DB source is unavailable
and contains zeroes only when the aggregate proves an empty set.

`StatusIncidentData` and `StatusIncidentUpdateData` build only the open incident
and its own timeline projection. The DTO uses `WireField::column()` for
persisted origins, `WireField::setting()` for the delay, and
`WireField::notPersonal()` for computed/catalog/runtime fields. The table
likewise marks only its catalog and computed fields nonpersonal. In admin view
mode, personal incident-update text becomes `HiddenValue`; the row and timeline
remain. Viewer permissions and hidden-field behavior follow
[admin view mode](admin-view-mode.md).

The three `BROWSER.SIGNAL` declarations use
`subscription_page_hilos_status`,
`subscription_page_hilos_status_components`, and
`subscription_page_hilos_status_subscribers`. These are metadata names, not
additional frames: page state still arrives in the existing `page_response`.
The Overview's `status.subscribers` and the Subscribers page's top-level
`statusSubscribers` carry `{confirmedCount, pendingCount}` in that answer. Both
pages declare reads of `statusSubscribers` and rebuild their current counts on
committed subscriber changes; neither adds a count action or signal. Counts do
not wait behind the viewport's Show/Apply boundary, and a known zero does not
collapse or replace a held viewport window.
The internal `Hilos\Status\StatusSubscriberCountsData` aggregate also carries
`total` and `maxSubscriberId` for the owner's audience cutoff; only
`{confirmedCount, pendingCount}` crosses the page-response boundary.

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

The Subscribers page binds the standard DB viewport table
`hilosStatusSubscribers`. Its `subscriber` slot contains exactly
`{id: int, email: string | HiddenValue, subscribedAt: int, confirmed: bool}`.
The table searches email, filters by a strict boolean `confirmed`, and sorts by
email, `subscribedAt` or `confirmed`, with the primary id settling ties. Its
default order is `subscribedAt DESC, id DESC`. The collection predicate adds
the confirmation filter for the window, facets and membership check. In admin
view mode the email is `HiddenValue`; search and sort over that hidden field
have no side channel, while the nonpersonal confirmation filter remains. The
trash action is disabled for a viewer and a forged valid-id action is refused
by the server. The table uses the ordinary Show/Apply taxonomy: new rows above
or inside a held window wait for Show, shown moves and removals wait for Apply,
values update immediately, departed placeholders carry no row data and preserve
the held geometry, and the initiating admin's own removal applies immediately.
Counts remain independent of that held window.

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
project bindings use the existing `HILOS_INDEX`; there is no new agent type. The
incident actions add no incident/RT fields or change their existing owner; they
reuse existing page and agent signal envelopes. Subscriber storage and its
owner are separate and described below.

The Subscribers page owns the separate `hilos_status_subscriber_remove`
`ADMIN` action with `HilosStatusRemoveSubscriberActionDTO {subscriberId: int
> 0}`. It keeps that action name, forwards the exact id in
`StatusRemoveSubscriberSignalData` to `StatusAgent`, and waits on
`hilos_status_subscriber_write_done`. The owner rechecks the fixed page/action
gate and freeze before deleting the row in its transaction. The internal ask is
not an `AGENT_ACTION`; the guest subscription actions remain on the Status
owner and do not share the admin removal path.

Overview source changes use the existing guarded `PageResender` /
`resendPageWhole()` path. The narrow `hilos_status` branches in
`BrowserContext::addBrowserChange()` and `addBrowserStaleness()` reuse the
subscription guards and `publishedWholeThisFlush`; they add no timer, cache or
generic fan-out. Components and Subscribers remain separate page subscriptions,
so an Overview re-answer does not reset either table viewport.

The framework's core module binds `page_response.data.status` and the
Subscribers page's `page_response.data.statusSubscribers` through strict schemas
and the existing `pageDataSignal`; it performs no mount fetch. Its state and
incident controllers retain the captured `incidentId`, draft, busy, refusal and
timeout lifecycle. A live update cannot retarget the dialog to a new incident
for the same component. A suppressed candidate remains `Checking` with
“Publication canceled manually”; reaching countdown zero does not publish it
or create a server fact. Moments use `formatMoment()` in the reader's zone; the
countdown uses the server clock through `toLocal()` and `formatCountdown()`.

The SDK exports the admin implementation lazily from
`@hilos/core/admin/status` and `@hilos/vue/admin/status`, while keeping
compatibility-root exports. The public reader and its subscription controller
are exported from `@hilos/core/status`; the Vue page is exported from
`@hilos/vue/status` and the compatibility root with required props
`{connection, actions}`. Its subscription modal is controller-owned: Subscribe
appears in a body row below the navigation-only shared header, not in a header
button slot. `HilosStatusSubscribersPage` from `@hilos/vue/admin/status` takes
`HilosStatusSubscribersContext`, the existing `{scopes, connection, actions}`
Status context. Status is not imported by either package's eager `base` entry
or Vue's default `hilosAdminViews()` map. Chat and Binance BTC Tracker mount
thin Vue wrappers for the public reader and all three admin pages. The public
`/status` route is the framework-owned client file reader; it has no backend
page, catalog entry or `page_response` subscription. React and Angular Status
views remain unbuilt until HIL-1695 and HIL-1696, so their Status tabs stay out
of the admin navigation.

The project also registers the shared Status directory, mounts the same volume
for every node's nginx, and binds the public page to the framework reader.
Neither a separate writer per node nor a project copy of the reader is an
activation mechanism. Each of the six demo filesystem contexts registers
`FsContext::STATUS` at `data/status` with `DirectoryScope::CLUSTER`; its nginx
mounts that directory read-only.

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

The same `StatusAgent` owns Add, Update and Remove on the lazy
`Hilos::$db->statusSubscribers` collection. Its primary-table rows are not
journaled and have no user or session foreign key; the schema and link lifecycle
are described in [Subscribers And Mail](#subscribers-and-mail).

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
This is the admin `page_response` projection. The public file format is the
separate sanitized `StatusPublicSnapshot` built by `StatusPublicSnapshotBuilder`;
the public reader never consumes this admin payload.

### Continuous Fact And Timeline

Each new continuous abnormal observation creates one durable candidate with a
fixed detection moment and publication deadline. `StatusSnapshot::read()` can
project an available unpublished incident as `CHECKING`; the public snapshot
projects only its component key and detection moment while that fact is
currently Checking. When live count is zero the current severity is Major; a
positive count below expected is Partial. Partial↔Major changes update
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
manually suppressed publication. These committed timeline events feed the
best-effort subscriber mail work described in
[Subscribers And Mail](#subscribers-and-mail); they are separate from the
candidate notice queue above.

Admin history retains ended candidates with their reasons, published outages
and completed works. A short or suppressed candidate never remains in public
history.
(not in the code yet — HIL-1693)

### Subscribers And Mail

Subscriptions are account-independent. The lazy
`Hilos::$db->statusSubscribers` collection maps to the non-journaled primary
table `hilos_status_subscriber`; `StatusAgent` is its only cluster writer for
Add, Update and Remove. The row has exactly these persisted columns:
`id`, `email`, `subscribed_at`, `confirmed`, `confirmed_at`,
`confirmation_token_hash`, `confirmation_expires_at`, `unsubscribe_token`, and
`browser_time_zone`. It has no user or session foreign key. The email is trimmed,
lowercased, validated and unique, with a 254-character limit.

Confirmation and unsubscribe capabilities are 32 random bytes encoded as 64
hexadecimal characters. The raw confirmation value exists only in the ephemeral
post-write result passed to mail; the DB stores its SHA-256 hash and a 24-hour
expiry. The confirm action uses one conditional update, so one concurrent caller
wins and the hash and expiry are cleared when it succeeds. Resending to a
pending address replaces its challenge and expiry. A confirmed address receives
no new confirmation mail. The permanent unsubscribe capability is held in the
DB only, outside ORM hydration; deleting a row revokes both capabilities. A
later row has a new id and new capability, so an old link cannot affect it.

The entity classifies `email` as `FAKE_EMAIL`; it classifies
`confirmation_token_hash`, `unsubscribe_token` and `browser_time_zone` as
`NULLIFY`; the remaining row columns are `notPersonal`. Unique indexes cover
email and both token columns. Additional indexes support `(subscribed_at DESC,
id DESC)`, `(email, id)`, `(confirmed, id)`, `(confirmed, subscribed_at DESC,
id DESC)`, and `(confirmed, email, id)`. The secret columns are deliberately
excluded from the ORM row projection; there is no subscription journal or
delivery outbox.

The guest action names and DTOs belong to `StatusAgent::AGENT_ACTIONS`:

| Action | DTO payload | Reply |
|---|---|---|
| `status_subscribe` | `StatusSubscribeActionDTO {email: string}` | `{status: 'check_inbox'}` for new, pending or confirmed addresses |
| `status_subscription_confirm` | `StatusConfirmSubscriptionActionDTO {token: string}` | `{status: 'subscribed'}` for the atomic winner |
| `status_unsubscribe_preview` | `StatusUnsubscribePreviewActionDTO {token: string}` | `{maskedEmail: string}` |
| `status_unsubscribe` | `StatusUnsubscribeActionDTO {token: string}` | `{status: 'unsubscribed'}`, including when its row was already removed |

The email and every token DTO mark their secret payload field in
`SECRET_FIELDS`; token values must be exactly 64 hex characters. Confirmation
and preview reject malformed or unknown capabilities with a generic domain
refusal. Preview never returns a full address. These WebSocket actions require
no account and have no public HTTP mutation route. All four pass through the
shared throttle. The opt-in email identity exists only on
`StatusSubscribeActionDTO`; its normalized address is converted to a
domain-separated SHA-256 key and never stored as the throttle identity.

`HILOS_STATUS_URL` is the configured absolute public `/status` address used in
mail. It accepts only HTTP or HTTPS with no credentials, query or fragment. A
missing or invalid value refuses a subscribe before it writes a row or issues
a token; confirmation and unsubscribe do not need to read it again. The root
`.env.example` leaves it blank, all six demo `.env.example` files give their
local `/status` address, and each test compose file supplies the matching demo
frontend address.

For a new row, the server snapshots the browser zone from the connection's
session source and session record. It stores only the normalized IANA zone, not
the session or account. Existing rows keep that snapshot on resend. Mail uses
the shared recipient-zone rule in
[Server Text And Human-Readable Exports](time-zones.md#server-text-and-human-readable-exports)
(HIL-1578), through
`TimeHelper::serverTextTimeZone(browserTimeZone: $savedZone)`, and prints the
selected zone's offset and name.

Confirmation links use `#status-confirm=<token>` and unsubscribe links use
`#status-unsubscribe=<token>`; neither capability is placed in a path or query.
The Vue reader's mounted controller reads its initial address and listens to
`hashchange` and `popstate` for new links on the same pathname. It removes each
owned fragment immediately with
`history.replaceState` before dispatch, and keeps the token only in mounted
memory and the correlated action body. It sends one automatic confirmation or
unsubscribe preview after a usable connection exists. An unsubscribe requires
an explicit confirmation; Keep does not delete. A disconnected or frozen
browser waits for the live action path, and no optimistic success or retry loop
is shown. The history rewrite does not trigger a route transition or loading
cycle; see
[the public Status route](../frontend/page-registry.md#the-public-status-route).
Unrelated anchors leave the operation and file reader alone. A link arriving
while a request is busy or another token is retained is scrubbed and ignored;
the current operation keeps its request and token, with no link queue. A later
navigation can present that link again after the operation finishes or Keep
releases it. Unmount removes both address listeners and discards the private
token; a captured callback cannot restart a disposed controller.

Confirmation mail is registered as `status.subscription.confirm`. Published
incidents, team updates and resolutions use
`status.outage.published`, `status.outage.update` and
`status.outage.resolved`. Each template has plain text and escaped HTML and
uses the existing `MailLetterFrameResolver`, `HilosMailer`, raw mail pool and
address shard. The confirmation parameters are `projectName`, `statusUrl`,
`confirmUrl`, `unsubscribeUrl` and `expiresAtLabel`. Outage mail carries
`projectName`, `statusUrl`, `unsubscribeUrl`, `componentName`, `severity`,
`text`, `detectedAtLabel`, `eventAtLabel` and, for resolution,
`resolvedAtLabel`. `MAIL_FROM_NAME` falls back to `Service`; `locale: null`
selects the project's explicit default locale under the existing mail
template contract. Mail carries only approved public words, with no internal
agent, node or exception detail. It does not use the Notification feature or a
Delivery journal.

After a published, team or resolved timeline transaction commits,
`Database::afterCommit()` captures a detached immutable notice. The private
`StatusSubscriberMailTask` captures the event, the maximum subscriber id, the
validated links and project name. Its recipient query is bounded to confirmed
rows with `confirmed_at <= eventAt` and `id <= maxSubscriberId`; before each
send the owner rechecks the primary id and confirmation. One task is drained
per tick, with at most 32 recipient attempts and a cooperative 20 ms portion
budget after at least one attempt. The cursor advances before mail handoff, so
an ambiguous send is not replayed. These tasks are private best-effort memory;
stop, freeze or restart discards them and there is no history replay. A lost
task or an SMTP result that is ambiguous after queueing is accepted. SMTP is not
exactly once. Confirmation mail is also issued only after its transaction
commits. Subscriber addresses, capabilities and saved zones never enter the
public files or public JSON v1; the public schema and heartbeat are unchanged.

### Scheduled Works And Maintenance Periods

Scheduling, changing or canceling works publishes the announcement change and
sends mail. Schedule clocks never enter or leave protected mode. Actual work
starts with the freeze: it is announced in advance when the freeze begins
inside the work's window, and unannounced otherwise. It continues if the freeze
outlasts the scheduled end. When the freeze ends, complete the period and send
Completed through delivery when permitted. Full freeze does not permit durable
delivery merely because Status still publishes files.
(not in the code yet — HIL-1692)

## Static Publication

`FsContext::STATUS = 'status'` is a reserved logical directory name reached as
`Hilos::$fs->status`. A project registers it in its filesystem context as
`DirectoryScope::CLUSTER`; `Hilos::init()` refuses Status activation when the
directory is missing or declared `NODE`. The installation chooses the backing
shared disk volume. All six demos register `data/status` as this cluster
directory and mount the same directory read-only into nginx. The one cluster
Status owner publishes the sanitized projection independently of visitors.
The general registration, cluster marker and shared-volume rules are in
[filesystem.md](filesystem.md).

`StatusPublicSnapshotBuilder` reads the typed `StatusSnapshot` and published
incident history in bounded portions; it completes an incident's timeline in
bounded portions too. It retains only detached public values, never DB or RT
objects. `StatusPublicFiles` serializes a bounded fragment/byte portion per
owner pass and uses `FsPath::publish()` to atomically install each complete file
from a temporary file on the same volume. It restores one coherent pair at
owner start; an absent, malformed or revision-mismatched pair does not replace
the last known archive. Publish whole `status.json`, then whole
`heartbeat.json` with the same revision, then the change hint. A heartbeat-only
pass updates `aliveAt` with the last successful content revision and then sends
the hint. A failed build or write is retried without advancing the heartbeat or
sending a success hint; if the files temporarily disagree, the reader accepts
neither revision-mixed pair.

### Public JSON v1

These are JSON shape declarations, not PHP/TS implementation types. All keys
are present: nullable fields serialize as `null`, lists as `[]` when empty.
Every moment below — including `at`, `startsAt` and `endsAt` — is an integer
of epoch milliseconds UTC, following [A Moment On The Wire](time-zones.md#a-moment-on-the-wire).
Every `id` is opaque. `revision` is a unique opaque content revision without a
node id or term; the heartbeat repeats the last successfully published
`status.json` revision.

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

The current writer emits `scheduledWorks: []` and `maintenancePeriods: []`.
These v1 shapes are reserved for the scheduled works and actual maintenance
periods owned by HIL-1692.
(not in the code yet — HIL-1692)

Apply these publication limits:

- Components retain project declaration order. `agentType` is admin-only.
- Candidates contain current Checking only, with no internal id, publication
  deadline or manual cancellation reason.
- Outages contain published open outages and published completed outages
  intersecting the last 90 days. Automatic update text is safe public wording
  selected by fact code; team text is the administrator's published wording.
  Each outage uses an opaque public id, never the internal integer id.
  Unpublished, suppressed and canceled candidates never become outage history.
- The public JSON reserves `Work` and `MaintenancePeriod` shapes, but the current
  writer keeps both lists empty. Their publication rules and implementation
  remain with HIL-1692.
- Works contain upcoming announcements and those belonging to current works;
  canceled announcements are not public.
- Maintenance periods contain active periods and completed periods intersecting
  the last 90 days. `workId: null` means unannounced works; `title` is a public
  label. Their boundaries follow the actual freeze, not schedule clocks.
(not in the code yet — HIL-1692)
- Never publish subscribers, email addresses, tokens, session time zones,
  operation or phase, PIDs, node ids/IPs, agent types, raw Daemon pictures,
  private error or exception text, or hidden candidates in history. Version,
  config and revision exist only to read the public projection correctly.

### Intervals, Hint And HTTP Addresses

| Environment constant | Type | Default |
|---|---|---|
| `EnvConstants::STATUS_POLL_INTERVAL_SECONDS` | positive integer (minimum 1) | 30 seconds |
| `EnvConstants::STATUS_STALE_AFTER_SECONDS` | positive integer (minimum 1) | 120 seconds |

Publish both values in `status.json.config` so a cold page learns them without
the daemon. The heartbeat interval is `min(30 seconds, staleAfterSeconds / 3)`.
Its 30-second ceiling is a separate named publisher constant, not a reuse of
the polling env value. Defaults yield a heartbeat every 30 seconds; a smaller
stale threshold requires a faster heartbeat. This derived internal cadence is
not a third env key and does not change the public shape. An nginx response
never updates `aliveAt`.

Declare `HilosSignalConstants::STATUS_FILES_CHANGED = 'status_files_changed'`
with `Hilos\Status\DTO\StatusFilesChangedSignalData`, payload `{}` (no fields).
It is only a hint that publication succeeded: no status, error, address or file
path travels in it. Use existing `sendToAllConnected()` / `WS_ALL_CONNECTED` so
a guest on `/status` receives it without a page subscription; the active Status
reader rereads the files, and other pages ignore it. Do not add a group or
subscription channel. The hint is sent only after a matching pair or a
heartbeat-only update succeeds, including when component state has not changed.

The public client route is `/status`. nginx serves the built project shell
there, plus exactly two public file addresses, allowlisted for GET and HEAD:

- `/status-data/status.json`;
- `/status-data/heartbeat.json`.

Use exact nginx locations with `Cache-Control: no-store`; the `/status-data/`
catch-all refuses every other name, slash form or method, and directory listing
is disabled. Do not expose temporary or hidden files. The mandatory
`.hilos-cluster-directory.json` marker stays private: its node identifier is
required for the cluster guard, not public Status data. Every node's nginx reads
the same read-only volume. Do not add a backend-fetch endpoint for the
projection.

## Reading /status

On entry, always read both nginx files. Render their contents without waiting
for daemon HTTP, an API, DB, handshake or `page_response`. Accept only a pair
with matching `revision` and supported `schemaVersion`; retry a revision race
up to three pair reads instead of mixing revisions. File mtime, a successful
nginx response and the arrival of a hint cannot turn the last coherent
projection into a new one.

With a usable live connection, reread the same files after every change hint.
Without one, including a connection held by freeze, poll at the file's
`pollIntervalSeconds` (default 30 seconds). On recovery of a usable live
connection, reread immediately. Maintain the local heartbeat-age timer even
with a live socket, so a stopped publisher is detected independently of
transport health. Only one read runs at a time; hints during it request one
follow-up read, and unmount aborts pending fetches and disposes both timers.

A fresh heartbeat with a broken socket says **system is up**; it does not erase
an outage or Maintenance in the projection. Age is measured against the
reader's current clock, even with a live socket. Once `aliveAt` is older than
`staleAfterSeconds` (default 120 seconds), say **not responding since**
`<aliveAt>` and show components as Unknown. Stale operational data is not proof
of current health. Empty, malformed or missing files do not replace the last
accepted pair; retain its history while its heartbeat is fresh, then show the
stale verdict. Staleness maps current components to Unknown and hides the
current Checking list, while retaining the accepted published incidents and
history. Without any accepted pair, the banner and component knowledge are
Unknown; never invent Operational.

The current public surface shows an overall banner, component states, Checking
candidates, published open outages and their timelines, plus completed
published-outage history. The 90-day calendar strip uses published outage
intervals; the narrow strip is its last 30 days. Each day shows its worst
published fact; candidates do not color days or enter history. The current
writer emits no scheduled works or maintenance periods, so those sections and
facts remain with HIL-1692. Show no SLA percentage. Use the shared time
mechanism for calendar boundaries and the core formatter in the reader's zone,
including the browser's zone for a guest; do not reproduce the mockup's UTC
screen labels (D-248). The [time-zone canon](time-zones.md) owns zone selection
and formatting, including recipient-zone mail.

## Freeze And Disconnection

`/status` is the narrow exception to subscription waiting, the freeze stub,
disconnect blocking and the ordinary stale-data screen. The built Vue reader
remains available on a cold load during full freeze; the maintenance stub links
to it as **Service status**. A disconnected shell may indicate the break, but
cannot cover the file reader with a blocking overlay. The exception covers this
read-only page's first-frame paint, disconnect, stale heartbeat and access
reactions only; it does not unblock shell requests, actions or modal controls.
Other pages keep their existing rules. React and Angular remain unbuilt until
HIL-1695 and HIL-1696.

Do not show the subscription button without a usable live connection. Every
subscription action and subscriber mail pass checks that the Status database
source is ready and the freeze permits the work; an occupied or unavailable
freeze runtime refuses the operation before subscriber access or mail. Public
file reading grants no offline mutation path or permission to bypass a freeze
for subscription actions.

The publisher's file half survives full freeze, or normal Maintenance would
become “not responding” after the stale threshold. `HILOS_STATUS` is preserved
with `HILOS_MAIL` by both `WorkerServer::protectedModeRefusesStart()` and
`WorkerServer::stopAgentsForProtectedMode()`, including agents awaiting a
worker. This permits only the public projection and heartbeat work, with no new
master I/O, child process or background process.

In every occupied freeze phase, the Status owner publishes from a detached
sanitized snapshot and the freeze fact. It does not read DB-backed Status data
or settings, observe components, perform ORM actions or deliver subscriber or
other durable mail.
Its public files contain no operation or phase, node/process/network details,
agent type, internal error, subscriber address, token or session time zone.
Entry abandons the owner's observation baseline and partial public build; after
lift it obtains a fresh Daemon picture and resumes only from permitted data.
On a cold owner start it restores only a revision-matched file pair; with no
valid pair, the reader begins Unknown. A known occupied freeze publishes
Maintenance while retaining only restored sanitized history; a missing freeze
runtime row publishes Unknown. Agents stopped by the freeze do not become
outage facts. This is no exception for ordinary ORM writes.

Serving these files is independent of the daemon, not of nginx or its machine.
If nginx or the whole host is unavailable, the page cannot open. External
hosting and external monitoring are outside this feature.

## Implementation Status And Leaf Owners

The built feature is split by owner below. The source paths also identify the
current validation surfaces.

| Work | Source and test evidence | Owner and state |
|---|---|---|
| Framework activation, component catalog, status owner, DB/RT machine, candidate notices and typed admin read | `framework/backend/Status/`, `framework/tests/Unit/Status*Test.php`, `framework/tests/Integration/Status*Test.php` | Landed by HIL-1688 |
| Admin Overview and Components pages, read-only viewport, gatekeeper actions, shared page head and Vue views | `framework/backend/Pages/Status/`, `framework/backend/Tables/Status/`, `framework/frontend/core/src/admin/status/`, `framework/frontend/vue/src/admin/status/` | Landed by HIL-1689; React and Angular remain with HIL-1695 and HIL-1696 |
| SDK packaging and lazy entrypoints | `framework/frontend/core/test/packaging/coreEntryPoints.test.ts`, `framework/frontend/core/test/bin/initialBundleBudget.test.mjs`, `framework/frontend/vue/test/packaging.smoke.mjs` | Core and Vue export `/admin/status` lazily; eager base closures remain free of Status. React and Angular have no Status view entry yet. |
| Public files, bounded builder, heartbeat, hint and reader | `framework/backend/Status/StatusPublicSnapshotBuilder.php`, `framework/backend/Status/StatusPublicFiles.php`, `framework/tests/Unit/StatusPublicFilesTest.php`, `framework/tests/Integration/StatusPublicFilesIntegrationTest.php`; `framework/frontend/core/src/status/`, `framework/frontend/core/test/status/` | Landed by HIL-1690 |
| Public route, Vue page and standalone browser coverage | `framework/frontend/core/src/routing/hilosPages.ts`, `framework/frontend/core/src/routing/HilosRouter.ts`, `framework/frontend/core/src/routing/pageTitle.ts`; `framework/frontend/vue/src/public/HilosStatusPage.vue` and `framework/frontend/vue/src/public/HilosStatusPage.test.ts`; `demo/binance-btc-tracker/tests/e2e/tests/status.spec.ts` and `demo/binance-btc-tracker/tests/e2e/tests/a11y.spec.ts` | Core route and Vue views are built; React and Angular public views remain with HIL-1695 and HIL-1696 |
| Guest subscriptions, primary storage, links and mail | `framework/backend/Status/StatusAgent.php`, `framework/backend/Status/StatusSubscriptionLink.php`, `framework/backend/Status/StatusSubscriberMailTask.php`, `framework/backend/Database/Entity/Item/StatusSubscriber.php`, `framework/backend/Database/Actions/Collection/StatusSubscribersActions.php`, `framework/backend/Database/Actions/Item/StatusSubscriberActions.php`, `framework/backend/Mail/Template/Status*MailTemplate.php`; `framework/tests/Unit/StatusGuestActionDTOTest.php`, `StatusSubscriptionLinkTest.php`, `StatusMailTemplatesTest.php`; `framework/tests/Integration/StatusSubscriberSchemaIntegrationTest.php`, `StatusSubscriberStorageIntegrationTest.php`, `StatusSubscriptionIntegrationTest.php`, `StatusSubscriberMailIntegrationTest.php` | Landed by HIL-1691; storage, guest actions and best-effort mail are documented above |
| Subscribers admin viewport and public subscription controller | `framework/backend/Pages/Status/AbstractHilosStatusSubscribersPage.php`, `framework/backend/Tables/Status/HilosStatusSubscribersTable.php`; `framework/frontend/core/src/admin/status/hilosStatusSubscribers.ts`, `framework/frontend/core/src/status/statusSubscription.ts`, `framework/frontend/vue/src/admin/status/HilosStatusSubscribersPage.vue`, `framework/frontend/vue/src/public/HilosStatusSubscriptionModal.vue`; `framework/tests/Unit/StatusSubscriberTableTest.php`, `StatusSubscriberAdminIntegrationTest.php`; `framework/frontend/core/test/admin/status/hilosStatusSubscribers.test.ts`, `framework/frontend/vue/src/admin/status/HilosStatusSubscribersPage.test.ts`; `demo/chat/tests/e2e/tests/status-subscribers.spec.ts`, `demo/binance-btc-tracker/tests/e2e/tests/status-subscriptions.spec.ts` | Landed by HIL-1691; Chat and Binance carry the built Vue pages, other four SDK views remain unbuilt |
| Email throttle scope and per-parking-agent verdict route | `framework/backend/Auth/Throttle/ThrottleEmailIdentityInterface.php`, `ThrottleIdentity.php`, `ThrottleVerdictRoute.php`, `framework/backend/Status/StatusAgent.php`, `framework/backend/Core/Topology/TopologyValidator.php`, `framework/backend/Core/Page/PageSignalRouter.php`; `framework/tests/Unit/ThrottleVerdictRouteTest.php`, `StatusThrottleVerdictRoutingTest.php`, `TopologyValidatorTest.php`, `PageSignalRouterThrottlePoolTest.php`; `framework/tests/Integration/AuthThrottleIntegrationTest.php`, `StatusSubscriptionIntegrationTest.php` | Landed by HIL-1691; the ordinary auth verdict remains on its existing host |
| Scheduled works, actual maintenance periods and Completed delivery | — | (not in the code yet — HIL-1692) |
| Admin history | — | (not in the code yet — HIL-1693) |
| Separate cluster and failover proof | — | (not in the code yet — HIL-1694) |
| React UI parity, including public freeze/disconnection behavior | — | (not in the code yet — HIL-1695) |
| Angular UI parity, including public freeze/disconnection behavior | — | (not in the code yet — HIL-1696) |

PR-133 through PR-135 and PR-204 through PR-209 remain the code leaves' design
promises. HIL-1685 neither implements nor closes them. HIL-1691's subscriber
schema, guest and admin action DTOs, and mail tasks are implemented in the
source paths above. HIL-1692 storage schemas and action DTOs remain with that
leaf and the ordinary contract approval gate. Remove a future marker only in
the commit that lands its behavior, following
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Validation

Status checks are split across these existing layers:

- The feature contract and page/table binding are guarded by
  `framework/tests/Unit/StatusFeatureActivationTest.php` and the six demo
  `*TopologyRegistryTest` files.
- Subscriber schema, storage, atomic confirmation, guest action replies,
  removal handover, and committed mail are covered by
  `framework/tests/Integration/StatusSubscriberSchemaIntegrationTest.php`,
  `StatusSubscriberStorageIntegrationTest.php`,
  `StatusSubscriptionIntegrationTest.php`,
  `StatusSubscriberAdminIntegrationTest.php` and
  `StatusSubscriberMailIntegrationTest.php`. Unit coverage includes
  `StatusGuestActionDTOTest.php`, `StatusSubscriptionLinkTest.php`,
  `StatusSubscriberTableTest.php` and `StatusMailTemplatesTest.php`.
- The throttle's per-host route, DTO, indexed address and shared email scope are
  covered by `ThrottleVerdictRouteTest.php`,
  `StatusThrottleVerdictRoutingTest.php`, `TopologyValidatorTest.php`,
  `PageSignalRouterThrottlePoolTest.php` and
  `AuthThrottleIntegrationTest.php`.
- Core file parsing, pair coherence, freshness, hint/poll lifecycle, calendar
  and time zone are covered by
  `framework/frontend/core/test/status/statusFiles.test.ts`,
  `publicStatus.test.ts` and `derivedStatus.test.ts`. The subscription and
  subscriber table controllers are covered by
  `statusSubscription.test.ts` and
  `admin/status/hilosStatusSubscribers.test.ts`.
- Vue rendering is covered by the public page and modal tests and the admin
  Subscribers page test under `framework/frontend/vue/src/`. The router's
  fragment-only history behavior is covered by
  `framework/frontend/core/test/routing/HilosRouter.test.ts`.
- Real browser and Mailpit coverage is carried by
  `demo/chat/tests/e2e/tests/status-subscribers.spec.ts`,
  `demo/binance-btc-tracker/tests/e2e/tests/status-subscriptions.spec.ts`, and
  both demos' `tests/e2e/tests/a11y.spec.ts`. These specs use light color
  scheme and desktop Chrome; they do not claim dark-theme or narrow-viewport
  E2E coverage.

The existing standalone public-reader fixture is
`composer -d demo/binance-btc-tracker run test:e2e-status-full`. Mail tests
prove MIME content and escaped HTML, not rendering in a real mail client. The
real-client rendering and plain-text checks in
[manual-checks.md](../manual-checks.md#mail), items 1 and 5, remain unchecked
for owner acceptance. Status uses the shared Bootstrap theme mechanism; no SLA
or independent cluster/failover proof is claimed here. Cluster/failover
evidence belongs to HIL-1694. No run-specific temporary paths, process ids or
container ids belong in this canon.
