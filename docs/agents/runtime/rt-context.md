# Runtime: RtContext

`Hilos::$rt` is the runtime state context entry point for the current
application. It exposes in-memory state shared across agents in a worker and
synced across workers via RT sync signals.

## Access

Use typed runtime collections through `Hilos::$rt`:

```php
Hilos::$rt->connections    // RtCollection of Connection view items
Hilos::$rt->userStates     // RtCollection of ChatUserState view items
Hilos::$rt->chatContext    // single ChatContext view item alias
```

The available names are defined in the project's `RtContext` subclass, such as
`ChatRtContext`. A caller should use these existing access paths before adding a
new runtime collection, helper, table method, or page-level state map.

## RtContext vs DbContext

| | RtContext (`Hilos::$rt`) | DbContext (`Hilos::$db`) |
|---|---|---|
| Persistence | In-memory, lost on restart | Persistent in MySQL |
| Speed | Very fast (no I/O) | Requires DB query |
| Use for | Live connection state, transient UI state | Business data, history |

Runtime state is appropriate for active WebSocket connections, socket-scoped
upload sessions, temporary progress UI, moderation UI state, pending per-user
text, and shared context that can be rebuilt or safely lost on restart.

Durable business entities, audit/history, catalog settings, billing state, and
anything that must survive process restart belongs in `Hilos::$db`.

## Layer Model

`RtContext` owns the app-level runtime context. It registers backing state
collections in `_stateCollections`, maps each one to a view collection with
`setRepresent()` when callers need collection access, and exposes named
single-item aliases with `setRepresentItem()` when the application needs a
typed shortcut to one item.

```php
final class ChatRtContext extends RtContext
{
    public const string connections = 'connections';
    public const string userStates  = 'userStates';

    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateCollections[self::userStates] = StateUserStates::init();

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
        $this->setRepresent(self::userStates, UserStates::class, UserStatesActions::class);
    }
}
```

`Hilos::$rt->connections` calls the context magic getter and returns the
registered `RtCollection` wrapper.

## Single-Item Aliases

An application-level runtime "one object" belongs in `_stateItems` and should
be exposed as a named item alias on the project `RtContext`.

For standalone singletons, assign the `RtState` directly to `_stateItems`.
For context-dependent or collection-backed aliases, register a resolver in
`_stateItems`; when the resolved row belongs to a represented collection,
`RtContext` attaches that collection automatically:

```php
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection;
use Hilos\Core\Execution\ExecutionContext;

/**
 * @property-read Connections $connections Active connections collection
 * @property-read ?Connection $selfConnection Current inbound WebSocket connection
 */
final class ChatRtContext extends RtContext
{
    public const string connections = 'connections';
    public const string selfConnection = 'selfConnection';

    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateItems[self::selfConnection] = function (): ?StateConnection {
            return $this->_stateCollections[self::connections]->get(ExecutionContext::currentAcceptKey());
        };

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
        $this->setRepresentItem(
            self::selfConnection,
            Connection::class,
            ConnectionActions::class,
        );
    }
}
```

The concrete context PHPDoc is part of the contract: add an explicit
`@property-read Foo $alias` or `@property-read ?Foo $alias` for each
single-item alias. Register the backing state item or resolver in `_stateItems`
first; the resolver must return an `RtState` row or `null`, not a raw array or
view item. `setRepresentItem()`
then maps that state alias to the caller-facing `RtItem` class and optional
item actions. When the resolved state row belongs to a represented runtime
collection, `RtContext` attaches that collection automatically so item actions
can use collection cache behavior. Standalone item actions use the state class
RT sync key from `RtState::getRtCollectionKey()` for truth-source checks and
RT sync. The magic getter returns the resolved `RtItem` or `null` when the
current context has no such item.

Use item aliases for application-level concepts such as "current WebSocket
connection" or a documented singleton runtime row. Do not use them to hide
arbitrary lookups, filters, or convenience predicates; those belong on the
owning `RtCollection` or `RtItem` only when they are reusable model contracts.

### Framework-Owned Singleton Aliases

Two aliases exist on every `RtContext` without any project registration:

| Alias | View item | Backing state |
|---|---|---|
| `hilosBackupRuntime` | `Runtime/View/Item/BackupRuntime` | `Runtime/State/Item/BackupRuntime` |
| `hilosProtectedModeRuntime` | `Runtime/View/Item/ProtectedModeRuntime` | `Runtime/State/Item/ProtectedModeRuntime` |

