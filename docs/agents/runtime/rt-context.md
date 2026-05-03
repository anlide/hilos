# Runtime: RtContext

`Hilos::$rt` is the runtime state context entry point for the current
application. It exposes in-memory state shared across agents in a worker and
synced across workers via RT sync signals.

## Access

Use typed runtime collections through `Hilos::$rt`:

```php
Hilos::$rt->connections    // RtCollection of Connection view items
Hilos::$rt->userStates     // RtCollection of ChatUserState view items
Hilos::$rt->chatContexts   // RtCollection of ChatContext view items
```

The available names are defined in the project's `RtContext` subclass, such as
`RtChatContext`. A caller should use these existing access paths before adding a
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
collections in `_stateCollections`, then maps each one to a view collection with
`setRepresent()`.

```php
final class RtChatContext extends RtContext
{
    public const string connections = 'connections';
    public const string userStates  = 'userStates';
    public const string chatContexts = 'chatContexts';

    public function configure(): void
    {
        $this->_stateCollections[self::connections] = StateConnections::init();
        $this->_stateCollections[self::userStates] = StateUserStates::init();
        $this->_stateCollections[self::chatContexts] = StateChatContexts::init();

        $this->setRepresent(
            self::connections,
            Connections::class,
            ConnectionsActions::class,
            ConnectionActions::class,
        );
        $this->setRepresent(self::userStates, UserStates::class, UserStatesActions::class);
        $this->setRepresent(self::chatContexts, ChatContexts::class, ChatContextsActions::class);
    }
}
```

`Hilos::$rt->connections` calls the context magic getter and returns the
registered `RtCollection` wrapper.

## Backing-State Boundary

Direct backing-state access is a low-level data-layer tool. Calls to
`getStateCollection()`, `RtContext::getStateCollection()`, and direct
`$this->stateCollection` access are allowed only inside files under
`Database/` or `Runtime/`.

Agents, pages, tables, signal handlers, tests, and other orchestration code
must not use backing-state access. They must call caller-facing APIs on
`Hilos::$rt` collections, items, or actions. When a needed read is missing, add
a typed read helper to the owning `Runtime/View/Collection` or
`Runtime/View/Item` class, then call that helper from the agent/page/table.
During refactors whose goal is transparent data shape, keep field checks
explicit and add no new convenience helper unless the exact method was approved
in the plan.

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

The project facade should create the context with `new RtChatContext()`.
`Hilos::init()` calls `configure()` after `createRuntime()`.

## Finding Existing Logic

Before writing runtime-backed code:

1. Find the app context: search for `extends RtContext` or the collection
   constant in `RtChatContext`.
2. Check `setRepresent()` to locate the View collection, collection actions, and
   item actions.
3. Inspect the View collection for existing lookup methods such as `forUser()`.
4. Inspect the State collection for typed `get()`, `offsetGet()`, and lookup
   helpers from inside `Database/` or `Runtime/` only.
5. Inspect collection actions for create/register/clear operations.
6. Inspect item actions for updates on one loaded runtime item.
7. Find the truth source registration in the owning agent's `onStart()` and
   `onStop()`.
8. Only then add the smallest missing method to the owning layer. In transparent
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

Runtime state may add transient overlays to DB entities, such as presence,
connection counts, upload progress, and socket-local UI state. It must not
replace the DB entity as the source of durable business data. Keep persistent
identity, history, settings, and catalog state in `Hilos::$db`, and project
DB + RT together only at the view/frontend boundary:

```php
foreach (Hilos::$db->users as $user) {
    $rows[] = [
        'id' => $user->id,
        'name' => $user->name,
        'onlineSessionCount' => count(Hilos::$rt->connections->forUser($user->id)),
    ];
}
```

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
$state = Hilos::$rt->chatContexts->getStateCollection()->get('main');

// Correct: add/use a Runtime collection API.
$context = Hilos::$rt->chatContexts->main();
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
| New runtime row field | `RtState` typed field, `toArray()`, `fromRow()`, and `applyDiff()` |
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

Only the **truth source** agent should write to a runtime collection. Other
agents read the synchronized state.

Application code should write through runtime actions, typed `RtState` fields,
and `sync()`. Reserve `applyDiff()` / `applyDiffToState()` for inbound RT
synchronization internals after another worker already made the write.

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
