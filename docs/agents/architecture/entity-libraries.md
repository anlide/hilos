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

Today neither question is declared, and what stands in their place is two
booleans in two different files that have to agree:

- `AgentDaemonInterface::requiresClusterLeadership()`, on the agent daemon,
  defaults to true, and `WorkerServer::startAgent()` refuses to start such an
  agent on a node that is not the cluster leader. This is the **gate**.
- `AgentRegistryKey::PER_NODE`, in the project's `Hilos::AGENTS` entry, puts the
  agent into the every-node start **pass**
  (`WorkerServer::startPerNodeAgents()`, via `AgentRegistry::startsOnEveryNode()`).

The pass does not open the gate. An agent flagged `PER_NODE` whose daemon still
returned true would be started on every node and then refused on every follower
by the gate, silently. Keeping the two in step is manual and by convention —
`LogRotationAgent` sets `PER_NODE` in the registry *and* returns false from
`LogRotationAgentDaemon::requiresClusterLeadership()`. So the questions are not
merely welded together; they are answered twice, in two places, with nothing
checking that the answers match.

The approach splits them into two independent registry keys, declared per agent
in `Hilos::AGENTS`:

| Key | Enum | Values | Question it answers |
|---|---|---|---|
| `AgentRegistryKey::SCOPE = 'scope'` | `Hilos\Core\Agent\Config\AgentScope` | `CLUSTER`, `NODE` | how many instances exist |
| `AgentRegistryKey::PLACEMENT = 'placement'` | `Hilos\Core\Agent\Config\AgentPlacement` | `LEADER`, `POLICY` | who picks the node |

Defaults are `SCOPE = CLUSTER` and `PLACEMENT = LEADER`, which is exactly
today's behaviour, so no existing agent is edited when the axes arrive.
`SCOPE = NODE` is what today's per-node agents express with **both** halves —
`AgentRegistryKey::PER_NODE = 'per_node'`
(`framework/backend/Core/Agent/Config/AgentRegistryKey.php`) *and* a daemon
returning false from the gate. Whether `PER_NODE` survives as an alias is the
splitting leaf's call; what this approach requires is that the two axes become
separately declarable **in one place**, with the gate a consequence of the
declaration rather than a second answer to the same question.

**A library declares `SCOPE = CLUSTER` and `PLACEMENT = POLICY`.** One holder
cluster-wide, on the node the placement policy picks — the owner's decision of
2026-08-23. The other silhouettes stay reachable as other cells of the same
matrix rather than as options bolted onto the chosen one:

| Scope | Placement | What it is |
|---|---|---|
| `CLUSTER` | `LEADER` | today's cluster-singleton; every current agent, unchanged |
| `CLUSTER` | `POLICY` | an entity library |
| `NODE` | — | a replica on every node; today's `PER_NODE` |

The machinery a `POLICY` library needs already exists on the placement side:
`ClusterPlacement::placeAgentOnBestNode()` ranks the online nodes by fit and
places on the winner, and `WorkerServer::executePlacement()` hosts it there by
reusing the ordinary local start. What is missing is the split itself — see *Open
Preconditions*.

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

Note what is **not** evidence for this: `demo/cluster` gives every node its own
schema (`hilos-demo-cluster-m1`, `-m2`, … in
`demo/cluster/docker/docker-compose.cluster.yml`). That demo is a mesh, election
and placement harness carrying no shared entity, so it neither demonstrates the
shared database nor contradicts it.

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
already expresses both:

| Owner | Right | Call |
|---|---|---|
| library | create rows in the collection | `TruthSourceRegistry::registerCreate($collection, $agentId)` |
| instance owner | write its own row's keys | `TruthSourceRegistry::register($collection, $keys, $agentId)` |

`AbstractAgent::registerDbTruthSource()` is the helper for the write half only;
there is no create-side helper on the agent today, and adding one is the
implementing leaf's business, not a decision this approach makes.

**Sign-in is the case that proves a library writes** (owner's decision,
2026-08-21). It has no instance owner to address, and the reason is structural
rather than historical: the person is not named until the command succeeds, the
account a registration creates does not exist before it, and the identifier
lookup in front of the form is a search across the whole set. So the users
library owns the sign-in commands. The door itself is built by HIL-622, onto the
unit named here; the handlers live in `demo/chat/backend/Pages/MainPage.php` and
`demo/chat/backend/Agents/ChatAgent.php` today.