The framework declares their representation in the base `RtContext` constructor,
because it owns both rows and every caller that reads them; the project decides
only whether the backing row is mounted. Read them as literal properties —
`Hilos::$rt?->hilosBackupRuntime?->isRunning($backupId) ?? false` — so the
`@property-read` declarations on `RtContext` type the result. An alias whose row
is not mounted resolves to `null`, which is what makes an unused subsystem a
quiet no-op instead of an exception.

### Feature-Owned Runtime State

A project never mounts runtime state that belongs to a framework feature. The
framework mounts it from `Hilos::FEATURES` (see
[app-topology.md](../app-topology.md)): `mountFeatureRuntime()` runs each
declared feature's `mount()` right before the project's `configure()`, and
`assertFeatureRuntimeIntact()` right after it refuses a project that mounted the
same key itself. `hilosBackupHistories` — the backup index, with the framework's
own `setRepresent()` — and `hilosBackupRuntime` arrive this way for any project
declaring `HilosFeature::BACKUP`.

Identity is what the check compares, not presence: re-mounting a feature's key
in `configure()` leaves a second, empty collection under the same name, into
which the agent writes while every reader still holds the first one. That is
invisible at runtime, so it is turned into a refusal to start naming the feature
and the line to delete.

`hilosProtectedModeRuntime` is the exception and is mounted for every project,
declared or not: freezing a node before a destructive operation is a
data-integrity guarantee, not an opt-in surface. A `null` there means "this
process has no runtime context", never "the feature is off".

## Backing-State Boundary

Direct backing-state access is a low-level data-layer tool. Calls to
`getStateCollection()`, `getStateItem()`, `RtContext::getStateCollection()`,
`RtContext::getStateItem()`, and direct `$this->stateCollection` access are
allowed only inside files under `Database/` or `Runtime/`. Checked
automatically: `RT-STATE-REACH`, see
[automated-checks.md](../code-style/automated-checks.md).

Any file outside those two trees is a violation regardless of the caller's
role — agents, pages, tables, signal handlers, and tests must call caller-facing
APIs on `Hilos::$rt` collections, items, or actions instead. When a needed read
is missing, add a delegate that returns plain values (ids, scalars, DTOs) to the
owning `Runtime/View/Collection` or `Runtime/View/Item` class and call that
delegate; a delegate that returns backing state objects outward is the same
violation one floor up. During refactors whose goal is transparent data shape,
keep field checks explicit and add no new convenience helper unless the exact
method was approved in the plan.

A framework seam hook's PHPDoc must not point projects at RT state classes as a
data source. Describe the plain value the hook returns (e.g. connection accept
keys) and name the project's View collection as the resolution path, never a
`getStateCollection()` / `findAllBy*()` state-layer call.

The layers are:

| Layer | Role | Example |
|---|---|---|
| State item (`RtState`) | In-memory row with typed fields and sync diff logic | `Runtime/State/Item/Connection.php` |
| State collection (`RtStates`) | Backing runtime map keyed by state id | `Runtime/State/Collection/Connections.php` |
| View item (`RtItem`) | Read-only caller-facing item wrapper | `Runtime/View/Item/Connection.php` |
| View collection (`RtCollection`) | Caller-facing collection API and lookup helpers | `Runtime/View/Collection/Connections.php` |
| Actions | Write operations for collection or item | `Runtime/View/Actions/...` |

Do not skip these layers with page/table-specific arrays or duplicated runtime
mutation logic when the behavior belongs to a collection, item, or action.

The project facade should create the context with `new ChatRtContext()`.
`Hilos::init()` calls `configure()` after `createRuntime()`.

## Finding Existing Logic

Before writing runtime-backed code:

1. Find the app context: search for `extends RtContext` or the collection
   constant in `ChatRtContext`.
2. Check `setRepresent()` to locate View collections, collection actions, and
   item actions.
3. Check `setRepresentItem()` for existing single-item aliases such as
   `selfConnection`.
4. Inspect the View collection for existing lookup methods such as `forUser()`.
5. Inspect the State collection for typed `get()`, `offsetGet()`, and lookup
   helpers from inside `Database/` or `Runtime/` only.
6. Inspect collection actions for create/register/clear operations.
7. Inspect item actions for updates on one loaded runtime item.
8. Find the truth source registration in the owning agent's `onStart()` and
   `onStop()`.
9. Only then add the smallest missing method to the owning layer. In transparent
   data-shape refactors, prefer explicit field access unless a new method was
   explicitly approved.

