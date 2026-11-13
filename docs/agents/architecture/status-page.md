# Architecture: The Status Page

Read this before declaring Status components, changing the candidate and outage
machine, publishing its public files, building its admin section, or reading
`/status` without a connection or under a freeze.

This document records the Status backend built by HIL-1688, the admin surface
built by HIL-1689, the public file publisher and reader built by HIL-1690, the
subscriber lifecycle and outage mail built by HIL-1691, scheduled works and
actual maintenance periods built by HIL-1692, the completed admin History
page built by HIL-1693, the React views built by HIL-1695, and the Angular
views built by HIL-1696. Separate cluster and failover proof has not been
demonstrated, and no such proof is scheduled.

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
page, and best-effort outage mail to confirmed recipients. HIL-1692 adds
scheduled works, observed maintenance periods, the Works admin page, public v2
maintenance projection and reader support, and best-effort Completed mail.
HIL-1693 adds the completed-history admin page. HIL-1695 adds the React
counterpart of every Vue Status view and of the public reader, with the React
shell's `/status` exception, mounted by Tasks and Ecommerce Shop. HIL-1696 adds
the Angular views mounted by Polls and OnlineTesting. Separate cluster and
failover proof has not been demonstrated, and no such proof is scheduled.

Use the existing [Daemon picture chain](daemon-section.md#the-circulation),
[filesystem](filesystem.md), [protected mode](protected-mode.md) and
[time-zone canon](time-zones.md). They remain the sources for their mechanisms;
Status does not introduce another collector, storage transport or time helper.

## Components And Activation

`HilosFeature::STATUS` is a framework-owned [Mode 1 feature](admin-features.md):
the project activates it and declares its component content; it does not copy
the outage machine. `StatusFeature` requires `DAEMON`, `NOTIFICATION_DELIVERY`
and `AUTH_THROTTLE`, the `HILOS_STATUS` agent, the shared mail-delivery agent,
the `StatusSettingsCatalog` fragment, the incident, incident-update, subscriber,
work, work-component and maintenance-period tables, all five Status admin
pages, all four Status tables, and the Components, Subscribers, Works and
History page-to-table bindings. It mounts the feature-owned component and
maintenance runtime collections.

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
schema migration pairs from the framework's Status units. The update unit
depends on the incident unit. The maintenance units are `status_work`,
`status_work_component` (which depends on `status_work`), and
`status_maintenance_period` (which also depends on `status_work`). They enter
through immutable framework migration changes; generated per-demo schema pairs
are the output of the pull, not edits to the genesis catalog or old changes.
The pull also includes the generated `auth_block` scope upgrade pair that adds
the `email` value to its existing enum. It does not apply SQL. Commit generated
pairs with the project and apply them through the ordinary `db:migration:up`
command. Do not hand-write or copy these migrations. All six demos in this
repository activate the feature, register the Status owner pair and merge the
settings fragment. Each demo registers thin project subclasses for all five
Status pages. It registers the four final framework Status tables directly in
`Hilos::TABLES` under their framework table keys, then binds Components,
Subscribers, Works and History to those tables in `Hilos::PAGE_TABLES`. Do not
create project subclasses of the Status tables. Feature activation validates
these requirements at startup.

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

The built backend section has five routed pages: `hilos_status` at
`/hilos/status`, `hilos_status_components` at `/hilos/status/components`,
`hilos_status_subscribers` at `/hilos/status/subscribers`,
`hilos_status_works` at `/hilos/status/works`, and
`hilos_status_history` at `/hilos/status/history`. All have empty route params,
use `PageReach::ROUTE`, and inherit the `ADMIN` access level from
`AbstractHilosPage`. `Status` is a child of Dashboard and is opted into the
shared page head with label and breadcrumb `Status`, icon `bi-activity`, and
lead `A public status page: service health, incidents, and planned work.`
Components, Subscribers, Scheduled maintenance and History are child tabs;
together with the Overview they make up the five Status tabs, with History last.

Every admin page first render uses the existing `page_response`. Overview data
is `page_response.data.status`, assembled temporarily by
`StatusOverviewData::read()` from the catalog, current component and subscriber
counts, open incidents and their updates, the publication-delay setting, and
protected mode:

```text
status = {
  healthKnown: bool,
  components: {operationalCount: int, totalCount: int},
  publishDelaySeconds: int | null | HiddenValue,
  maintenance: null | {
    phase: string, operation: string | null, startedAt: int | null,
    announcement: null | {known: bool, work: Work | null}
  },
  incidents: null | Incident[],
  subscribers: null | {confirmedCount: int, pendingCount: int},
  scheduledWorks: null | {count: int, nextWork: Work | null},
  historyCount: int | null
}
Work = {
  id: string, title: string, description: string | null,
  componentKeys: string[], startsAt: int, endsAt: int
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
visible. A stale or unavailable maintenance RT source produces
`maintenance.announcement: null`; it does not imply an unannounced period. The
`scheduledWorks` summary is null while its DB sources are unavailable and
contains a zero count only for a known empty set. Its count covers unexpired,
unfinished announcements and `nextWork` is the nearest by start. Only open
incidents and their own updates are read into the Overview's `incidents` field;
that field contains no ended rows. The separate History page reads completed
incident and maintenance facts. This Overview payload contains no subscriber
address, delivery count or public-file data. The separate subscriber count
object is null when its DB source is unavailable and contains zeroes only when
the aggregate proves an empty set.

The Overview's Scheduled maintenance tile uses `status.scheduledWorks` for its
count and nearest announcement. Its existing maintenance card shows the
announcement only when the current RT observation proves it; a missing or
stale source leaves that detail unknown.

The Overview's `historyCount` is the exact scalar total of completed incidents
and maintenance periods. It is independent of History filters and the table's
500-row count ceiling. It is null when either source cannot be read or the
freeze silences DB reads; an unavailable count is not shown as zero.

`StatusIncidentData` and `StatusIncidentUpdateData` build only the open incident
and its own timeline projection. The DTO uses `WireField::column()` for
persisted origins, `WireField::setting()` for the delay, and
`WireField::notPersonal()` for computed/catalog/runtime fields. The table
likewise marks only its catalog and computed fields nonpersonal. In admin view
mode, personal incident-update text becomes `HiddenValue`; the row and timeline
remain. Viewer permissions and hidden-field behavior follow
[admin view mode](admin-view-mode.md).

The five `BROWSER.SIGNAL` declarations use
`subscription_page_hilos_status`,
`subscription_page_hilos_status_components`,
`subscription_page_hilos_status_subscribers`,
`subscription_page_hilos_status_works`, and
`subscription_page_hilos_status_history`. These are metadata names, not
additional signal frames: page state still arrives in the existing
`page_response`.
The Overview's `status.subscribers` and the Subscribers page's top-level
`statusSubscribers` carry `{confirmedCount, pendingCount}` in that answer. Both
pages declare reads of `statusSubscribers` and rebuild their current counts on
committed subscriber changes; neither adds a count action or signal. Counts do
not wait behind the viewport's Show/Apply boundary, and a known zero does not
collapse or replace a held viewport window.
The internal `Hilos\Status\StatusSubscriberCountsData` aggregate also carries
`total` and `maxSubscriberId` for the owner's audience cutoff; only
`{confirmedCount, pendingCount}` crosses the page-response boundary.

The History route uses `subscription_page_hilos_status_history` only as browser
metadata on the existing `BrowserPageSignalData`; it has `ACTIONS=[]` and empty
route params. Its first ordinary `page_response` carries nullable
`data.statusHistory` and the initial `windows.hilosStatusHistory` viewport
together. `statusHistory.components` is the complete component filter catalog;
the table window is the ordinary viewport, separate from that catalog. There is
no history-specific handshake, action or frame.

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

### Works page and actions

The Works page's first answer carries `data.statusWorks` and the initial
`page_response.windows.hilosStatusWorks` window together. Its data is either
`null` while a required source is unavailable or
`{components: list<{componentKey: string, name: string}>, upcomingCount: int,
nextWork: Work | null}`. This is the catalog for the editor and the nearest-work
summary; the browser makes no second request for either. All these fields are
explicitly nonpersonal.
The page's `dataFields()` and table's `wireFields()` identify every catalog and
row field as nonpersonal. The Vue page uses the shared admin view-mode controls;
schedule, change and cancel remain `ADMIN` mutations.
The Works page declares DB interest in both work collections and RT interest
in protected mode. The Overview declares the same work DB sources plus the
maintenance RT row and rebuilds its ordinary `page_response` data on relevant
DB and RT source changes. Its maintenance announcement is null when the RT
source is missing, stale or unavailable; no source failure becomes `false`,
zero or an unannounced period.

`HilosStatusWorksTable` uses the ordinary viewport with a maximum window of 32
rows, with the `work` row slot keyed by the stored work id. Rows carry
`{id: int, publicId: string, title: string, description: string | null,
componentKeys: list<string>, startsAt: int, endsAt: int, version: int,
canceledAt: int | null, consumedAt: int | null, expiredAt: int | null,
lastMailQueuedAt: int | null}`. The table searches
`title` and `description`, filters by the strict catalog key `componentKey`,
and offers ascending and descending orders for `startsAt`, `endsAt` and
`title`; the id settles ties in the selected direction, and the default is
`startsAt ASC`. Component keys are read through the
work-component bridge in the same row. A bridge change resolves its parent work
and refreshes that row, never a separate component row. Ordinary viewport
anchors, focus, source recovery and Show/Apply behavior apply. A cancellation,
consumption or expiry removes the row through the usual Apply path rather than
an optimistic client splice.

The page owns three non-reading `ADMIN` actions:
`hilos_status_work_schedule` with
`{title: string, description: ?string, componentKeys: list<string>, startsAt: int,
endsAt: int}`, `hilos_status_work_change` with those fields plus
`{workId: int, publicId: string, expectedVersion: int}`, and
`hilos_status_work_cancel` with
`{workId: int, publicId: string, expectedVersion: int}`. The correlated
`requestId` stays in the action envelope. The page sends typed asks to the
existing Status owner and defers its reply until
`hilos_status_work_write_done` carries the existing `HandoverAnswerSignalData`;
the browser action names do not become agent actions. The page's internal asks
are `status_schedule_work`, `status_change_work` and `status_cancel_work`; the
owner answers them over the declared page-owned reply route. The owner rechecks
the Works page's ADMIN gate and freeze, reconciles maintenance before a write,
then checks public identity and version in its transaction.

The core module owns the `hilosStatusWorks` state and row resolver and exposes
the named create, edit and cancel actions. Editing uses the shared row edit
session, table-row edit source and focus-return path; the baseline contains the
text, component list and both times, and the live row version is sent as
`expectedVersion`. The work window is a `DateTimePicker` datetime range,
`windowPicker`, made on each opening in the reader's IANA zone captured then.
A repeated hour resolves only to the chosen or the loaded occurrence, and a
DST gap is rejected instead of guessed. The Vue, React and Angular dialogs
draw the picker as one field, `Window, <zone>`; Enter from inside that field
while its calendar is open belongs to the calendar, and from any other field it
follows the Save verdict, the narrow calendar sheet included. Action frames
continue to carry epoch milliseconds UTC.
Cancellation remains bound to the focused row's immutable public id; an
unrelated late acknowledgement cannot retarget another dialog. Vue uses the
standard Status admin
page, viewport table, create/edit/cancel modals and action notices; React draws
the same pages, table and modals over the same core controllers. Chat and
Binance mount thin Vue wrappers, Tasks and Ecommerce Shop thin React wrappers,
and Polls and OnlineTesting mount the Angular pages through lazy wrappers that
pass their shared Status context. Every work window states the non-overlap
rule in a hint under it: the parts of the Window field in the Vue, React and
Angular dialogs name that hint in `aria-describedby`.

Overview source changes, including completed-history changes to `historyCount`,
use the existing guarded `PageResender` / `resendPageWhole()` path. The narrow
`hilos_status` branches in `BrowserContext::addBrowserChange()` and
`addBrowserStaleness()` reuse the subscription guards and
`publishedWholeThisFlush`; they add no timer, cache or generic fan-out.
Components, Subscribers, Works and History remain separate page subscriptions,
so an Overview re-answer does not reset another table viewport.

The framework's core module binds `page_response.data.status`, the Subscribers
page's `page_response.data.statusSubscribers` and History's
`page_response.data.statusHistory` through strict schemas and the existing
`pageDataSignal`; it performs no mount fetch. Its state and incident controllers
retain the captured `incidentId`, draft, busy, refusal and timeout lifecycle. A
live update cannot retarget the dialog to a new incident for the same component.
A suppressed candidate remains `Checking` with “Publication canceled
manually”; reaching countdown zero does not publish it or create a server fact.
Moments use `formatMoment()` in the reader's zone; the countdown uses the server
clock through `toLocal()` and `formatCountdown()`.

The SDK exports the admin implementation lazily from
`@hilos/core/admin/status`, `@hilos/vue/admin/status`,
`@hilos/react/admin/status` and `@hilos/angular/admin/status`. The public reader
and its subscription controller are exported from `@hilos/core/status`. The Vue,
React and Angular public view components are exported as `HilosStatusPage` from
their `/status` subpaths, with required props or inputs `{connection, actions}`.
A consumer may use a local alias, for example
`import { HilosStatusPage as HilosPublicStatusPage } from '@hilos/vue/status'`.
The admin `HilosStatusPage` remains in each view kit's `/admin/status` entry.
The subscription modal is controller-owned: Subscribe appears in a body row
below the navigation-only shared header, not in a header button slot.
`HilosStatusSubscribersPage` from the Vue and React admin subpaths takes
`HilosStatusSubscribersContext`, the existing `{scopes, connection, actions}`
Status context. Angular's five admin pages take `HilosStatusContext` in their
required `context` input. Status is not imported by the eager `base` entry of
core, Vue, React or Angular, or by the default `hilosAdminViews()` map of Vue or
React. Angular's context-bound Status pages are also omitted from its default
`hilosAdminViews()` map. Chat and Binance BTC Tracker mount thin Vue wrappers
for the public reader and all five admin pages; Tasks and Ecommerce Shop mount
thin React wrappers for the same six as lazy entries in their app. Polls and
OnlineTesting mount the Angular pages from six lazy entries in `app.ts`, with
the five admin wrappers passing their shared Status context. The public
`/status` route is the framework-owned client file reader; it has no backend
page, catalog entry or `page_response` subscription.

A React Status page creates its state, table and controllers, or its file
reader and subscription controller, in the effect of its own generation and
disposes them in that same effect's cleanup, so a replayed effect or a new
context gets a live set of its own. Angular creates its admin controllers and
tables from the required context input and creates its public reader and
subscription controller from the connection and actions inputs. Computed state
and effects start the current generation; cleanup disposes it when the inputs
change or the page leaves.

The project also registers the shared Status directory, mounts the same volume
for every node's nginx, and binds the public page to the framework reader.
Neither a separate writer per node nor a project copy of the reader is an
activation mechanism. Each of the six demo filesystem contexts registers
`FsContext::STATUS` at `data/status` with `DirectoryScope::CLUSTER`; its nginx
mounts that directory read-only.

### History page and rows

`AbstractHilosStatusHistoryPage` serves `/hilos/status/history` under the
existing `HILOS_INDEX` project binding. It is read-only and adds no table agent.
The current component catalog supplies filter names; saved component keys that
are no longer in that catalog remain selectable with the key as their fallback
label. `data.statusHistory` is null when either historical source is not ready
or cannot be read, including when freeze silence prevents DB reads. The merged
table does not turn a missing source into an empty or partial history.

Rows come only from `Hilos::$db->statusIncidents` with `resolvedAt != null` and
`Hilos::$db->statusMaintenancePeriods` with
`completionObservedAt != null`. Open and suppressed-open incidents are not
rows. A canceled or expired announcement without an actual maintenance period
does not create one. This admin history is separate from the public retained
projection: unpublished candidates remain absent from public retained history.

Each normalized row preserves its source identity as
`incident:<positive-id>` or `maintenance:<positive-id>` and keeps the saved
incident component name or maintenance title, component keys,
`announcementKnown`, nullable `workPublicId` and completion outcome. These
historical values are not replaced by the current component catalog. Incident
outcomes are `resolved`, `recovered_before_publication` and
`canceled_publication`; maintenance uses `maintenance_completed`.

The table searches the saved component name or maintenance title. Its Component
filter uses the stable component key, Outcome offers the four retained outcomes,
and Period filters Start between inclusive from/to calendar dates in the
browser's IANA time zone, with the end boundary at the next local calendar day
so daylight-saving changes follow the calendar boundary.
Sorting is only by Start: descending by default and ascending as its mirror.
The merged order is `(startedAt, kindRank, sourceId)`, with incident rank 0,
maintenance rank 1 and numeric source id as the final tie-breaker.

Typed bounded View/Object reads through `Hilos::$db` return up to 32 rows plus
an edge probe. Numbered edge prefixes use `TableWindowPlan` within the shared
500-row count ceiling. These reads do not load all history, issue SQL from the
page or table, or keep a long-lived ORM history cache. The table total is capped
at that ceiling and marks whether it is exact. The Overview's `historyCount`
remains the exact uncapped scalar total, independent of the table's filters. An
immutable migration change adds the incident source's
`(detected_at DESC, id DESC)` history index. The generated Status incident
schema pairs are already present in all six demos.

Incident duration is `detectedAt` to `resolvedAt`; `publishedAt` is not the
start. Published incidents display `peakSeverity ?? severity`, while
unpublished incidents retain their saved severity. A maintenance period has a
proven duration only when `endedAt` is known. If completion was observed but its
exact end is unknown, the row shows “Completion observed at” and Duration
“Unknown”; the observation time is not used as the end. The saved
`announcementKnown` classification is checked before a missing announcement id
can be described as unannounced. Moments travel as UTC epoch milliseconds and
`formatMoment()` displays them in the reader's zone.

Committed incident and maintenance changes use ordinary browser source fan-out
and the viewport's Show/Apply behavior. Already visible values update without a
window jump; row arrivals, moves and departures follow the ordinary pending
rules. History's filter catalog is republished through coalesced `page_data`
independently, so it does not rebuild a held viewport or apply its pending
changes. The initial response contains both the catalog and window. The
Overview's completed-history count is resent with the whole Overview through
the regular `PageResender`, regardless of History filters.

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

The same `StatusAgent` owns Add and Update on `statusWorks` and
`statusMaintenancePeriods`, and Add and Remove on `statusWorkComponents`. No
agent is created per announcement. The bounded ORM and migration contracts are
below.

### Scheduled works and maintenance storage

With `STATUS` enabled, the framework lazily mounts full Entity/Object/View
chains at `Hilos::$db->statusWorks`, `statusWorkComponents` and
`statusMaintenancePeriods`. Their primary tables are not journaled. All stored
moments are signed `BIGINT` epoch milliseconds UTC. Status refuses a moment
before the epoch on every write path; the column type no longer does (owner
decision, 2026-11-09). All persisted fields are
classified nonpersonal (`_pii = []`); these records carry no actor, session,
address, IP or account relation.

- `hilos_status_work` stores auto-increment `id BIGINT UNSIGNED` primary key,
  immutable unique `public_id CHAR(32)`, `title VARCHAR(200)`, nullable
  `description TEXT`,
  `starts_at`, `ends_at`, `created_at`, `updated_at`, nullable
  `canceled_at`, `consumed_at`, `expired_at`, `version INT`, and nullable
  `last_mail_queued_at`. Titles are limited to 200 characters and descriptions
  to 2000. The public id is generated from 16 secure random bytes. Creation
  starts at version 1; changing, canceling, consuming or expiring increments
  the domain version. The queue handoff timestamp does not.
- `hilos_status_work_component` stores auto-increment primary key
  `id BIGINT UNSIGNED`,
  required `work_id BIGINT UNSIGNED` and `component_key VARCHAR(128)`.
  `(work_id, component_key)` is unique; `component_key` has a key-first
  `(component_key, work_id, id)` index. Its FK cascades with the work; the
  chosen project catalog key has no catalog-table FK. The entity set is owned
  through `work_id`, so the set is one work's selected keys.
- `hilos_status_maintenance_period` stores auto-increment primary key
  `id BIGINT UNSIGNED`,
  unique opaque `public_id CHAR(32)`, nullable `work_id BIGINT UNSIGNED` with
  `ON DELETE SET NULL`, nullable historical `work_public_id CHAR(32)`,
  required `announcement_known BOOLEAN`, `title VARCHAR(200)`,
  `component_keys_json TEXT`,
  `started_at`, `last_observed_at`, nullable `ended_at`, and nullable
  `completion_observed_at`. Its detached key list is decoded by the View; the
  Entity and Object retain scalar persisted fields. Public and transit values
  and the DB View expose that set in canonical string order; valid unsorted v2
  or transit lists decode as the same set. `work_public_id` preserves
  the original announcement identity after the work row is removed.

The work indexes are the unique public id, three pending prefixes that combine
`(canceled_at, consumed_at, expired_at)` with one of `starts_at`, `ends_at` or
`title` followed by `id`, and the window index
`(canceled_at, starts_at, ends_at, id)`. Maintenance periods have
the unique public id, `(started_at DESC, id DESC)`,
`(completion_observed_at, id)`, and `(work_id, id)` indexes. Each work window
uses `[startsAt, endsAt)`; a conflict is an overlapping noncanceled window, so
adjacent windows are allowed and component selections do not divide the
system-wide schedule.

`StatusWork` is a standalone root set. `StatusWorkComponent` is a child set
through `work_id`; `StatusMaintenancePeriod` is standalone but is not a root
set. The component key is a catalog value, not a foreign key to a DB catalog.

Typed View/Object reads keep interval and history queries at the ORM layer:
`conflictingWindow()` finds one overlap; `matchingAnnouncement()` finds an
unused, unexpired announcement covering an observed start; `upcomingPortion()`,
`expiredPortion()` and `StatusWorks::summary()` read bounded work sets or their
count and nearest start. `StatusMaintenancePeriods::intersecting()` reads
active and retained history in bounded portions, and both collections expose
`findByPublicId()` for opaque identity reconciliation. Read portions cap at 32;
callers do not load full history or issue SQL from the agent, page or table.
The work Object's `searchCondition()` is shared by window queries, membership
checks and facet counts; it searches title/description and matches a component
key through a parameterized `EXISTS` on the bridge. The table and caller do not
add joins. Work collection actions create/import rows; a known work row uses
`change`, `cancel`, `consume(at)`,
`consumeObservation(StatusMaintenanceObservation)`, `expire` and
`markMailQueued` item actions. The ordinary `consume(at)` checks the current
`[startsAt, endsAt)` window; the recovery-only observation path uses a validated
snapshot to prove the same public work identity and its original window. It is
an owner reconciliation action, not an admin action or wire API. Component keys
are replaced through bridge actions in that same work-change transaction.
Maintenance-period item actions only monotonically reconcile an owner
observation; the UI does not CRUD history. `StatusWork::$components` is the
selected key bridge; the maintenance-period View exposes a nullable work bridge
only when its saved public id still matches. It never reconstructs a link from
a title.

One transaction in `StatusAgent` owns each write. It replaces the component
bridge in the same transaction as a work change. Public invalidation and
immutable mail notices are registered with `Database::afterCommit()`; a
rollback emits neither. The three units are declared by
`framework/backend/Database/Migration/FrameworkSchemaCatalog.php`, with up/down
create pairs under `framework/backend/Database/Migration/Stub/`. An immutable
change under `framework/backend/Database/Migration/Change/` introduces the
three units. The per-demo `backend/Database/Migration/Schema/` pairs are
generated pull output in all six demos. Genesis and earlier migration changes
are not rewritten.

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
create a new id. Public incident, work and maintenance ids remain opaque
strings; their public shapes expose no primary integer id.

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

Admin History retains completed incidents with their outcomes, including
incidents that recovered before publication or had publication canceled, and
completed maintenance periods. Public retained history still excludes
unpublished candidate incidents.

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
unsubscribe preview after a usable connection exists. A confirmation opens no
window: it waits, confirms and answers in the page's notice slot, as a broken
link's refusal does; only an unsubscribe link opens its window, to ask. An
unsubscribe requires an explicit confirmation; Keep does not delete. A
disconnected or frozen
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
send the owner rechecks the primary id and confirmation. One shared notice
queue drains one task per tick, with at most 32 recipient attempts across
outage and work mail and a cooperative 20 ms portion budget after at least one
attempt. The cursor advances before mail handoff, so an ambiguous send is not
replayed. These tasks are private best-effort memory; stop, freeze or restart
discards them and there is no history replay. A lost task or an SMTP result
that is ambiguous after queueing is accepted. SMTP is not exactly once.
Confirmation mail is also issued only after its transaction commits.
Subscriber addresses, capabilities and saved zones never enter the public
files or public Status projection.

### Scheduled Works And Maintenance Periods

`StatusAgent` handles work schedule, change and cancel asks forwarded by the
ADMIN Works page. Starts must be in the future at creation or when changed;
times must have whole-second precision, titles and descriptions are validated,
and at least one currently declared component key is required. Changing an
unchanged start may keep it in the past until actual maintenance begins.
Noncanceled windows cannot overlap under half-open interval rules; adjacent
windows are allowed. A write addresses the row by both its integer id and
immutable public id and checks `expectedVersion`. Repeating cancellation of
the same canceled row is an idempotent no-op. A work is not editable after
cancellation, consumption, expiry or its scheduled end. Schedule, change and
cancel send a detached event snapshot after the transaction commits. The
schedule clock never starts or ends protected mode.

Actual maintenance begins and ends only with the existing protected-mode
runtime. The Status owner reads that unconditional runtime; a missing or stale
row is unknown and cannot complete an observation. It converts the runtime's
`startedAt` seconds to epoch milliseconds once. One occupied interval remains
one period across operation, phase, node or projected start changes. A clean,
coherent public announcement snapshot can classify the actual start only when
the most recent inactive observation proves that it predates the freeze.
Because protected-mode `startedAt` is recorded in seconds, the proof can include
a snapshot from that same second. Cold, dirty, building, torn, missing or
otherwise unknown source data fails closed. After the first observation, the
sanitized title and identity are retained. An expired window alone never ends
actual maintenance. When an announcement is consumed, its `publicId` links the
period; restore never matches a work by its old integer id.

The feature-owned `Hilos::$rt->hilosStatusMaintenance['current']` row has the
strict shape `{id: string, available: bool, active: bool,
announcementKnown: bool, periodId: string | null, workPublicId: string | null,
title: string | null, componentKeys: list<string>, startsAt: int | null,
endsAt: int | null, startedAt: int | null, lastObservedAt: int | null}`.
Nullable moments are epoch milliseconds UTC, and `id` is the fixed key
`current`. `available=false` does not mean inactive. `StatusAgent`
is the only writer, through row actions; the Overview reads it through declared
RT interest in another worker. Missing, stale or unavailable RT produces an
unknown announcement verdict, not false or an empty work. The Status feature
mounts this collection; protected-mode runtime shape and its owner are
unchanged. Transit cursors and partial DB/file work remain private to the
Status owner and are not shared state.

The private observation store under the logical `$fs->status` directory keeps
one `<period-public-id>.json` file per observed period. Its exact envelope is
`{formatVersion: 1, period: {id, workId, announcementKnown, title,
componentKeys, startedAt, lastObservedAt, endedAt, completionObservedAt},
work: null | {id, title, description, componentKeys, startsAt, endsAt}}`.
These are sanitized public values only; no account, recipient, operation,
phase, node or process fields are present. Start and completion writes use a
same-volume temporary file and atomic publish. File discovery and import are
bounded to 32 entries per pass. The Status worker alone reads or writes transit;
nginx exposes only the two public Status files, never this directory.

The first occupied observation is saved immediately; later `lastObservedAt`
updates follow the public heartbeat cadence. At a continuously observed
inactive transition, `endedAt` and `completionObservedAt` are both that
transition moment. If restart or unavailable source broke continuity,
`completionObservedAt` records when completion was detected while `endedAt`
stays null; `lastObservedAt` remains the last proven occupied moment. The gap
after it is unknown, not maintenance duration. A missing/null freeze source
never ends the period.

On owner recovery, valid transit files and the last coherent public archive
can restore the currently active observation. The archive is sanitized and
does not restore general work schedules or completed history into the DB. Before
an expiry pass or new admin write, recovery resolves the work by opaque
`publicId` and imports or monotonically reconciles periods in one owner
transaction. If a saved snapshot proves that its original window covered the
actual start, recovery can consume the work with that same `publicId` even when
a restore has returned an older schedule window. `consumeObservation()` marks
that identity consumed without restoring the snapshot's old title, interval or
version into the DB; ordinary `consume(at)` still checks the current window. A
reused integer id with another `publicId`, and canceled, expired or missing
work, is left untouched. Transit is removed only after commit. Replays do not
duplicate the period or its first Completed event. A damaged or unwriteable
transit file is ignored or retried and logged; it does not hold the system
closed or stop the independent public heartbeat. If a completion record could
not be saved before owner loss, its exact end is not promised. This transit is
neither an admin History feature nor a mail outbox, and this implementation
does not claim separate cluster or failover proof.

Scheduled, changed, canceled and announced-completed work events use the
existing mailer and four templates: `status_work_scheduled`,
`status_work_changed`, `status_work_canceled` and `status_work_completed`.
`StatusWorkSubscriberNotice` captures the public work snapshot, event moment,
event kind and component-name snapshot when the transaction commits; the task
does not reread a subsequently edited work to build the message. Template
parameters are exactly
`{projectName, statusUrl, unsubscribeUrl, title, description, componentNames,
startsAtLabel, endsAtLabel, changesText, endedAtLabel,
completionObservedAtLabel}`. Nullable parameters remain present. The four
templates produce plain text and escaped HTML inside the existing mail frame;
moment labels use the recipient's saved time zone, offset and zone name. An
inexact completion uses `endedAtLabel: null` and the nonnull
`completionObservedAtLabel`, so the letter reports when completion was
observed without calling it the end.

Work tasks capture the event-time confirmed-recipient boundary and recheck each
subscriber immediately before handing mail to the existing pool. At the end of
a nonempty task, `last_mail_queued_at` is advanced only if every attempted
handoff was accepted and none was ambiguous; no eligible recipients or a
partial/failed handoff does not create a positive mark. This records an accepted
queue handoff, not SMTP delivery. The row's `publicId` is rechecked before the
mark; the timestamp does not change the work version. The immutable event
snapshot stays separate from the latest committed version and request
provenance, which live in a private `StatusWorkMailContext` shared by pending
tasks for that `publicId`. Its after-commit update is independent of whether a
new best-effort enqueue succeeds. An older task can finish a valid handoff
without losing the newer action's provenance during coalescing: the current
version check decides whether that provenance accompanies the mark, while the
public-id check keeps it on the same work. The keyed context cannot attach one
work's origin to another, uses O(1) lookup, and is released after the last task
or when the queue resets on stop, freeze or restart. This is private owner work,
not shared RT state or a durable DB receipt. No durable outbox, SMTP receipt, retry
after owner loss, or mail replay from public or DB history is provided.

## Static Publication

`FsContext::STATUS = 'status'` is a reserved logical directory name reached as
`Hilos::$fs->status`. A project registers it in its filesystem context as
`DirectoryScope::CLUSTER`; `Hilos::init()` refuses Status activation when the
directory is missing or declared `NODE`. The installation chooses the backing
shared disk volume. All six demos register `data/status` as this cluster
directory and mount the same directory read-only into nginx. Each demo cluster
stand provides this directory through its own named volume, shared
by its nodes and entry where present ([filesystem.md](filesystem.md)). The one
cluster Status owner publishes the sanitized projection independently of visitors.
The general registration, cluster marker and shared-volume rules are in
[filesystem.md](filesystem.md).

`StatusPublicSnapshotBuilder` reads the typed `StatusSnapshot`, published
incident history and its timelines, upcoming work, retained maintenance
periods and closed silence history in bounded portions. It retains only
detached public values, never DB or RT objects. `StatusPublicFiles` serializes a
bounded fragment/byte portion per owner pass and uses `FsPath::publish()` to
atomically install each complete file from a temporary file on the same volume.
It restores one coherent pair at owner start; an absent, malformed or
revision-mismatched pair does not replace the last known archive. Publish whole
`status.json`, then whole `heartbeat.json` with the same revision, then the
change hint. A heartbeat-only pass updates `aliveAt` with the last successful
content revision and then sends the hint. A failed build or write is retried
without advancing the heartbeat or sending a success hint; if the files
temporarily disagree, the reader accepts neither revision-mixed pair.

### Public JSON v3

These are JSON shape declarations, not PHP/TS implementation types. All keys
are present: nullable fields serialize as `null`, lists as `[]` when empty.
Existing moments — including `at`, `startsAt` and `endsAt` — remain integer
epoch milliseconds UTC under [A Moment On The Wire](time-zones.md#a-moment-on-the-wire).
The new silence bounds are also epoch milliseconds UTC, but signed so a proven
start before 1970 is retained. All prior moment constraints remain unchanged.
Every public `id`, including `workId`, is opaque and never a DB integer id.
The content `revision` is opaque and has no node id or term; the heartbeat
repeats the last successfully published `status.json` revision.

```text
status.json = {
  schemaVersion: 3,
  revision: string,
  generatedAt: int,
  config: {pollIntervalSeconds: int, staleAfterSeconds: int},
  components: Component[],
  candidates: Candidate[],
  outages: Outage[],
  scheduledWorks: Work[],
  maintenancePeriods: MaintenancePeriod[],
  silencePeriods: SilencePeriod[]
}
heartbeat.json = {schemaVersion: 3, revision: string, aliveAt: int}

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
  id: string, title: string, description: ?string,
  componentKeys: list<string>, startsAt: int, endsAt: int
}
MaintenancePeriod = {
  id: string, workId: ?string, announcementKnown: bool,
  title: string, componentKeys: list<string>, startedAt: int,
  lastObservedAt: int, endedAt: ?int, completionObservedAt: ?int
}
SilencePeriod = {id: string, startedAt: int, endedAt: int}
```

Only closed silence periods appear in v3. Their bounds form a half-open
interval, require `endedAt > startedAt`, and use unique opaque IDs in stable
`(startedAt, id)` order. The 90-day public window includes periods that
intersect it; a long period keeps its original `startedAt` rather than being
clipped to the window. No internal row id, node, PID or reason is published.
`heartbeat.json` keeps exactly its existing three keys.

The writer always emits schema version 3 in both files. PHP recovery and the
TypeScript reader accept versions 1, 2 and 3; a pair is accepted only when both
files have the same supported version and revision. The actual earlier v1 shape
still requires empty `scheduledWorks` and `maintenancePeriods`; nonempty lists
remain unsupported. V2 has no `silencePeriods`. Their other shape and moment
limits remain as before; this is not a migration of older Status moments.
For both v1 and v2, the reader supplies an empty silence-period list.
Before a readable v1 or v2 pair can be followed by a v3 heartbeat, the owner
publishes a complete matching v3 pair. Accepted outage fields remain in the
public shape through that upgrade.

### Closed Publication Gaps

Before every proposed heartbeat, the owner compares its time with the `aliveAt`
of the last successful heartbeat. A gap qualifies only when
`now - aliveAt > staleAfterSeconds * 1000`, including before a heartbeat-only
update and for a live owner, a cold restart or a new owner. Its start is that
last successful `aliveAt`; a fresh shared heartbeat after an owner change is
not a new gap. A shorter gap is not retained, and a failed write, attempt time
or `generatedAt` is not evidence of new liveness. Without either a trusted
prior `aliveAt` or a validated unfinished observation, no start is inferred.

The owner durably saves a qualified open observation before publishing a fresh
heartbeat. If that private save fails, the heartbeat stays blocked. The end is
the `aliveAt` receipt of the first newly published coherent pair. The owner
reserves and fills that end in the final bounded status serialization pass;
the archive is not serialized in one pass. If `status.json` installs but the
heartbeat write fails, retry rebuilds the closing end from that retry's time
before trying the heartbeat again. Only a successful receipt makes the closed
period the published snapshot. The owner then saves the closed transit; after a
crash between receipt and that save, recovery closes the matching private ID
from the same coherent public pair. Existing limits remain 64 fragments and
64 KiB per serialization pass. Private transit discovery visits at most 32
directory entries per pass, and owner import handles at most 32 closed
observations per pass. Recovery is detached work and does no DB work under
freeze.

The durable archive is `Hilos::$db->statusSilencePeriods`, mounted only when
Status is active with `Objects::LAZY_STRATEGY_KEY` and owned by `StatusAgent`
for collection `Add` and `Remove`.
Its primary table `hilos_status_silence_period` has `id BIGINT UNSIGNED`,
`public_id CHAR(32)`, `started_at BIGINT` and `ended_at BIGINT`, all not null;
the two moment columns are signed epoch milliseconds with no SQL clock
defaults. `id` is the auto-increment primary key, `public_id` is unique,
`(ended_at, id)` is indexed, and `ended_at > started_at` is checked. The public
ID is a secure opaque 16-byte hex value. Importing that ID with identical
bounds returns the existing row; different bounds for the same ID are refused.
The entity is not journaled, and all four columns are classified as non-personal.
The working archive is append-only and is not deleted at 90 days: that limit
applies only to public projection. Exact test cleanup is the only removal and
deletes through the known item's actions in its own transaction.

Open and not-yet-imported closed observations use private transit on the same
cluster Status volume at `silence-observations/<publicId>.json`, with shape
`{formatVersion: 1, period: {id, startedAt, endedAt}}`; its moments are signed
epoch milliseconds and `endedAt` is `null` only while the new coherent pair has
not succeeded. The directory is mode 0700 and files are mode 0600; reads are
capped at 1024 bytes. Writes use a same-volume temporary file and atomic
publish. Symlinks and malformed or unreadable records are not trusted. A damaged
or inaccessible record is logged and retried in bounded recovery without
inventing a boundary or ID. Nginx does not serve this transit.

A full database backup carries the closed rows. After rehydrate makes the
restored database readable, the public archive is rebuilt from that database
and valid closed transit that has not yet been imported. A closed interval in
the public copy never repopulates a row missing from a restored database, so
already imported rows newer than the backup roll back with it. Backup time and
`generatedAt` do not prove a gap start; a new machine without a prior heartbeat
does not invent a multi-day gap. A hot restore need not stop the heartbeat.
No backup or rehydrate protocol changes are part of this behavior.

Apply these publication limits:

- Components retain project declaration order. `agentType` is admin-only.
- Candidates contain current Checking only, with no internal id, publication
  deadline or manual cancellation reason.
- Outages contain published open outages and published completed outages
  intersecting the last 90 days. Automatic update text is safe public wording
  selected by fact code; team text is the administrator's published wording.
  Each outage uses an opaque public id, never the internal integer id.
  Unpublished, suppressed and canceled candidates never become outage history.
- `scheduledWorks` contains unused, unexpired announcements. The active
  period's known announcement is also retained in the public snapshot as a
  reference, even after its window ends; the reader does not show a consumed
  announcement as upcoming. Canceled announcements are not published.
- `maintenancePeriods` contains active periods and completed periods within
  the 90-day retention window. A completed period with an exact `endedAt` uses
  that boundary; when its end is unknown, retention uses
  `completionObservedAt` without treating it as the end. The current active
  interval proves occupancy only through `lastObservedAt`.
- `completionObservedAt: null` means active. A nonnull value means completion
  was observed, even when `endedAt` is null. Continuous observation records
  `endedAt` and `completionObservedAt` at the same transition moment. A gap
  leaves `endedAt: null`; do not infer duration or color the unknown interval
  after `lastObservedAt`.
- `workId: null` is classified by `announcementKnown`: `true` means the owner
  knows this was an unannounced freeze, while `false` means it cannot prove
  whether an announcement matched. Never display unknown as unannounced.
  `title` and `componentKeys` are sanitized historical snapshots.
- A full freeze paints every declared component as Maintenance. The announced
  `componentKeys` describe planned scope and do not limit which components the
  actual full freeze affects.
- Never publish subscribers, email addresses, tokens, session time zones,
  operation or phase, PIDs, node ids/IPs, agent types, raw Daemon pictures,
  private error or exception text, or hidden candidates in history. Work
  versions, DB ids, config and revision exist only to read or mutate the
  private records and public projection correctly.

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

### Test-only moments

Three moments in this feature belong to the production clock: the heartbeat
cadence (30 seconds), the stale limit that turns a silent publisher into “not
responding” (120 seconds), and the scheduled start of an announced work. A spec
does not wait one out, and no environment value or compose overlay shortens one
([Time-based features](../cli/commands.md#time-based-features-no-universal-clock));
the three moment commands bring them to now (HIL-1856). They are `test:` commands
that refuse on a production-like environment and use the existing command
channel. The CLI half only sends and prints; the Status owner (`HILOS_STATUS`, one
per cluster) answers the moment commands and the separate exact-cleanup command
from `onSignalCommand()`, because it owns publication and the archive. The owner
answers every command path once; a deferred command keeps one correlation until
a receipt or error. See [Status moment and settings write commands](../cli/commands.md#status-moment-and-settings-write-commands).

- **`test:status:heartbeat:stale` ages the heartbeat.** It needs a coherent
  published pair and otherwise refuses with “No public Status pair is published
  yet.” The owner atomically publishes `heartbeat.json` with the last pair's
  revision and `aliveAt` = now − `staleAfterSeconds` × 1000 − 1000 ms, sends
  `status_files_changed`, and holds its publication until `now` or restart.
  `status.json` is not touched. The reply is
  `{aliveAt: int, silencePeriodId: string}`; repeating `stale` during the same
  hold returns the same ID and original start. Starting work does not lift the
  hold either.
- **`test:status:heartbeat:now` releases the hold and resumes the ordinary
  bounded publisher.** The owner makes a heartbeat due and advances the same
  publication path as a normal tick, including its freeze-aware maintenance and
  freeze checks. It does not loop synchronously while a full pair is being
  built: it keeps at most one pending request and replies once after the actual
  heartbeat receipt, or with a bounded error if publication fails, the owner
  stops, or the deadline expires. The existing `statusMoments.mjs` helper keeps
  its five-second command-channel budget. The command works under freeze because
  the Status publisher can use its detached public values there without DB access.
  Before any coherent pair exists, it still refuses with “No public Status pair is
  published yet.” Its successful reply remains `{aliveAt: int}`.
- **`test:status:work:start <publicId>` starts an announced work now.** In an
  ordinary work write — nothing under a freeze, the maintenance reconciliation
  first, one transaction, the item's own `change` action — the owner moves the
  `starts_at` of an unused, uncanceled, unexpired work to the current moment
  rounded down to a whole second. The version goes up by one only when the start
  actually moves: a work whose start is already this second is no change to the
  item's action, which writes nothing and keeps its version, and the reply then
  carries the version as it was. Either way, after the commit the public
  projection is marked to be read again, with the same pair of marks an
  administrator's work write sets. **No mail goes to subscribers:** this moves a test's clock, it does
  not change what was announced. It skips the rule that a start must lie in the
  future ([Scheduled Works And Maintenance Periods](#scheduled-works-and-maintenance-periods)),
  which is why it exists, and keeps the rest. It refuses in the administrator's
  words: the work no longer exists; it was canceled, started or expired; the new
  window would overlap another announcement; under a freeze, that Status changes
  are unavailable. A blank public id is refused with “Name the maintenance
  announcement by its public id.” The reply is the public id, the new `startsAt`
  and the version.
- **`test:status:silence:remove <publicId>` removes exactly one closed test
  interval.** The request and successful reply carry `{publicId: string}`. The
  CLI validates the positional ID and prints
  `Removed closed Status silence period <publicId>` only after the owner has
  published its absence. The owner refuses while freeze or DB access is
  unavailable, while publication is held or another command is pending, while
  recovery is incomplete, or while the named interval is still open. If closed
  transit exists, the owner first imports the exact ID and bounds idempotently
  and removes that transit; only then does it delete the known DB item through
  its actions in a transaction. It then rebuilds the public projection and
  answers after a successful receipt confirms the ID is absent. The command is
  idempotent, test-only and declared in the owner's `AGENT_COMMANDS`; its
  command-channel route is derived from that declaration, with no manual
  `SignalRouter` entry. The CLI does no SQL or filesystem work, and no browser
  hint is sent before the DB delete commits. No browser signal or DTO,
  `page_response`, `AGENT_SIGNALS`, page route, RT field, public HTTP address,
  environment key or timer is added.

The stale command saves its open observation before replying, so a spec can
register the exact `silencePeriodId` for cleanup. A spec releases the hold
first, then uses its existing maintenance cleanup and lift path, waits for the
owner to recover, and removes only that ID. The standalone stop/start journey
keeps its existing start/release flow, then beats and removes its own ID. The shared e2e
helpers are `staleStatusHeartbeat`, `beatStatusHeartbeat`,
`removeStatusSilencePeriod` and `startStatusWork` in
`framework/frontend/scripts/statusMoments.mjs`; they use the existing five-
second command-channel budget. No spec waits for a production clock or shortens
one through the stand.

**Why the owner holds its publication after the stale command.** The next
heartbeat is counted from the one last published, so without the hold the owner
would publish a fresh one on its next tick and the aged moment would be gone
before a reader looked at it. A stopping daemon is the second reason: `docker
stop` can still let the owner publish one last genuine pair, which would replace the
aged one that nginx is meant to keep serving. The hold is a field of the owner's
instance, not Runtime state: nobody else reads it, and it dies with the instance,
so a restart releases it. The command that beats at now also clears the one-second
retry barrier of the heartbeat attempt.

**A spec that ages the heartbeat releases it.** Until `test:status:heartbeat:now`
or a restart, the owner publishes nothing more, and every later spec on the stand
would read a stale page. The spec that called the stale command calls the beat in
its `finally`, as the owned-cleanup canon asks
([Preconditions — test commands with owned cleanup](../frontend/testing-strategy.md#preconditions--test-commands-with-owned-cleanup)).
The e2e helpers are `staleStatusHeartbeat`, `beatStatusHeartbeat` and
`startStatusWork` in `framework/frontend/scripts/statusMoments.mjs`.

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
reader's current clock, even with a live socket. Once `now - aliveAt` is
strictly greater than `staleAfterSeconds * 1000` (default 120 seconds), say
**not responding since** `<aliveAt>` and show current components as Unknown.
Stale operational data is not proof of current health. Empty, malformed,
missing or temporarily unreadable files do not replace the last accepted
coherent pair; retain it and continue aging its accepted heartbeat. Staleness
hides the current Checking list while retaining accepted outages, scheduled
facts and completed history. An accepted active maintenance period remains
visible as a retained observation with `confirmedCurrent: false`; it is not
evidence that the freeze is still active. Without any accepted pair, the banner
and component knowledge are Unknown; never invent Operational. After a
successful recovery pair, current states return to the ordinary projection,
while the newly closed silence period remains in the 90-day public projection.

The public surface shows an overall banner, component states, Checking
candidates, published open outages and their timelines, unused announcements
whose scheduled windows have not ended, active maintenance observations, and
completed outage and maintenance history. A window remains an announcement
after its scheduled start while it remains unexpired; the actual period begins
only when protected mode starts and consumes it. Under a fresh pair, the banner
gives known maintenance priority, then Major outage, Partial outage and
Checking; Operational requires every component to be operational. Staleness
overrides the current verdict with “not responding”.

The 90-day calendar subtracts the union of closed silence periods and a current
`[aliveAt, now)` silence, which the reader calculates locally only after the
strict stale threshold. Reader-zone calendar days use the shared calendar
boundaries, including DST. For each elapsed part of a day,
it removes that union from overlapping published outage and actual maintenance
intervals before selecting the day's worst known event. A half-open interval
ending at midnight does not color the next day; a long silence is not clipped
in the public file. An open outage does not make its silence interval known.
`PublicStatusDay` keeps `state` as the worst known event outside silence and
adds the independent `hasNoData` flag, which is true when any elapsed part of
that reader-zone day intersects silence. `publicStatusDayStrips(status, days, now, heartbeat)`
takes the accepted heartbeat as its required fourth argument, and
`publicStatusDayLabel(day)` supplies the date and words shared by day controls
and selected-day detail. Without silence, priority remains Major,
Partial, Maintenance, then Operational. A day with `hasNoData` uses the shared,
theme-aware gray No data treatment in all three SDKs; a known Major, Partial or
Maintenance fact outside the gap gets a persistent marker, while Operational
gets no marker. When a day has no known event outside silence, including a day
containing only silence, it is No data with no green Operational marker. The
shared day label and selected-day detail name both facts accessibly, for example
“No data · Major outage
recorded”; the state does not add No data to current `PublicStatusState` or the
outage-severity scale. The wide strip covers 90 days and the narrow strip its
last 30. Components, incident outcomes and `resolvedAt`, durations, archive
cards and mail are unchanged. Completed maintenance with an unknown end reports
its completion observation moment and does not invent an exact duration or
paint an unknown gap. Public “Completed” history is the reader's projection,
not the separate admin History page. Show no SLA percentage. Use the shared
time mechanism for calendar boundaries and the core formatter in the reader's
zone, including the browser's zone for a guest; do not reproduce the mockup's
UTC screen labels (D-248). The [time-zone canon](time-zones.md) owns zone
selection and formatting, including recipient-zone mail.

## Freeze And Disconnection

`/status` is the narrow exception to subscription waiting, the freeze stub,
disconnect blocking and the ordinary stale-data screen. The built Vue, React
and Angular readers remain available on a cold load during full freeze; the
maintenance stub links to it as **Service status**. A disconnected shell may
indicate the break, but cannot cover the file reader with a blocking overlay.
The exception covers this read-only page's first-frame paint, disconnect,
stale heartbeat and access reactions only; it does not unblock shell requests,
actions or modal controls.
Other pages keep their existing rules. In React the exception lives in three
shell files. `HilosLayout.tsx` keeps the reader in place of the maintenance
stub, the first-frame hold, the blocked-account card and the frozen-account
screen, gives the page DOM a second interaction state while the shell's dialogs
keep the caller's policy, and never draws the verification banner on this
route. While the freeze is active or the first frame is held, the brand stays
here as passive text where other routes drop it; otherwise it is the ordinary
link. `HilosView.tsx` clears the departure copy of the previous page when the
route becomes `/status`, even while the lazy reader code loads.
`HilosMaintenance.tsx` carries the **Service status** link in every branch of
the stub. The Angular layout keeps the public reader outside the maintenance
stub and blocked/frozen cards, omits the verification banner and leaves the
brand as passive text. The public page region has its own interaction state
while the shell keeps its caller's `HILOS_CONNECTION_INTERACTION` policy; it
does not enable shell controls or use global `allowDisconnectedInteraction`.
Its `HilosView` clears the departure copy on entry, and `HilosMaintenance`
carries the native **Service status** link in every branch.

Do not show the subscription button without a usable live connection. Every
subscription action and subscriber or work mail pass checks that the Status
database source is ready and the freeze permits the work; an occupied or
unavailable freeze runtime refuses the operation before subscriber access or
mail. Public file reading grants no offline mutation path or permission to
bypass a freeze for subscription actions.

The publisher's file half survives full freeze, or normal Maintenance would
become “not responding” after the stale threshold. `HILOS_STATUS` is preserved
with `HILOS_MAIL` by both `WorkerServer::protectedModeRefusesStart()` and
`WorkerServer::stopAgentsForProtectedMode()`, including agents awaiting a
worker. This permits only the public projection and heartbeat work, with no new
master I/O, child process or background process.

In every occupied freeze phase, the Status owner publishes from its detached
sanitized archive, current maintenance observation, recovered silence transit
and the freeze fact. It does not read DB-backed Status data or settings, observe
components, perform DB ORM actions or deliver subscriber or work mail. Its public files contain no
operation or phase, node/process/network details,
agent type, internal error, subscriber address, token or session time zone.
Entry abandons the owner's component baseline and partial public build; after
lift it obtains a fresh Daemon picture and resumes only from permitted data.
On a cold owner start it restores a revision-matched file pair and validates
private maintenance and silence transit. Without a valid public pair the reader
begins Unknown. A known occupied freeze publishes Maintenance while retaining
the restored sanitized archive and active observations; a missing or stale freeze
runtime row publishes Unknown. Agents stopped by the freeze do not become
outage facts. This is no exception for ordinary DB ORM writes.

Serving these files is independent of the daemon, not of nginx or its machine.
If nginx or the whole host is unavailable, the page cannot open. External
hosting and external monitoring are outside this feature.

## Implementation Status And Leaf Owners

The built feature is split by owner below. The source paths also identify the
current validation surfaces.

| Work | Source and test evidence | Owner and state |
|---|---|---|
| Framework activation, component catalog, status owner, DB/RT machine, candidate notices and typed admin read | `framework/backend/Status/`, `framework/tests/Unit/Status*Test.php`, `framework/tests/Integration/Status*Test.php` | Landed by HIL-1688 |
| Admin Overview and Components pages, read-only viewport, gatekeeper actions, shared page head, Vue, React and Angular views | `framework/backend/Pages/Status/`, `framework/backend/Tables/Status/`, `framework/frontend/core/src/admin/status/`, `framework/frontend/vue/src/admin/status/`, `framework/frontend/react/src/admin/status/HilosStatusPage.tsx`, `HilosStatusIncidentModal.tsx`, `HilosStatusComponentsPage.tsx`, `framework/frontend/angular/src/admin/status/` | Landed by HIL-1689; React views landed with HIL-1695; Angular's five page views landed with HIL-1696 |
| SDK packaging and lazy entrypoints | `framework/frontend/core/test/packaging/coreEntryPoints.test.ts`, `framework/frontend/core/test/bin/initialBundleBudget.test.mjs`, `framework/frontend/vue/test/packaging.smoke.mjs`, `framework/frontend/react/test/packaging.smoke.mjs`, `framework/frontend/react/package.json`, `framework/frontend/react/vite.config.ts`, `framework/frontend/angular/admin/status/`, `framework/frontend/angular/status/`, `framework/frontend/angular/test/packaging.smoke.mjs` | Core, Vue, React and Angular export `/admin/status` lazily and `/status` as a separate entry; eager base closures remain free of Status. |
| Public files, bounded builder, heartbeat, hint and reader | `framework/backend/Status/StatusPublicSnapshotBuilder.php`, `framework/backend/Status/StatusPublicFiles.php`, `framework/tests/Unit/StatusPublicFilesTest.php`, `framework/tests/Integration/StatusPublicFilesIntegrationTest.php`; `framework/frontend/core/src/status/`, `framework/frontend/core/test/status/` | The original public pair landed by HIL-1690; its v3 silence archive and current-gap derivation landed in HIL-1886. |
| Closed silence archive, private recovery transit and test cleanup | `framework/backend/Status/StatusPublicSilencePeriod.php`, `StatusSilenceObservation.php`, `StatusSilenceObservationStore.php`, `StatusAgent.php`; `framework/backend/Database/Entity/Item/StatusSilencePeriod.php`, `framework/backend/Database/Actions/Collection/StatusSilencePeriodsActions.php`, `framework/backend/Database/View/Collection/StatusSilencePeriods.php`, `framework/backend/Database/Migration/Change/20261105060052889668_HIL-1886_status_silence_period/`; `framework/backend/Core/CLI/Commands/StatusTestSilenceRemoveCommand.php`; `framework/tests/Unit/StatusSilenceObservationTest.php`, `StatusSilenceObservationStoreTest.php`, `StatusPublicFilesTest.php`; `framework/tests/Integration/StatusSilenceSchemaIntegrationTest.php`, `StatusSilenceStorageIntegrationTest.php`, `StatusSilencePeriodsIntegrationTest.php`, `StatusPublicFilesIntegrationTest.php`, `StatusTestCommandsIntegrationTest.php` | Implemented by HIL-1886. The DB archive is durable; public output retains only its 90-day projection, and exact cleanup stays on the existing command channel. |
| Two-fact public calendar | `framework/frontend/core/src/status/statusFiles.ts`, `derivedStatus.ts`; `framework/frontend/vue/src/public/HilosStatusPage.vue`, `framework/frontend/react/src/public/HilosStatusPage.tsx`, `framework/frontend/angular/src/public/HilosStatusPage.ts`; `framework/frontend/core/test/status/statusFiles.test.ts`, `derivedStatus.test.ts`, `publicStatus.test.ts`; the three SDK public-page tests | Implemented by HIL-1886. The calendar preserves No data and the worst known event as separate facts, with no independent cluster or failover proof claimed. |
| Public route, Vue, React and Angular pages and standalone browser coverage | `framework/frontend/core/src/routing/hilosPages.ts`, `framework/frontend/core/src/routing/HilosRouter.ts`, `framework/frontend/core/src/routing/pageTitle.ts`; `framework/frontend/e2e/publicStatus.ts`, `framework/frontend/e2e/test/publicStatus.test.ts`; `framework/frontend/vue/src/public/HilosStatusPage.vue` and `framework/frontend/vue/src/public/HilosStatusPage.test.ts`; `framework/frontend/react/src/public/HilosStatusPage.tsx` and `framework/frontend/react/test/HilosPublicStatusPage.test.tsx`; `framework/frontend/angular/src/public/HilosStatusPage.ts`, `framework/frontend/angular/test/HilosPublicStatusPage.test.ts`; `demo/binance-btc-tracker/tests/e2e/tests/status.spec.ts`, `demo/binance-btc-tracker/tests/e2e/standalone/loss.spec.ts`, and `demo/binance-btc-tracker/tests/e2e/tests/a11y.spec.ts`; `demo/ecommerce-shop/tests/e2e/tests/status.spec.ts` and `demo/ecommerce-shop/tests/e2e/tests/a11y.spec.ts`; `demo/tasks/tests/e2e/tests/smoke.spec.ts` and `demo/tasks/tests/e2e/tests/a11y.spec.ts` | Core route and Vue, React and Angular readers are built; the two Binance journeys cover silence identity/bounds and warm/cold reading, not cluster failover. Polls and OnlineTesting mount the Angular public reader. |
| Guest subscriptions, primary storage, links and mail | `framework/backend/Status/StatusAgent.php`, `framework/backend/Status/StatusSubscriptionLink.php`, `framework/backend/Status/StatusSubscriberMailTask.php`, `framework/backend/Database/Entity/Item/StatusSubscriber.php`, `framework/backend/Database/Actions/Collection/StatusSubscribersActions.php`, `framework/backend/Database/Actions/Item/StatusSubscriberActions.php`, `framework/backend/Mail/Template/Status*MailTemplate.php`; `framework/tests/Unit/StatusGuestActionDTOTest.php`, `StatusSubscriptionLinkTest.php`, `StatusMailTemplatesTest.php`; `framework/tests/Integration/StatusSubscriberSchemaIntegrationTest.php`, `StatusSubscriberStorageIntegrationTest.php`, `StatusSubscriptionIntegrationTest.php`, `StatusSubscriberMailIntegrationTest.php` | Landed by HIL-1691; storage, guest actions and best-effort mail are documented above |
| Subscribers admin viewport and public subscription controller | `framework/backend/Pages/Status/AbstractHilosStatusSubscribersPage.php`, `framework/backend/Tables/Status/HilosStatusSubscribersTable.php`; `framework/frontend/core/src/admin/status/hilosStatusSubscribers.ts`, `framework/frontend/core/src/status/statusSubscription.ts`, `framework/frontend/vue/src/admin/status/HilosStatusSubscribersPage.vue`, `framework/frontend/vue/src/public/HilosStatusSubscriptionModal.vue`, `framework/frontend/react/src/admin/status/HilosStatusSubscribersPage.tsx`, `HilosStatusRemoveSubscriberModal.tsx`, `framework/frontend/react/src/public/HilosStatusSubscriptionModal.tsx`; `framework/tests/Unit/StatusSubscriberTableTest.php`, `StatusSubscriberAdminIntegrationTest.php`; `framework/frontend/core/test/admin/status/hilosStatusSubscribers.test.ts`, `framework/frontend/vue/src/admin/status/HilosStatusSubscribersPage.test.ts`, `framework/frontend/react/test/HilosStatusSubscribersPage.test.tsx`, `HilosStatusRemoveSubscriberModal.test.tsx`, `HilosStatusSubscriptionModal.test.tsx`; `demo/chat/tests/e2e/tests/status-subscribers.spec.ts`, `demo/binance-btc-tracker/tests/e2e/tests/status-subscriptions.spec.ts`, `demo/ecommerce-shop/tests/e2e/tests/status-subscriptions.spec.ts`; `framework/frontend/e2e/statusSubscriptions.ts` | Landed by HIL-1691; Chat and Binance carry the built Vue pages, Tasks and Ecommerce Shop the React pages built by HIL-1695; Polls and OnlineTesting mount the Angular pages built by HIL-1696. |
| Email throttle scope and per-parking-agent verdict route | `framework/backend/Auth/Throttle/ThrottleEmailIdentityInterface.php`, `ThrottleIdentity.php`, `ThrottleVerdictRoute.php`, `framework/backend/Status/StatusAgent.php`, `framework/backend/Core/Topology/TopologyValidator.php`, `framework/backend/Core/Page/PageSignalRouter.php`; `framework/tests/Unit/ThrottleVerdictRouteTest.php`, `StatusThrottleVerdictRoutingTest.php`, `TopologyValidatorTest.php`, `PageSignalRouterThrottlePoolTest.php`; `framework/tests/Integration/AuthThrottleIntegrationTest.php`, `StatusSubscriptionIntegrationTest.php` | Landed by HIL-1691; the ordinary auth verdict remains on its existing host |
| Scheduled works, maintenance observation and Completed delivery | `framework/backend/Database/Entity/Item/StatusWork.php`, `framework/backend/Database/Entity/Item/StatusWorkComponent.php`, `framework/backend/Database/Entity/Item/StatusMaintenancePeriod.php`; `framework/backend/Status/StatusAgent.php`, `framework/backend/Status/StatusMaintenanceObservation.php`, `framework/backend/Status/StatusMaintenanceObservationStore.php`, `framework/backend/Status/StatusPublicWork.php`, `framework/backend/Status/StatusPublicMaintenancePeriod.php`, `framework/backend/Status/StatusPublicSnapshot.php`, `framework/backend/Status/StatusPublicFiles.php`, `framework/backend/Status/StatusPublicSnapshotBuilder.php`; `framework/backend/Pages/Status/AbstractHilosStatusWorksPage.php`, `framework/backend/Tables/Status/HilosStatusWorksTable.php`; `framework/frontend/core/src/admin/status/hilosStatusWorks.ts`, `framework/frontend/core/src/admin/status/hilosStatusWorkActions.ts`, `framework/frontend/core/src/timeZone/localDateTime.ts`, `framework/frontend/core/src/status/statusFiles.ts`, `framework/frontend/core/src/status/derivedStatus.ts`; `framework/frontend/vue/src/admin/status/HilosStatusWorksPage.vue`, `framework/frontend/vue/src/admin/status/HilosStatusWorkModal.vue`, `framework/frontend/vue/src/admin/status/HilosStatusCancelWorkModal.vue`, `framework/frontend/vue/src/public/HilosStatusPage.vue`, `framework/frontend/react/src/admin/status/HilosStatusWorksPage.tsx`, `HilosStatusWorkModal.tsx`, `HilosStatusCancelWorkModal.tsx`, `framework/frontend/react/src/public/HilosStatusPage.tsx`; `demo/chat/frontend/src/views/Hilos/Status/Works/Works.vue`, `demo/binance-btc-tracker/frontend/src/views/Hilos/Status/Works/Works.vue`, `demo/tasks/frontend/src/views/Hilos/Status/Works/Works.tsx`, `demo/ecommerce-shop/frontend/src/views/Hilos/Status/Works/Works.tsx`; `framework/tests/Integration/StatusWorksSchemaIntegrationTest.php`, `framework/tests/Integration/StatusMaintenancePeriodsIntegrationTest.php`, `framework/tests/Integration/StatusWorkMailIntegrationTest.php`; `demo/binance-btc-tracker/tests/e2e/tests/status-works.spec.ts`, `demo/ecommerce-shop/tests/e2e/tests/status-works.spec.ts` | Implemented by HIL-1692, with the React page and windows added by HIL-1695; source and validation paths are listed here |
| Admin History page, merged completed facts and Overview count | `framework/backend/Pages/Status/AbstractHilosStatusHistoryPage.php`, `framework/backend/Status/DTO/StatusHistory*.php`, `framework/backend/Status/DTO/StatusOverviewData.php`, `framework/backend/Tables/Status/HilosStatusHistoryTable.php`, `framework/backend/Tables/Status/HilosStatusHistoryTableRow.php`, `framework/backend/Core/Browser/Context/BrowserContext.php`, `framework/backend/Core/Feature/Definition/StatusFeature.php`, `framework/backend/Database/Migration/Change/20261029183938100830_HIL-1693_status_history_order/up.sql`; `framework/frontend/core/src/admin/status/hilosStatusHistory.ts`, `framework/frontend/vue/src/admin/status/HilosStatusHistoryPage.vue`, `framework/frontend/vue/src/admin/status/HilosStatusPage.vue`, `framework/frontend/react/src/admin/status/HilosStatusHistoryPage.tsx`, `framework/frontend/react/src/admin/status/HilosStatusPage.tsx`; `demo/chat/frontend/src/views/Hilos/Status/History/History.vue`, `demo/binance-btc-tracker/frontend/src/views/Hilos/Status/History/History.vue`, `demo/tasks/frontend/src/views/Hilos/Status/History/History.tsx`, `demo/ecommerce-shop/frontend/src/views/Hilos/Status/History/History.tsx`; `framework/tests/Unit/StatusHistoryProjectionTest.php`, `StatusHistoryTableTest.php`, `StatusOverviewProjectionTest.php`, `StatusOverviewPageTest.php`, `StatusFeatureActivationTest.php`; `framework/tests/Integration/StatusHistorySchemaIntegrationTest.php`, `StatusHistoryTableIntegrationTest.php`, `StatusHistoryFanoutIntegrationTest.php`, `StatusHistoryPageIntegrationTest.php`; `framework/frontend/core/test/admin/status/hilosStatusHistory.test.ts`, `framework/frontend/vue/src/admin/status/HilosStatusHistoryPage.test.ts`, `HilosStatusPage.test.ts`, `framework/frontend/react/test/HilosStatusHistoryPage.test.tsx`, `HilosStatusPage.test.tsx` | Landed by HIL-1693; Chat and Binance mount the built Vue page, Tasks and Ecommerce Shop the React page built by HIL-1695; Polls and OnlineTesting mount the Angular History page built by HIL-1696. |
| Separate cluster and failover proof | — | (not proven yet — not scheduled) |
| React UI parity, including public freeze/disconnection behavior | `framework/frontend/react/src/HilosLayout.tsx`, `HilosView.tsx`, `HilosMaintenance.tsx`, `ConflictActions.tsx`; `framework/frontend/react/src/admin/status/index.ts`, `framework/frontend/react/src/status/index.ts`, the then-present `framework/frontend/react/src/index.ts` (historical root barrel, since removed); `framework/frontend/core/src/routing/hilosUnbuiltPages.ts`; `demo/tasks/frontend/src/App.tsx`, `demo/tasks/frontend/src/views/Status/Status.tsx`, `demo/tasks/frontend/src/views/Hilos/Status/`; `demo/ecommerce-shop/frontend/src/App.tsx`, `demo/ecommerce-shop/frontend/src/views/Status/Status.tsx`, `demo/ecommerce-shop/frontend/src/views/Hilos/Status/`; `framework/frontend/react/test/HilosLayout.test.tsx`, `HilosView.test.tsx`, `HilosMaintenance.test.tsx`, `ConflictActions.test.tsx`, `HilosPublicStatusPage.test.tsx`, `HilosStatus*.test.tsx`, `statusFixtures.tsx`; `framework/frontend/core/test/routing/hilosUnbuiltPages.test.ts` | Landed by HIL-1695; `HILOS_UNBUILT_PAGES` no longer lists `react` for the six Status keys; React `ConflictActions.tsx` keeps the focus in the dialog when a conflict resolves, as the Vue one does |
| Angular UI parity, including public freeze/disconnection behavior | `framework/frontend/angular/src/admin/status/`, `framework/frontend/angular/src/public/HilosStatusPage.ts`, `HilosStatusSubscriptionModal.ts`, `framework/frontend/angular/src/HilosLayout.ts`, `HilosView.ts`, `HilosMaintenance.ts`; `framework/frontend/core/src/routing/hilosUnbuiltPages.ts`; `framework/frontend/angular/test/HilosStatusPage.test.ts`, `HilosStatusComponentsPage.test.ts`, `HilosStatusSubscribersPage.test.ts`, `HilosStatusWorksPage.test.ts`, `HilosStatusHistoryPage.test.ts`, `HilosPublicStatusPage.test.ts`, `HilosStatusSubscriptionModal.test.ts`, `HilosLayout.test.ts`, `HilosView.test.ts`, `HilosMaintenance.test.ts`, `packaging.smoke.mjs`; `demo/polls/frontend/src/app/views/hilos/status/`, `demo/polls/frontend/src/app/views/status/status.ts`, `demo/polls/frontend/src/app/app.ts`; `demo/online-testing/frontend/src/app/views/hilos/status/`, `demo/online-testing/frontend/src/app/views/status/status.ts`, `demo/online-testing/frontend/src/app/app.ts`; `demo/polls/tests/e2e/tests/smoke.spec.ts`, `a11y.spec.ts`; `demo/online-testing/tests/e2e/tests/status.spec.ts`, `status-subscriptions.spec.ts`, `status-works.spec.ts` | Landed by HIL-1696. Both projects mount five context-bound admin pages and the public reader through six lazy entries; the six Angular Status keys are absent from `HILOS_UNBUILT_PAGES`. The public `/status` route remains a file reader without a backend page or subscription; Angular keeps its page interaction separate from shell controls while retaining the caller's shell policy. |

PR-133 through PR-135 and PR-204 through PR-209 remain the code leaves' design
promises. HIL-1685 neither implements nor closes them. HIL-1691's subscriber
schema, guest and admin action DTOs, and mail tasks are implemented in the
source paths above. HIL-1692's work storage, owner actions and maintenance
observation are implemented in the source paths above. Remove a future marker
only in the commit that lands its behavior, following
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Validation

Status checks are split across these existing layers:

- The feature contract and page/table binding are guarded by
  `framework/tests/Unit/StatusFeatureActivationTest.php` and the six demo
  `*TopologyRegistryTest` files.
- Scheduled-work schema, storage, actions, table membership, maintenance import,
  and mail paths are covered by
  `framework/tests/Integration/StatusWorksSchemaIntegrationTest.php`,
  `StatusWorksStorageIntegrationTest.php`, `StatusWorksActionsIntegrationTest.php`,
  `StatusWorksTableIntegrationTest.php`,
  `StatusMaintenancePeriodsIntegrationTest.php` and
  `StatusWorkMailIntegrationTest.php`. Unit coverage includes
  `StatusWorkActionDTOTest.php`, `StatusWorksTableTest.php`,
  `StatusMaintenanceObservationTest.php`, `StatusMaintenanceRuntimeTest.php`
  and `StatusWorkMailTemplatesTest.php`.
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
  time zone, v3 silence and two-fact day derivation, and the Works table controller are covered by
  `framework/frontend/core/test/status/statusFiles.test.ts`,
  `publicStatus.test.ts` and `derivedStatus.test.ts`. The subscription and
  subscriber and Works table controllers are covered by `statusSubscription.test.ts`,
  `admin/status/hilosStatusSubscribers.test.ts` and
  `framework/frontend/core/test/admin/status/hilosStatusWorks.test.ts`; History
  controller behavior is covered by
  `framework/frontend/core/test/admin/status/hilosStatusHistory.test.ts`.
- Vue rendering is covered by the public page and modal tests and the admin
  Subscribers, Works and History pages, work modal and Overview tests under
  `framework/frontend/vue/src/`. History row rendering and the Overview count
  are covered by `HilosStatusHistoryPage.test.ts` and `HilosStatusPage.test.ts`.
  The public strip's No data state, persistent known-event marker and shared
  day label are covered by `framework/frontend/vue/src/public/HilosStatusPage.test.ts`.
  The router's fragment-only history behavior is covered by
  `framework/frontend/core/test/routing/HilosRouter.test.ts`.
- React rendering is covered under `framework/frontend/react/test/` by
  `HilosPublicStatusPage.test.tsx` and `HilosStatusSubscriptionModal.test.tsx`
  for the public page and its window, and by the `HilosStatusPage`,
  `HilosStatusComponentsPage`, `HilosStatusSubscribersPage`,
  `HilosStatusWorksPage` and `HilosStatusHistoryPage` tests with the tests of
  their incident, removal, work and cancel windows, all over
  `statusFixtures.tsx`. The shell exception is covered by `HilosLayout.test.tsx`,
  `HilosView.test.tsx` and `HilosMaintenance.test.tsx`, the focus kept when a
  conflict resolves by `ConflictActions.test.tsx`, and the published exports by
  `framework/frontend/react/test/packaging.smoke.mjs`.
  The public-page test covers the No data state, known-event marker and selected-day label.
- Angular public rendering, including the same No data, event-marker and day-label contract,
  is covered by `framework/frontend/angular/test/HilosPublicStatusPage.test.ts`.
- Real browser and Mailpit coverage is carried by
  `demo/chat/tests/e2e/tests/status-subscribers.spec.ts`,
  `demo/binance-btc-tracker/tests/e2e/tests/status-subscriptions.spec.ts`,
  `demo/binance-btc-tracker/tests/e2e/tests/status-works.spec.ts`,
  `demo/ecommerce-shop/tests/e2e/tests/status.spec.ts`,
  `status-subscriptions.spec.ts` and `status-works.spec.ts` of the same demo,
  the Status steps of `demo/tasks/tests/e2e/tests/smoke.spec.ts`, and the
  `tests/e2e/tests/a11y.spec.ts` of Chat, Binance BTC Tracker, Tasks and
  Ecommerce Shop. These specs use light color scheme and desktop Chrome; they do
  not claim dark-theme or narrow-viewport E2E coverage.
- The existing Binance `demo/binance-btc-tracker/tests/e2e/tests/status.spec.ts`
  active-freeze journey and `demo/binance-btc-tracker/tests/e2e/standalone/loss.spec.ts`
  stop/cold-recovery journey assert the owned silence ID, exact published bounds, and
  retention for their warm and cold readers. They are not live-cluster or failover proof.
  Visual acceptance in both themes and at narrow widths remains manual; these automated
  checks do not claim color acceptance.
- The four Status test-only commands (three moment commands plus exact cleanup;
  [Test-only moments](#test-only-moments))
  are covered on the CLI side by
  `framework/tests/Unit/Core/CLI/Commands/StatusTestHeartbeatStaleCommandTest.php`,
  `StatusTestHeartbeatNowCommandTest.php`, `StatusTestWorkStartCommandTest.php` and
  `StatusTestSilenceRemoveCommandTest.php`; `framework/frontend/e2e/test/statusMoments.test.ts`
  covers helper names, owned IDs and the five-second budget. The publisher and archive are
  covered by `framework/tests/Unit/StatusPublicFilesTest.php`,
  `framework/tests/Integration/StatusSilenceStorageIntegrationTest.php` (committed rows),
  and `StatusPublicFilesIntegrationTest.php` (rollback-scoped publication). Owner command
  behavior, including deferred receipt, exact removal, freeze and refusals, is covered by
  `framework/tests/Integration/StatusTestCommandsIntegrationTest.php`.
- The Status e2e specs run on the production clock: a poll every 30 seconds, a
  stale limit of 120 seconds, a heartbeat every 30 seconds. No environment value
  or compose overlay shortens them, and the Binance e2e step runs in one native
  phase on the one stand, with no daemon recreated between phases. The specs bring
  each moment to now by command: `status.spec.ts` ages and beats the heartbeat,
  `status-works.spec.ts` starts an announced work, and the standalone
  `loss.spec.ts` ages the heartbeat before the full daemon stop, so nginx serves
  the aged pair and the stale verdict needs no real minutes. A spec registers its returned
  silence ID for owned cleanup, releases the hold, performs the existing maintenance or
  stop/start cleanup, and removes only that closed period. The timeout budget in `status.config.ts`
  is the convergence of the Daemon picture alone; it carries no term for the poll
  interval.

`composer -d demo/binance-btc-tracker run test:e2e` runs the selected specs once,
natively; filters, quarantine and `--last-failed` keep their native meaning. An
unfiltered run is followed by the standalone cycle — the prepare spec and the loss
spec, with the whole daemon container stopped while nginx keeps serving the files.
A red native run does not cancel that cycle, and the step stays red either way.
The standalone cycle on its own, from a clean stand, is
`composer -d demo/binance-btc-tracker run test:e2e-status-full`. Mail tests
prove MIME content and escaped HTML, not rendering in a real mail client. The
real-client rendering and plain-text checks in
[manual-checks.md](../manual-checks.md#mail), items 1 and 5, remain unchecked
for owner acceptance. Status uses the shared Bootstrap theme mechanism; no SLA
or independent cluster/failover proof is claimed here. No run-specific temporary
paths, process ids or container ids belong in this canon.
