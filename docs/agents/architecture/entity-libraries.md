# Entity Libraries

Read this before answering a question about a *set* of an entity rather than
about one instance of it: a list, a search, a create with nothing to address
yet, a delete, a command that names no instance. Read it too when declaring a
new agent, because the same page decides where that agent runs.

An **entity library** is the agent that holds one entity's set and answers for
it. The agent instance holding a set is its **holder**. Both words are fixed
here so the next leaf does not coin its own: say *library* for the unit and
*holder* for the running instance, not "registry", "catalog", "index" or
"manager".

The counterpart is the **instance owner** — the agent that owns one row and its
contents (epic HIL-626; the rule itself is HIL-632's document). A library exists
because a read must not raise owners: an admin list of ten thousand users cannot
start ten thousand agents to draw a table.

**This document is an approach, not a description of code.** Nothing in it is
implemented; the names below are given so that everything built against it is
built against the same words. What each named leaf owns is in *Open
Preconditions* and *What This Approach Does Not Decide*.

## Core Rule

One entity, one library. A caller that does not own an instance reads the set
from that entity's library or from the database, and never by iterating a
collection in local memory. A write with no instance to address goes to the
library, which owns the set; a write into a row that has an owner goes to that
owner, which owns the contents.

## The Unit: One Entity, One Library

The unit is per entity, not per project and not per subsystem. The library of an
entity owns the *set* operations — list, search, create, delete, and any command
that arrives without an instance to address. It does not own what is inside a row
that has an instance owner.

`demo/chat/backend/Agents/LibraryAgent.php` is the prototype in the repository
and also the shape the rule cuts: it registers two collections
(`ChatDbContext::bots` and `ChatDbContext::moderatorPromptPieces`) in one
`onStart()`, and two entities in one holder is exactly what one entity, one
library forbids. Its two pages —
`demo/chat/backend/Pages/AdminBotsPage.php` and
`demo/chat/backend/Pages/AdminModeratorPage.php`, each pointing
`SUBSCRIPTION_AGENT_TYPE` at `AgentType::LIBRARY` — are otherwise the read path
this approach keeps.

The rejected shape is hleb's `Library` task: one monopolistic class of 4224 lines
with 41 per-entity traits, plus 2841 lines in its master half. Two faults follow
from it, and both are answered here rather than later: it cannot be read
comfortably (answered by one entity, one library), and it cannot be spread across
cluster nodes (answered by the placement axes below). A per-project library
carrying "all the admin entities" is the same monolith at an earlier size.

## Placement Is Two Axes, Not One Flag

Each question is declared once, in the project's `Hilos::AGENTS` entry, on its
own axis (HIL-667):

| Key | Enum | Values | Question it answers |
|---|---|---|---|
| `AgentRegistryKey::SCOPE = 'scope'` | `Hilos\Core\Agent\Config\AgentScope` | `CLUSTER`, `NODE` | how many instances exist |
| `AgentRegistryKey::PLACEMENT = 'placement'` | `Hilos\Core\Agent\Config\AgentPlacement` | `LEADER`, `POLICY` | who picks the node |

Defaults are `SCOPE = CLUSTER` and `PLACEMENT = LEADER`, which is exactly the
behavior that came before, so an agent that declares neither is untouched.
`TopologyValidator` refuses the two combinations that mean nothing: `NODE` with
`INDEXED` (a sharded pool needs an index, a replica has none), and `NODE` with
any `PLACEMENT` (a replica runs everywhere, so no node is picked).

It was worth splitting because one question used to be answered twice, in two
files that had to agree by convention. `AgentDaemonInterface::requiresClusterLeadership()`
was the **gate** on the daemon; `AgentRegistryKey::PER_NODE` in the registry was
the every-node start **pass**. The pass did not open the gate: an agent flagged
`PER_NODE` whose daemon still returned true was started on every node and then
refused on every follower, silently. `LogRotationAgent` kept the two in step by
hand. Both are gone now — the method is deleted from the interface, the base and
all seven overrides, `PER_NODE` is removed with no alias, and the gate in
`WorkerServer::startAgent()` reads the axes instead.

**A library declares `SCOPE = CLUSTER` and `PLACEMENT = POLICY`.** One holder
cluster-wide, on the node the placement policy picks — the owner's decision of
2026-08-23. The other silhouettes stay reachable as other cells of the same
matrix rather than as options bolted onto the chosen one:

| Scope | Placement | What it is |
|---|---|---|
| `CLUSTER` | `LEADER` | the leader-hosted cluster singleton; every agent that declares nothing |
| `CLUSTER` | `POLICY` | an entity library; also the delivery pools and the cluster demo's fleet |
| `NODE` | — | a replica on every node; log rotation, throttle counters, the code pool |

The machinery a `POLICY` library needs is in place on both sides.
`ClusterPlacement::placeAgentOnBestNode()` ranks the online nodes by fit and
places on the winner, and `WorkerServer::executePlacement()` hosts it there by
reusing the ordinary local start — the one entry that carries the placement
sanction, so a `POLICY` agent comes up on a follower through it and no other way.
`DaemonManager::ensurePolicyAgentsPlaced()` is what asks: a per-tick
reconciliation on the leader over every unindexed `POLICY` agent in the registry.
Indexed pools stay with the project, which alone knows their members.

## Reading Without The Owner

Two ways are allowed, and a third is forbidden:

- **Ask the holder.** The set arrives over a page subscription routed to the
  library — the page's `SUBSCRIPTION_AGENT_TYPE` names the library's agent type,
  which is how `AdminBotsPage` already reaches `LibraryAgent`. A table's first
  window travels in the same subscription reply
  ([../signals/subscriptions.md](../signals/subscriptions.md); the mechanics are
  HIL-642).
- **Read the database.** By key, or by a query. A single row a caller needs is
  fetched from the database and does not wake the holder.
- **Never iterate a local collection as if it were the set.** The rows in this
  process's memory are the rows this process happened to touch.

The second way rests on the nodes of a cluster sharing one database — the premise
the re-hydrate path already writes down
(`framework/backend/Cluster/Peer/DTO/PeerDbReHydrateDTO.php`: a restore run on
any node leaves the others holding caches of a database that no longer exists).

**A query is equally fresh on any node; a read by key is not.**
`Objects::offsetGet()` answers from this process's object cache when the row is
already there and only falls through to SQL when it is not, and the repair for a
cache another process invalidated is node-local: the daemon announces a write to
its own workers (`WorkerDbSync*MessageDTO`), and `framework/backend/Cluster`
carries no DB sync at all. So a node that read a row before another node changed
it keeps the old copy indefinitely. Cross-node cache invalidation is one of the
open preconditions below; until it is answered, a caller that must not read a
stale row reads it with a query rather than by key.

Who holds the set *in memory* is the question the library answers; how a node
learns that its copy of a row is stale is the question it leaves open.

Note what is **not** evidence for this: since HIL-712 every node of `demo/cluster`
names the one schema `hilos-demo-cluster`, so that stand does hold a shared
database — but the only table in it is the framework settings table, and the demo
is a mesh, election and placement harness carrying no shared entity at all. It
says nothing either way about who holds the set of an entity in memory.

The rule is already the practice in the places that met the problem, and this
document defends it rather than introducing it:

- `BrowserContext::sourceItemsForSnapshot()` runs a fresh
  `DbCollection::queryPageItems()` for a DB-backed anchor precisely so a lazy
  key-only collection does not shrink a list page to the rows already cached.
  Cited for that and only that: its `catch (Throwable) { return []; }` turns a
  failed query into an empty list, which is the shape the *Refusals* section
  below forbids. That is the call site's own debt, for the leaf that reaches it —
  cited here as the query shape, not blessed as error handling.
- `ObjectIdentities::listByUser()` answers with a query
  (`EntityIdentity::get([...])`) and merges the result into the object
  collection, rather than filtering the collection it holds.

The shape being replaced is equally visible: a view collection that wants the
whole set calls `Objects::loadAllFromDB()` on the spot — `listAll()` in
`demo/chat/backend/Database/View/Collection/Users.php` and its counterparts in
the tasks and simple-poll demos. That loads the entire table into whichever
process asked. A library is the answer to that: one holder keeps the set, and
everybody else asks it or queries the database.

## Declaring The Set Complete, And Refusing When It Is Not

A holder declares completeness in `onStart()`: it registers itself as the truth
source of its collection (`AbstractAgent::registerDbTruthSource()`) and calls
`Objects::preloadAll()` (`framework/backend/Database/Object/Objects.php`). Every
other process stays lazy. `Objects::isAllLoaded()` is the bit that records the
declaration.

**Iterating a collection that has not declared completeness must refuse.** Today
it does not, and the caller cannot tell the two answers apart:
`Objects::valid()` returns false for a `LAZY_STRATEGY_KEY` collection whose
iterator has run past the loaded rows — the same false that means "no more rows".
`DbCollection::filter()` shows the second half of the same gap: it preloads for
`LAZY_STRATEGY_BATCH` and then iterates whatever is in memory for
`LAZY_STRATEGY_KEY`. HIL-410 (an OAuth-linked identity missing from a profile
list) is one visible outcome of a general mechanism, not a defect of its own.

The named refusal is
`Hilos\Database\Exception\CollectionNotFullyLoadedException extends HilosException`,
beside `framework/backend/Database/Exception/CollectionNotManualException.php`,
and its message names the collection. Naming the collection is the point: the
caller sees which set was incomplete, not that something somewhere was lazy.

Precedent for the shape exists in the same class. `Objects::filter()` already
refuses on this condition — but with a `LogicException` reading "Filtering for
lazy-loaded collections not yet implemented", which reads as a TODO rather than
as a contract, and only on its no-truth-source branch: the truth-source branches
above it filter memory unguarded. The approach turns that into the named
exception, extends it from filtering to iteration, and does not inherit the
branch that skips it.

**"Complete" is one answer per lazy strategy, not one bit**, and an implementing
leaf that reads `isAllLoaded()` alone will refuse collections that are perfectly
sound. There are four strategies, and every switch over them in the framework
enumerates all four before throwing `UnknownLazyStrategyException` on the
default (`DbContext::__get()`, and the collection- and item-level `DbActions`):

| Strategy | How it becomes complete | What a refusal must do |
|---|---|---|
| `LAZY_STRATEGY_NONE` | `loadAllFromDB()`; `preloadAll()` is a **no-op** here, being gated on `_allowLazyLoading`, which `initDB()` sets to false for this strategy | never refuse: the collection is not lazy at all |
| `LAZY_STRATEGY_BATCH` | `current()` self-loads the whole set on first iteration — and this is `initDB()`'s default | never refuse an iteration: it is the load |
| `LAZY_STRATEGY_KEY` | only `preloadAll()`, which is what a holder calls | refuse when `isAllLoaded()` is false |
| `LAZY_STRATEGY_FULL_ON_ACCESS` | `offsetGet()` loads the whole set on the first read by key — but iteration loads nothing, because `current()` self-loads for `BATCH` only | refuse when `isAllLoaded()` is false, exactly as for `LAZY_STRATEGY_KEY` |

So the condition is the narrow one — a lazy collection that does not load itself
on iteration being walked as a set — and not "this collection has not loaded
everything". `Objects::filter()` already excuses the eager case; the `BATCH` row
is the one a naive gate gets wrong, and it is `initDB()`'s default, so getting it
wrong refuses most of the collections in the repository. No collection in the
repository is built with `FULL_ON_ACCESS` today, which is precisely why its row
has to be written down rather than discovered by the leaf that first uses it.

This refusal is what makes the read rule enforced by machinery rather than by
prose, and it will surface existing iterations. That is the intended effect; the
leaf that reaches such a call site fixes it there.

## Writing Without The Owner

The set and the row have different owners, and the existing truth-source registry
already expresses both. A registration answers two questions, not one: which rows
are yours, and what you may do with them.

| Owner | Right | Call |
|---|---|---|
| library | create rows in the collection | `TruthSourceRegistry::registerCreate($collection, $agentId)` |
| instance owner | write its own row's keys | `TruthSourceRegistry::register($collection, $keys, $agentId)` |
| either | only some of add / update / remove | the fourth argument of `register()`, a list of `TruthSourceOperation` |

Creating is not a mechanism of its own (HIL-688): `registerCreate()` is a
registration that covers no row and allows `TruthSourceOperation::Add`, and the
guard that refuses a write names the operation it refused along with the ones the
source does hold.

**Every runtime collection has exactly ONE full truth source, and an add/remove
co-owner is allowed as long as it is declared** (owner's decision, 2026-08-25).
Two claims on one collection are not a smell to be argued each time: the holder of
the entity holds it whole, and a library beside it holds adding and removing so it
can park what it just created. What the co-owner may NOT do is edit - a row that is
already there is the full owner's, and the library says what changed in a frame
instead (HIL-685 is the first pair: `hilos_auth_registration_wait_moved` and
`hilos_auth_recovery_wait_moved`). Checking this by machine is HIL-696; today it is
read off the two `register()` calls with the eye.

`AbstractAgent::registerDbTruthSource()` is the helper for the write half only;
there is no create-side helper on the agent today, and adding one is the
implementing leaf's business, not a decision this approach makes. What the helper
does carry is the operation set: it takes it from
`AbstractAgent::defaultTruthSourceOperations()`, which
`AbstractUsersLibraryAgent` overrides with adding and removing. **A library never
edits a row it already wrote** (owner's decision, 2026-08-24) - the standard
behaviour of a library rather than a switch each project throws, and the whole of
the rule is that one override.

**Sign-in is the case that proves a library writes** (owner's decision,
2026-08-21). It has no instance owner to address, and the reason is structural
rather than historical: the person is not named until the command succeeds, the
account a registration creates does not exist before it, and the identifier
lookup in front of the form is a search across the whole set. So the users
library owns the sign-in commands. The door itself was built by HIL-622, onto the
unit named here: the handlers live in `framework/backend/Auth/Library/` now, and a
project reaches them by declaring `HilosFeature::AUTH` rather than by writing a
page of its own.

When an instance owner writes its own row, it tells the holder with an explicit
agent signal:

| Name | Value | Payload |
|---|---|---|
| `SignalConstants::LIBRARY_ROW_CHANGED` | `'library_row_changed'` | `collection` (collection key), `id` (row key, as a string), `mutation` (`Hilos\Core\Table\Mutation\TableMutationType`) |

An explicit signal, and it stays explicit: the announcement to the holder is a
statement about one library's set, not a database fact, and it is addressed to the
one agent that owns that set. The signal is chosen because an agent signal already
has a cross-node path: `SignalRouter::applyPlacement()` rewrites a destination that
resolves to another node into a remote one, and `PeerServer::sendSignalToNode()`
carries it. Delivery there is best-effort by construction — an unlinked node is
dropped and logged — which is a property the implementing leaf inherits rather than
one it may assume away.

**Cross-node database sync exists as of HIL-670**, and it is a different mechanism
answering a different question: the four DB sync facts a worker raises after a write
travel the mesh (`peer_db_sync`) so that every node's own in-memory copies stop being
stale. It carries no library semantics and replaces nothing above — a holder learns
that its SET changed from the signal, and learns that a ROW it holds changed from the
sync. The mechanism is precondition 3 below.

**Both paths run from anywhere, not only from the leader.** `applyPlacement()` asks
`ClusterPlacement::locate()`, which answers from the placement view the leader
publishes to every node (HIL-668) and from the agent's own declared axes (HIL-667).
Where the answer is not known it says so rather than falling back to local delivery
(HIL-670) — an owner on a follower announcing to nobody was exactly the silent case
that made the fallback wrong.

## Refusals

| Situation | What happens |
|---|---|
| iteration without a declared complete set | `CollectionNotFullyLoadedException`, naming the collection |
| no holder — quorum lost, or the library is between nodes | the list answers `SignalConstants::SUBSCRIPTION_PAGE_ERROR`; reads by key keep working, because they never needed the holder |
| the holder's node dies | the existing failover re-places it (HIL-183); the new instance calls `preloadAll()` again |
| rebalance | a library is not moved: a truth source is not relocatable (HIL-443) |

**An empty list where the holder is missing is forbidden.** `[]` is a statement
about the data — "there are no rows" — and a caller cannot tell it from "nobody
could answer". This is the same rule the incomplete-set refusal states, at the
subscription boundary instead of the collection boundary.

On quorum loss the library stops (HIL-176). Rebuilding client subscriptions after
a holder moves is part of the return-path precondition below, not a separate
mechanism.

## On A Single Node

The whole silhouette works on a single node today and needs nothing from the
cluster: `locate()` answers "here" for every agent, so `applyPlacement()` leaves
every destination local, delivery never touches the peer channel, no DB fact is
announced to anyone, there is no second node to hold a stale cache, and every
cluster precondition below is invisible. A single-node project therefore gets one
entity, one library, the read rule and the refusals immediately; what it does not
get is a holder on a node other than its own, which it has no use for.

This is also the honest warning attached to it: an approach validated only on one
node has not exercised a single one of its four preconditions.

## Open Preconditions, And Who Owns Them

Four things this approach depends on were missing, and none was built by the leaf
that wrote this page. All four were found by reading the code, and all four are
invisible until a second node exists. Three are now closed; the third is not.

1. **A cluster-singleton could not be placed anywhere but the leader — closed by
   HIL-667.** `WorkerServer::executePlacement()` still reuses the ordinary local
   start, but that start now reads the two axes rather than the daemon's
   leadership flag, and carries a bit no caller outside the class can set: a
   `SCOPE = CLUSTER`, `PLACEMENT = POLICY` agent comes up on a follower when — and
   only when — the start arrived down the placement path.

   **What the split did not lose along with the gate.** That gate was not only a
   placement rule; it was the only thing enforcing *one holder cluster-wide*.
   `WorkerServer::sendSignalToAgent()` starts an agent that is not running so it
   can deliver to it, and on a follower the gate is what refuses that start. An
   axis that let a `POLICY` agent start off the leader, applied naively, would let
   an inbound signal raise a second holder — a second truth source for the same
   collection. The replacement is named and in place: the placement path, not
   leadership, is what may host the agent, and every other start on a follower is
   refused rather than served. What such a refused signal should do instead is
   **HIL-629**'s question, not this one's.
2. **A signal crossed nodes only when the leader routed it — closed by
   HIL-668.** `nodeFor()` read the leader-side `PlacementRegistry`, so on a
   follower it returned null and the destination stayed local: the
   owner-to-holder announcement worked from the leader and silently did not from
   anywhere else. Of the two ways out the page named — followers learn
   placements, or the frame is relayed through the leader — the first was taken.
   The leader publishes its whole placement view (`peer_placement_view`) whenever
   it changes and to every node that links, and the lookup answers from the registry
   it owns or, off the leader, from that published copy. A node that has not been
   handed one yet knows of no placement — which HIL-670 then made a distinct answer
   from "the agent is here", because the two had been the same null and the second
   reading delivered the signal into a node running no such agent.
3. **A row cached on one node was never invalidated by a write on another —
   closed by HIL-670.** DB sync used to reach this daemon's own workers and stop
   there, so a row this node had read by key stayed as it first read it, for the
   life of the process; a rename made elsewhere was invisible and nothing anywhere
   said so. The same four facts now travel the mesh as `peer_db_sync`, are applied
   by the receiving master and handed to its own workers, and go no further — one
   hop, as every other replica frame. No ownership is checked on arrival, which is
   the difference from the RT twin: the write already happened in the database both
   nodes read, so refusing the news of it would only leave this node disagreeing
   with the disk.

   **The border of the apply.** A change or a removal reaches a row the process is
   already holding, or it reaches nothing — which is what the intra-node applicator
   always did. A creation is the one fact with a choice in it, and a row created on
   another node enters only a collection that claims to hold the whole set
   (`Objects::isAllLoaded()`): that copy is what a list is drawn from, and a list
   missing a row that exists is a list that lies, while a lazy copy is entitled not
   to hold a row nobody asked for. Nothing is lost either way — the row is in the
   database, and a read fetches it. Which is exactly why a library's set, declared
   full by `preloadAll()`, is the copy that must take it.

   **A missed frame.** Delivery is best-effort, so a node that could not be reached
   simply did not get the fact. It does not work out what it missed: on every
   completed peer handshake it stops believing its database rows altogether and
   re-reads them — the master its own, the workers through `db_re_read`. A lazy
   collection forgets and fetches on next access; an eager one, and one declared
   full, reloads at once. The price is named and accepted: a burst of queries after
   every reconnect, including a pointless one for a brand new node that missed
   nothing.
4. **An agent could not answer a client attached to another node — closed by
   HIL-668.** The one peer frame that carried a signal was addressed to an agent
   (`PeerServer::sendSignalToNode()`), while the reply to a browser was local, and
   `SignalRouter::applyPlacement()` said so in as many words. It no longer does.
   Every master announces the accept keys it holds, so a `WebSocketDestination`
   whose key belongs elsewhere is rewritten to a `RemoteClientDestination` and
   forwarded (`peer_client_signal`) to the node holding the socket, which writes
   it down the path it writes its own. A fan-out (`ws_all`, `ws_group`,
   `ws_all_connected`) has no address to rewrite — who receives one is answered by
   a node-local subscription registry — so it is carried to every node instead
   (`peer_client_fanout`) and each expands it against its own. The public API is
   untouched: application code still names an accept key and nothing else.

   **What this did not close.** A signal that cannot be carried is dropped and
   logged, exactly as a write to a socket that has already gone is. The one
   exception is a page subscription, where somebody is waiting on an answer: the
   client's node raises the ordinary `subscription_page_error` so the tab stops
   spinning. Holding and retrying what was in flight to a node that fell over is
   durable delivery and belongs to HIL-347.

All four are landed: HIL-667 for the first, HIL-668 for the second and the fourth,
HIL-670 for the third. The third surfaced while this page was being written and had
no leaf at the time.

## What This Approach Does Not Decide

The leaf that wrote this page delivers the approach and no executable code. Where
each piece lands:

| Leaf | Its piece |
|---|---|
| HIL-622 | the sign-in commands, placed onto the users library |
| HIL-627 | routing a subscription to an *instance* agent; a library needs none of it, being reachable by the existing `SUBSCRIPTION_AGENT_TYPE` |
| HIL-628 | raising an instance owner on demand and stopping it when idle |
| HIL-629 | delivering a signal to an agent that is not up yet — a library needs it for the same reason an owner does |
| HIL-630 | the instance owner as the writer of its row |
| HIL-632 | the instance-owner rule in docs and skills; that document and this one are neighbours and reference each other |

Also outside it: the library base class and any framework code; the splitting of
the leadership flag (HIL-667, landed); the cross-node return path to a client (HIL-668);
edits to `BrowserContext` or any other existing reader; the mechanics of a
table's first window (HIL-642).

## Anti-Patterns

```php
// Wrong: one holder for several entities - the monolith at an earlier size.
public function onStart(): void
{
    $this->registerDbTruthSource(ChatDbContext::bots);
    $this->registerDbTruthSource(ChatDbContext::moderatorPromptPieces);
}
```

One library per entity. Two entities in one holder cannot be placed
independently, which is one of the two faults this approach exists to answer.

```php
// Wrong: reading a set by iterating whatever this process has cached.
foreach (Hilos::$db->identities as $identity) {
    // a lazy key-only collection holds the rows this process happened to touch
}
```

Ask the holder, or query the database — `ObjectIdentities::listByUser()` is the
shape.

```php
// Wrong: loading the whole table into whichever process asked for a list.
if (!$objectCollection->isAllLoaded()) {
    $objectCollection->loadAllFromDB();
}
```

That is the holder's job, once, in `onStart()`. Everyone else asks it or queries.

```php
// Wrong: an empty list when nobody could answer.
return [];
```

Answer `SUBSCRIPTION_PAGE_ERROR`. An empty list is a claim about the data.

## Related

- [agent-lifecycle.md](agent-lifecycle.md) — `onStart()` / `onStop()`, agent
  identity, registration.
- [browser-source-fanout.md](browser-source-fanout.md) — how a source change
  reaches a browser once a holder answers for the set.
- [../signals/subscriptions.md](../signals/subscriptions.md) — one page
  subscription answers with everything the page renders.
- `framework/backend/Auth/Library/AbstractUsersLibraryAgent.php` — the first
  entity library in code (HIL-622): the users library, its command groups, and
  the frames it hands a finished sign-in to the sessions library with.
- `framework/backend/Auth/Library/AbstractSessionsLibraryAgent.php` — the second
  (HIL-710): the sessions library, which owns the session set and the handshake
  that opens it. It is the worked example of *one entity, one library* against a
  neighbour — sessions left the users library rather than joining it, because they
  are hot where users are cold and the two have to be placed apart. What it does
  NOT own is the connection rows, so it speaks to the project holding them in two
  frames: `hilos_session_state` out, `hilos_session_rebind` back.
- `framework/backend/Notification/Library/AbstractNotificationsLibraryAgent.php` —
  the third (HIL-771): the notifications library, which owns the notification rows,
  the channel preferences, the delivery journal and the push endpoints. It is the
  worked example of a library founded on an entity that had *no owner at all* — the
  emit seam wrote from whatever worker called it, which held only while that worker
  happened to host an owner of something else — and of co-ownership by operation: a
  delivery channel agent updates the journal row of the attempt it is running, while
  the library adds and prunes rows.