## Reads

Use `Hilos::$rt->{collection}` from agents, pages, and handlers:

```php
// Required connection row: guard first, then use the addressed item directly.
if (!isset(Hilos::$rt->connections[$acceptKey])) {
    return;
}

// Existing collection lookup: iterate the returned collection directly.
foreach (Hilos::$rt->connections->forUser(Hilos::$rt->connections[$acceptKey]->userId) as $userConnection) {
    // Use the iterated connection item directly.
}

// Optional one-shot when a missing row is an acceptable no-op.
Hilos::$rt->connections[$acceptKey]?->actions->unregister();
```

RT View collection read access treats a `null` key as absent. `isset($collection[null])`
is false, `$collection[null]` returns `null`, and unset with `null` is a no-op.
Use this for caller-facing nullable runtime keys instead of guarding only to
protect array access.

Runtime state may add transient overlays to DB entities, such as presence,
connection counts, upload progress, and socket-local UI state. It must not
replace the DB entity as the source of durable business data. Keep persistent
identity, history, settings, and catalog state in `Hilos::$db`, and combine
DB + RT only through BrowserContext rows, typed frontend state payloads,
table rows, or signal DTOs:

```php
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;

foreach (Hilos::$db->users as $user) {
    $rows[] = [
        AdminUserTableRow::id => $user->id,
        AdminUserTableRow::name => $user->name,
        AdminUserTableRow::onlineSessionCount => count(Hilos::$rt->connections->forUser($user->id)),
    ];
}
```

Boundary arrays must use key constants from the owning DTO, browser payload, table
row, entity, object, or context. Add the missing constant before adding another
string key.

Put reusable runtime lookups on the collection layer and reusable row-level
read helpers on the view item only when they are established model contracts.
During refactors, keep simple field checks visible at the caller:

```php
if (
    isset(Hilos::$rt->userStates[$userId])
    && Hilos::$rt->userStates[$userId]->lastOutboundSubmittedAt > 0.0
) {
    // ...
}
```

Do not call `getStateCollection()` from agents, pages, tables, signal handlers,
or tests:

```php
// Wrong outside Database/Runtime.
$state = Hilos::$rt->getStateItem(ChatRtContext::chatContext);

// Correct: use the registered single-item alias.
$context = Hilos::$rt->chatContext;
```

Do not put read-only helpers under `->actions`. Actions are write APIs; using
them for reads hides ownership boundaries and makes call sites look like they
mutate state.

## Collection Actions

Collection actions are for writes that create runtime rows or affect the
collection as a whole: register/create/ensure rows, clear all runtime rows,
delete expired rows, or perform a real bulk transition.

```php
Hilos::$rt->connections->actions->register($acceptKey, $userId);
Hilos::$rt->connections->actions->clear();
```

A collection action receives the `RtCollection`, checks truth source write
permission, mutates the backing `RtStates` collection, and keeps the view
collection cache synchronized.

Do not put update/delete operations for one known collection item behind a
collection action that accepts the item's key:

```php
// Wrong: acceptKey is the connections collection key.
Hilos::$rt->connections->actions->unregister($acceptKey);

// Correct: load the item by key, then mutate that item.
Hilos::$rt->connections[$acceptKey]?->actions->unregister();
```

## Item Actions

Item actions are for writes on a single loaded `RtItem`, including update and
delete operations when the caller already has the collection key:

```php
Hilos::$rt->connections[$acceptKey]?->actions->unregister();
Hilos::$rt->connections[$acceptKey]?->actions->clearBinaryUploadSessionAndProgressUi();
Hilos::$rt->connections[$acceptKey]?->actions->applyStoredBinaryChunkProgress($receivedBytes);
```

Use item actions when the operation naturally belongs to an existing runtime
item and needs that item's state. Do not put update logic in a page handler when
an item action should own it.

## Choosing Where New Logic Belongs

| Need | Put it in |
|---|---|
| New runtime collection | `RtContext` plus `RtStates` and `RtCollection` |
| App-level single runtime item | `RtState` in `_stateItems` plus `RtContext::setRepresentItem()` |
| New runtime row field | `RtState` typed field, sync-row `toArray()`, `fromRow()`, and `applyDiff()` |
| Runtime lookup helper | State collection plus View collection wrapper |
| Caller-facing row read helper | View item |
| Caller-facing collection read helper | View collection |
| Create/register/ensure a runtime row | Collection actions |
| Clear all rows, delete expired rows, or mutate a batch | Collection actions |
| Update/delete one runtime item when its key is known | Item actions |
| Durable business data | DB layer, not runtime |
| Page response assembly | Page handler, using existing RT/DB APIs only |
| Table query/result shaping | Table layer, delegating runtime behavior to collections/actions |

