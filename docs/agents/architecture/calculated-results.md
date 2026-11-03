# Calculated Results: Basis, Invalidation And Rebuilding

Read this before designing a calculated result whose meaning depends on
configuration, declaring its calculation basis, or changing how that result is
invalidated, rebuilt and presented to an open reader.

## Scope And Status

**This is the approved research design of HIL-1793, not a description of code
already built.** It settles the behavior and its cost, so implementation leaves
do not reopen the rejected choice of serving two versions. It adds no callable
API, permission, table, DB or RT field, signal, DTO or route. The result and
attempt described below are logical values, not new contracts.

The code leaves have not been cut. Their exact schemas, public names, atomic
operations and topology pass the existing contract approval gate at their own
interviews. Following [rule-authoring.md](../rule-authoring.md#a-rule-written-ahead-of-its-code),
each future leaf takes its norms and implementation markers when it is cut;
this research invents neither leaf keys nor markers without an implementation
owner. The status boundary follows the precedent in
[account-operations.md](account-operations.md#scope-and-status), not its protocol.

Today's code remains governed by [analytics.md](analytics.md),
[time-zones.md](time-zones.md) and
[work-in-portions.md](../signals/work-in-portions.md). In particular, the
reader-zone Overview of HIL-1734 remains as implemented. Physical storage of
moments, wire moment formats, the shared formatter and human-readable exports
are not redesigned here.

## Owner Decisions And Cost

The HIL-1793 interview on 2026-11-03 settled these choices. The italic words
below render the owner's Russian answers in English; they are not quotations
originally given in English. The concurrency requirements later in this page
are conditions for making the chosen reset correct, not further owner quotes.

- **Calculation uses the project zone.** Changing the environment value and
  restarting resets all current project OLAP cubes and the validity of their
  dependent current results. Rendered: *Changing the time zone simply deletes
  something.* The cause invalidates; the ordinary pipeline builds again.
- **A personal zone does not reset shared cubes.** It may change how an
  absolute moment is displayed, not which project day, week or hour a result
  belongs to. The owner confirmed that distinction.
- **Visitors and MCP thresholds are independent.** Visitors concerns visitor
  events; MCP concerns calls. Each threshold resets its affected cube and
  dependent results, not all analytics. A changed definition likewise
  invalidates what depends on that definition. The feature owner determines
  that scope; an arbitrary setting change does not reset arbitrary cubes.
- **Only one version is served.** Rendered: *There is no need to serve two
  versions.* After an accepted reset, the affected result is unavailable until
  the ordinary pipeline has built it. A failed build has its ordinary failure
  state, without an old served fallback. The same choice applies to both
  thresholds, not only to the project zone.
- **An answer names its actual basis.** Rendered: *The answers must explicitly
  say which time zone and which details they use.* Numbers and their labels
  remain explainable together; current settings cannot relabel old numbers.
  This does not authorize serving a result after it has been invalidated.
- **Loss without source data is accepted, with a warning.** Rendered: *We
  accept it — write above about the warning that will have to be made.* The
  owner accepted the configuration-source warning and its visibility limits.

Selective reset has a cost too: its affected result is absent until rebuilt,
and available raw data and the archive model determine how much history can be
rebuilt. History whose source data has been deleted cannot be recovered by
recalculation. Archived raw data is not automatically available again. Do not
promise recovery, require that all history be recoverable before allowing a
change, or add a confirmation gate to undo this accepted cost.

## Current Implementation

These are code facts checked against the working tree, with research base
`2cf51c642` as the reference. They are the starting points for implementation,
not evidence that the target lifecycle exists.

| Source | What exists today |
|---|---|
| [EnvCatalogStub](../../../framework/backend/Environment/EnvCatalogStub.php), `HILOS_PROJECT_TIME_ZONE`; [EnvAccessor](../../../framework/backend/Environment/EnvAccessor.php), `resolve()` | The project zone is an environment value with default `UTC`; explicit empty does not become that default. Resolution checks process env, the project's env file and its example before the catalog fallback. It is not a live settings key. |
| [PeerModule](../../../framework/backend/Core/Daemon/Module/PeerModule.php), `PROJECT_TIME_ZONE`; [Server UTC And The Project Zone](time-zones.md#server-utc-and-the-project-zone) | HIL-1597 carries the exact project-zone string as a peer marker. Missing or different markers refuse the peer link. Changing a cluster's zone requires configuring and restarting every node; the side with a quorum holds leadership. |
| [AnalyticsOverviewSnapshot](../../../framework/backend/Core/Analytics/AnalyticsOverviewSnapshot.php); [hilosAnalyticsOverview.ts](../../../framework/frontend/core/src/admin/analytics/hilosAnalyticsOverview.ts), `createHilosAnalyticsOverview()` | One answer contains the captured reader zone, UTC-ms week/day/hour bounds, a common `asOfTs` and exact counts. The core holds the first complete valid snapshot for the current page scope; later answers and reconnect do not replace it. The browser currently calculates calendar starts, with Monday as the first weekday. |
| [SourceChangeBus](../../../framework/backend/Core/Source/SourceChangeBus.php); [SettingPresetChangeSubscriber](../../../framework/backend/Database/Settings/Preset/SettingPresetChangeSubscriber.php) | Collection changes are announced locally and on applied sync; reactions wait for commit. The settings subscriber marks preset pages stale. These are change facts and an existing reaction, not an OLAP invalidation or rebuild system. |
| [Page subscriptions](../frontend/wire-protocol.md#page-subscriptions-01); [PageSignalRouter](../../../framework/backend/Core/Page/PageSignalRouter.php), `dispatchPageUpdateSubscription()` | `page_update_subscription` already changes params on the current page, with guards and accepted params mirrored. This is an existing transport path, not the calculated-result client lifecycle described below. |

OLAP cubes and a calculated-result reset API are not built. Nothing here
changes Phase-1 collection, node journals, the single analytics writer, or the
HIL-1734 snapshot. Their existing descriptions stay factual.

## Calculation Basis Travels With The Result

Treat numbers and the basis actually used to calculate them as one coherent
answer. Its logical contents are:

- the calculation's time zone;
- the applied threshold or definition, when it affects this result;
- the period and step, its calendar boundaries as UTC epoch milliseconds, and
  the data cutoff;
- other grounds that actually affect this particular result, as they appear.

The answer is not a snapshot of every project setting. The displayed zone and
relevant details come from the answer itself, not a separate read of today's
configuration. Absolute moments may be formatted in the reader's zone under
[time-zones.md](time-zones.md); that presentation does not change the calendar
meaning of a project bucket. A project day cannot become a reader day by
changing its label. This list chooses no future DTO shape or public field name.

The list grows through the declaration of the result's definition or its basis
provider. Capture actual values together with the decision to calculate. A new
consumer's owner declares which grounds affect it and which result they
invalidate. Do not grow a central switch over every setting, or introduce a
global reverse-dependency registry merely to reset all cubes.

HIL-1402 owns the cube schemas and aggregation boundaries, including which MCP
metrics are affected and which could remain intact. HIL-1424 owns locale and
the first weekday. Neither a new setting nor an existing first-weekday API is
implied here; Monday in today's Overview remains a code fact.

## Invalidate, Build, Publish

Keep one current requested basis and attempt for a result. The logical path is
from that request to **not built / building**, then **ready**, or to the ordinary
**failure / unavailable** state. These words are not enum values or wire keys.
The cause only invalidates. The normal pipeline agents calculate and publish
availability, without a separate time-zone lifecycle or a second served copy.

A project-zone change invalidates all current OLAP cubes. A Visitors or MCP
threshold change invalidates its corresponding area; a definition change
invalidates its dependents. The feature owner decides the exact useful metrics
and rebuildable history within that area. This page does not infer them from a
placeholder view.

The reset also invalidates dependent current summaries, answers, readiness and
progress. Otherwise an adjacent layer can bring old numbers back after the
cube was reset. Available raw data and independent export files are not
automatically deleted. There is no global deletion of archives. Returning an
old derived archive, or receiving a late answer, cannot make an old basis
current again.

**Not built or unavailable is not zero.** Before the first whole result, show
no purported complete answer. For ordinary rebuilding under still-valid
grounds, [Part Or Whole](../signals/work-in-portions.md#part-or-whole) keeps
the last whole until replacement. The accepted reset here makes those grounds
invalid and therefore does not keep the old version serving while it builds.

After failure, use the ordinary pipeline failure state. A new attempt follows
[After A Failure](../signals/work-in-portions.md#after-a-failure): select from
facts of completed work, not an opaque saved cursor. A whole result is rebuilt
from the start; an unfinished one is discarded unseen. Do not promise
exactly-once execution, retry an ambiguous after-commit effect automatically,
or invent a universal retry timer or CLI command for this research.

## Superseded Work And Cluster

These are correctness conditions of the chosen reset:

- There is one current attempt identity. After reset, superseded work cannot
  write current result rows, progress or readiness, even if an old worker is
  still finishing a portion.
- Once a reader has received the new current attempt, it cannot accept an old
  page answer. The reader drops the invalid result and follows the ordinary
  unavailable/building/ready outcome. Use the surface's declared source and
  normal answer path under [screen-invalidation.md](../signals/screen-invalidation.md);
  a notification that leaves the screen waiting without an answer is not enough.
- A→B→C and A→B→A both distinguish attempts. Comparing basis values alone would
  let the first A masquerade as the later A. Attempt or generation identity
  prevents that; it does not imply storing two versions of the content.
- A restart or owner change preserves the recorded basis and current attempt.
  Starting again with the same basis does not reset the result on every start.

One lawful cluster owner of the affected set records one invalidation boundary.
A node merely seeing a new env value has no right to reset the shared database.
Under HIL-1597, nodes with old and new project zones refuse peer links and
leadership stays with the quorum side. A new isolated node without ownership
does not reset anything shared. When the lawful owner works with the new basis,
it establishes the boundary once.

**Peer refusal alone does not fence an old database write.** Publication must
exclude the superseded owner or attempt after that boundary, including during
rolling restart or owner handover. The precise atomic operations, schemas and
builder placement are inputs to HIL-1402 and the implementation cut of
HIL-1792. This research specifies the guarantee without declaring new ownership
claims or a database protocol.

Apply [framework-first.md](../framework-first.md) by lifetime and owner. The
recorded basis and current attempt must survive restart, so they belong to the
durable layer. `Hilos::$rt` may carry a shared live projection or progress,
written by its declared [truth source](truth-source.md); it is not the durable
authority. A tab's captured snapshot stays local to that tab. A static value
on one node cannot be the cluster's truth. The existing
[monopolistic-agent rules](../agent-system/monopolistic-agent.md) govern blocking
work; this page does not choose a new process shape for builders.

Research `Hilos::$db` before adding queries. HIL-1402 already allows raw SQL for
raw data and building, with ORM for the final readable tables. This document
does not enlarge that exception or authorize arbitrary SQL from readers.

## Warning At The Configuration Source

The accepted warning belongs in each project's `.env.example`, immediately
before the **commented** `HILOS_PROJECT_TIME_ZONE` assignment. Loss of history
comes first, and plain env text uses CAPS, `!!!` and separation for visibility.
This is ready wording for a future implementation leaf, not a change to env
files in this research:

```dotenv
# !!! WARNING: CHANGING THE PROJECT TIME ZONE CAN LOSE ANALYTICS HISTORY !!!
# PLANNED OLAP RESET: history without retained raw data cannot be rebuilt.
# All current OLAP cubes and dependent results will be unavailable until rebuilt.
# Archived raw data must be available to the normal rebuild pipeline.
# Calendar boundaries, cron, backup retention selection and server text can change.
# Set the same zone on EVERY cluster node and RESTART ALL nodes.
# Full consequences: docs/agents/architecture/time-zones.md
# HILOS_PROJECT_TIME_ZONE=UTC
```

The OLAP behavior stays explicitly **PLANNED** while its code is absent. The
implementation leaf changes that status together with the behavior, not before.
[time-zones.md](time-zones.md#formatting-a-captured-analytics-snapshot) points
directly to this section for the full consequences:

1. All current OLAP cubes and dependent current results are reset and unavailable
   until rebuilt. Readiness and progress from the previous attempt do not count.
2. History without retained raw data cannot be rebuilt. An archived source is
   usable only as the ordinary archive model makes it available; reset does not
   itself restore that source or delete independent export files.
3. Project calendar boundaries change, so a day, week or hour can group the same
   absolute moments differently.
4. Cron uses the new project zone after restart, with its existing DST rules.
5. Backup retention's calendar buckets can change the normal selection of which
   backups to keep or delete. Changing the zone does not itself delete all
   backups; the normal retention policy still governs selection.
6. Server text using project mode, or reaching the project-zone fallback, uses
   the new zone. Existing exports keep their captured zone and bytes under
   [Server Text And Human-Readable Exports](time-zones.md#server-text-and-human-readable-exports).
7. Every node of the cluster must use the same zone and be restarted, including
   slaves; rolling restart has the peer/quorum boundary described above.

The root example is not inherited by projects. New-project instructions must
carry this block into the project's own example. Existing separate declarations,
such as [the cluster common env](../../../demo/binance-btc-tracker/docker/cluster/common.env),
need a short pointer in the future implementation too. No actual example,
common env, Compose file or project README is changed by this research.

The accepted visibility limit is explicit: `cp -n` does not replace an existing
`.env`, and process env or Compose may bypass the example. The warning is not
guaranteed to be seen on every change. No new UI, confirmation or startup stop
is introduced to compensate for that limit.

## Inputs To The Future Cut

HIL-1792 can cut the following work from this design. These are inputs, not new
tickets or promises of APIs. Each implementation item owns its necessary
behavioral coverage with its code; no test obligation is handed to an unrelated
later leaf.

| Subject | Accepted behavior, dependency and boundary | Coverage that belongs with the implementation |
|---|---|---|
| A real consumer's current attempt, reset and building | Use the actual cube and aggregation boundaries of HIL-1402. Put threshold and definition invalidation with their feature owner. Preserve one current attempt durably and fence superseded work; do not build an abstract kernel or global registry without a consumer. | Late work cannot publish rows/progress/readiness; A→B→C and A→B→A; a normal restart with unchanged basis does not reset; personal-zone changes do not reset shared cubes; Visitors and MCP thresholds remain independent; failure and missing/archived source data have the accepted availability and loss outcomes; rolling restart and owner handover preserve the boundary. |
| Actual basis in answers and readers in Vue, React and Angular | Use the real consumer's result and availability from the preceding item. Carry only its influencing grounds with the numbers through contracts approved at that leaf's interview. Use ordinary page delivery and unavailable states, without an old served fallback. | Labels and numbers remain one calculation; unavailable data is distinct from a true zero; no partial total is presented as whole; a late old answer is rejected after the new attempt is known; failure never restores invalid numbers; changing a personal zone preserves the project calendar meaning. |
| Project-env warning and instructions | Install the warning at the actual configuration sources and in new-project instructions. Its OLAP status depends on the reset behavior landing; keep PLANNED until then. This work does not introduce a configuration UI or approval gate. | The example keeps the assignment commented, names history loss first and links to the full consequences; project examples and separate declarations have the intended route; existing `.env` files are not represented as automatically updated. |
| Transition of the temporary Overview | Move the HIL-1734 reader-zone calculation to the real project calculation when its HIL-1402 result and the answer/reader lifecycle exist. Coordinate locale/first-weekday work with HIL-1424; do not invent that API here. | Project calendar bounds, cutoff, numbers and labels agree; personal-zone changes leave shared calculations intact; current-attempt replacement and reconnect do not revive an invalid snapshot; unavailable and zero remain distinct in every supported reader. |

This research adds no tests, CLI commands, timers or stand changes. Its existing
documentation guard is `composer run test:framework:unit -- --filter AgentDocGuardTest`.
The behavioral scenarios above are obligations of the code that will implement
them, not e2e coverage claimed by a documentation change.