When an instance owner writes its own row, it tells the holder with an explicit
agent signal:

| Name | Value | Payload |
|---|---|---|
| `SignalConstants::LIBRARY_ROW_CHANGED` | `'library_row_changed'` | `collection` (collection key), `id` (row key, as a string), `mutation` (`Hilos\Core\Table\Mutation\TableMutationType`) |

An explicit signal, and **no cross-node database sync** — none exists and none is
introduced. The signal is chosen because an agent signal already has a cross-node
path: `SignalRouter::applyPlacement()` rewrites a destination that resolves to
another node into a remote one, and `PeerServer::sendSignalToNode()` carries it.
Delivery there is best-effort by construction — an unlinked node is dropped and
logged — which is a property the implementing leaf inherits rather than one it
may assume away.

**That path runs from the leader only, and the approach depends on it running
from anywhere.** `applyPlacement()` asks `ClusterPlacement::nodeFor()`, which
reads `PlacementRegistry` — the leader-side view of where agents are placed. On a
follower the registry holds no record, `nodeFor()` returns null, and the
destination stays local: an owner living on a follower would announce its write
to nobody, silently, with no dropped-frame log to show for it. This is the second
open precondition below.

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
cluster: `nodeFor()` returns null, so `applyPlacement()` leaves every destination
local, delivery never touches the peer channel, there is no second node to hold a
stale cache, and every cluster precondition below is invisible. A single-node
project therefore gets one entity, one library, the read rule and the refusals
immediately; what it does not get is a holder on a node other than its own, which
it has no use for.

This is also the honest warning attached to it: an approach validated only on one
node has not exercised a single one of its four open preconditions.

## Open Preconditions, And Who Owns Them

Four things this approach depends on do not exist, and none is built by the leaf
that wrote this page. All four were found by reading the code, and all four are
invisible until a second node exists.

1. **A cluster-singleton cannot be placed anywhere but the leader.**
   `WorkerServer::executePlacement()` reuses `startAgent()`, and `startAgent()`
   holds the leadership gate — so a `SCOPE = CLUSTER`, `PLACEMENT = POLICY` agent
   placed on a non-leader node is refused by the gate that placement just chose
   to bypass. This is the splitting of the flag described above, and it is a
   precondition of the library specifically. **HIL-667** owns it.

   **What the split must not lose along with the gate.** That gate is not only a
   placement rule; today it is the only thing enforcing *one holder cluster-wide*.
   `WorkerServer::sendSignalToAgent()` starts an agent that is not running so it
   can deliver to it, and on a follower the gate is what refuses that start. An
   axis that lets a `POLICY` agent start off the leader, applied naively, lets an
   inbound signal raise a second holder — and a second truth source for the same
   collection, which is the correctness bug the flag's fail-safe default exists to
   prevent. The splitting leaf owes a named replacement: the placement record, not
   leadership, decides which node may host the agent, and every other node refuses
   the start rather than serving it.
2. **A signal crosses nodes only when the leader routes it.** `nodeFor()` reads
   the leader-side `PlacementRegistry`, so on a follower it returns null and the
   destination stays local. The owner-to-holder announcement therefore works from
   the leader and silently does not from anywhere else. Either followers learn
   placements, or the frame is relayed through the leader; the approach needs one
   of the two and does not pick.
3. **A row cached on one node is never invalidated by a write on another.** DB
   sync reaches this daemon's own workers and stops there; `framework/backend/Cluster`
   has none. Until this is answered, "read the database" means *query* for
   anything that must not be stale, and the by-key path is safe only for rows this
   node has not read before.
4. **An agent cannot answer a client attached to another node.** The one peer
   frame that carries a signal is addressed to an agent
   (`PeerServer::sendSignalToNode()`), while the reply to a browser is local:
   `DaemonManager` sends a `WebSocketDestination` through this node's WebSocket
   server, and `SignalRouter::applyPlacement()` says so in as many words — only
   an `AgentDestination` is eligible for rewriting, because WebSocket, all-client
   and command-reply targets are bound to this node. **This is a precondition of
   the whole HIL-626 epic**, not of the library alone: any agent that is not on
   the client's node has the same problem. **HIL-668** owns it.

Two of the four have a leaf — HIL-667 for the first, HIL-668 for the fourth, both
placed on 2026-08-23. The second and the third surfaced while this page was being
written and have none yet; they are for the owner to place.

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
the leadership flag (HIL-667); the cross-node return path to a client (HIL-668);
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