## Sync mechanism

RT changes are broadcast to all workers via `RT_SYNC_*` signals:

- Write in one worker
- Signal queued
- Daemon broadcasts
- All workers apply via `RtSyncApplicator`
- On a cluster, the daemon also announces the fact to every other node, whose
  daemon applies it and feeds its own workers the same way

Only the **truth source** agent should write to a runtime collection. Other
agents read the synchronized state.

### The truth source is unique per cluster, not per process

A runtime collection is **always shared across the whole cluster**: there is no
per-node collection, no flag that makes one, and none is coming. So the
single-writer rule is a rule about the mesh, not about a process — the node
hosting the truth source writes, every other node holds a **read-only replica**,
and replication is one-way from the one to the others. There is no merge, no
version, no clock and no arbiter, because with one writer there is nothing to
arbitrate.

What this means when you write an agent that owns a collection:

- **Say it in the registry, on the scope axis.** An agent that registers as a
  truth source must stay `AgentScope::CLUSTER` (the default), so it runs on
  exactly one node — hosted by the leader or placed by the policy, either way
  once. An agent declared `AgentScope::NODE` runs on every node, and if it
  registers as a truth source, that collection now has two owners, which is the
  one thing the model does not allow.
- **A per-node agent may read a shared collection, not write it.** Writing on a
  node where the source is not registered fails with
  `RtTruthSourceWriteNotAllowedException`, exactly as it does off-cluster.
- **To change a row this node does not own, send a signal to the owning agent.**
  Cross-node signal delivery already exists; it is the only write path to
  somebody else's collection.

A node that receives a replica for a row it owns itself refuses it and
writes `RT collection <key> has truth sources on two nodes: local and <node>`.
That line means the same row has a source on two nodes at once — look
at the owning agent's `AgentRegistryKey::SCOPE` first. A frame about a row this
node does not own is ordinary traffic (see the two axes below).

A node joining the mesh is handed what each owner holds, because it has no history
for the deltas to apply to. That hand-over **replaces** what it speaks for: a row
inside its scope that the owner does not send is a row that no longer exists.

**What travels is what an agent owns.** The node announces a write only for the
collections its own agents registered, so a project's collection is replicated
by having an owner, and nothing else has to be declared. Two framework
collections stand outside that rule, because the daemon master registers them
on every node and no agent stands behind them:

- **`hilosProtectedModeRuntime` is node-local and never travels.** Each master
  writes its own node's freeze row — the leader by decision, the followers in
  reaction to the peer frames carrying it — so a replica of it would be one
  node's freeze overwriting another's.
- **`hilosSessionRotations` travels both ways.** One cluster-wide store with a
  second writer by act: the agent owning the session seam announces a rotation
  from a worker, and whichever master receives the handshake that spends the
  ticket burns the row. That burn is announced from a node hosting no owning
  agent, and it is applied on the node that hosts one instead of being refused
  as a split — or the spent ticket would survive there and buy a second
  handshake inside its lifetime.

Both are framework-owned and both are named in `DaemonManager`; an application
collection has no such case, and adding one is not a knob that exists.

**Ownership is claimed on two axes: which rows, and which operations.** A claim
naming keys is ownership of THOSE ENTITIES and is meant to be used that way
(HIL-589): a fleet of agents may split one collection between nodes, each owning
the rows it writes. A claim short of an operation is the other axis (HIL-688):
one node adds and removes, another edits, and neither is a second owner. The
node-level map answers both, and only a claim of every row AND every operation
is ownership of the collection itself.

A frame is judged by what it carries. A delta naming a row is judged by that row,
so a neighbour's rows are ordinary traffic and only a frame about a row this node
owns is the split. A hand-over from an owner of named rows carries a SCOPE: it
speaks for those rows alone, and the receiver replaces them and leaves the rest of
the collection as it found it. An owner of the whole collection sends no scope,
and then the frame is the collection, as it has always been.

**A replica whose owner cannot be reached is still served — and says so.** Nothing
refuses the reader and nothing sweeps the rows when the node that wrote them
leaves the mesh: refusing on a broken link would replace a stale answer with an
empty one, which is no truer. What the row gains is an answer to "is my source
still reachable": `RtItem::staleSince()` gives the moment this node stopped
hearing about that row, and `RtCollection::staleSince()` the earliest such moment
among its rows — `null` in both when the copy is current, which is what a local
row and every row off a cluster always are.

The mark is uniform across every RT collection and enumerates none of them. It is
kept BESIDE the rows (`RtStaleness`) rather than in them, because a row is the
owner's copy byte for byte and a housekeeping field inside it would travel into
the browser's projection and into every snapshot diff. Reachability is measured by
the LINK and not by membership: gossip from a third node keeps a peer online while
nothing between these two reaches anything, so the cues are the last link closing
and a completed handshake.

**What a reader does with the mark is the reader's own decision.** One reader in
the framework fails closed on it: `HilosSessionRotations::claimable()` refuses a
one-time login ticket read off a frozen replica, because the burn is announced by
whichever master took the handshake and in a break the other node does not hear
it — a ticket good for a second handshake is worse than a login. Every other
reader goes on being served. Presence is the standing exception and a deliberate
one: it still says "online" about a node that is gone, and closing that is not
this mechanism's job.

The mark needs no expiry, tick or poll: when the owner comes back, its hand-over
brings the copy back in line and clears the mark by that very act — which is why a
hand-over has to cover rows and not only whole collections, since delivery has no
retries and everything written during the break is otherwise lost. The browser
sees the same state as one snowflake on the SDK shell's connection indicator,
raised only when a collection the OPEN page reads is frozen.

Application code should write through runtime actions, typed `RtState` fields,
and `sync()`. Reserve `applyDiff()` / `applyDiffToState()` for inbound RT
synchronization internals after another worker already made the write.

### A collection written outside its actions is worker-local

`RtStates::add()`, `remove()`, and `clear()` are plain in-memory operations:
they queue **nothing**. The `RT_SYNC_*` signals come from the actions layer
(`RtActions::addStateToCollection()`, `removeStateFromCollection()`,
`clearAllStates()`, item `remove()`, and `sync()`). So a write that skips the
actions changes the collection **in the writing worker only**.

That failure is silent and looks like a frontend bug: the writing agent's log
says the data is there, and a page served by any other worker shows nothing —
including after a reload, because the reload lands on a worker whose collection
was never populated. The truth-source rule guarantees a single writer; it does
not move a single byte between processes on its own.

Two rules follow, and both are mandatory:

- **Register the representation.** A state collection in `_stateCollections`
  with no matching `setRepresent()` has no actions class, so it has no write
  path that syncs. Registering the state alone is a half-activation: reads work
  inside one worker and nothing else does.
- **Write through the actions,** never through the state collection —
  even from the owning agent, even for a bulk rebuild. When a rebuild is
  genuinely a rebuild, diff it against the current rows and emit one create /
  update / delete per real change (`clear()` + re-add would tear down and
  recreate every row for every browser watching).

```php
// Wrong: memory-only, invisible to every other worker and to the browser.
Hilos::$rt->getStateCollection(Foo::RT_COLLECTION)->add(Foo::fromRow($row));

// Right: the actions queue RT_SYNC_CREATED, so every worker and every
// subscribed table sees the new row.
Hilos::$rt->fooRows->actions->register($row);
```

A framework-owned collection binds its own framework-owned representation; the
project supplies only the `setRepresent()` call
([architecture/admin-feature-scaffold.md](../architecture/admin-feature-scaffold.md)).

## Anti-Patterns

Do not use runtime state as a hidden durable database:

```php
// Wrong: durable business history must not live only in runtime state.
Hilos::$rt->connections[$acceptKey]?->actions->storePermanentOrder($orderId);
```

Persist durable facts through `Hilos::$db` and keep `Hilos::$rt` for live state:

```php
Hilos::$db->events->actions->add($type, $userId, $payload);
Hilos::$rt->connections[$acceptKey]?->actions->clearBinaryUploadSessionAndProgressUi();
```

Do not add runtime arrays, duplicated connection maps, or transient mutation
logic to page/table layers just because a screen needs the value. Page/table
code should orchestrate existing typed runtime APIs; the runtime layer should
own shared in-memory behavior.

Do not add read-only methods to actions:

```php
// Wrong: actions are for writes.
Hilos::$rt->connections->actions->activeUploadCountForUser($userId);

// Correct: read through collection/item state, keeping simple checks visible.
count(Hilos::$rt->connections->forUser($userId));
```
