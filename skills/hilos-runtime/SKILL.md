---
name: hilos-runtime
description: Work with Hilos runtime state, Hilos::$rt, runtime collections, RtState subclasses, in-memory synchronization between workers, RT sync events, and non-database shared state. Use when creating or modifying runtime state objects, RT collection access, or worker synchronization behavior.
---

# Hilos Runtime

Use this skill for Hilos runtime state and cross-worker in-memory data.
Start with `agents.md`, then read the matching runtime guide.

## Read First

- `Hilos::$rt`, runtime collections, worker sync: `docs/agents/runtime/rt-context.md`
- `RtState` subclasses and state item access: `docs/agents/runtime/rt-state.md`
- Truth sources and shared state ownership: `docs/agents/agent-system/monopolistic-agent.md`
- Why a direct state write never leaves its worker: `docs/agents/antipatterns/rt-write-outside-actions.md`
- RT sync signal flow: use `$hilos-signals`

## Mental Model

- `Hilos::$rt` is the typed runtime state context entry point for the
  current app.
- A project `RtContext` registers backing `RtStates` collections, then exposes
  typed `RtCollection` wrappers such as `Hilos::$rt->connections`.
- A project `RtContext` may expose a documented single-item alias such as
  `Hilos::$rt->selfConnection` by registering `_stateItems[$alias]` and then
  calling `setRepresentItem()`; collection-backed aliases auto-attach to their
  represented parent collection when one exists.
- `RtStates` stores runtime-only `RtState` rows in memory.
- A runtime collection is shared by the whole **cluster**, not by one node: its
  truth source is unique cluster-wide, every other node holds a read-only
  replica, and the daemon replicates each write one way from the owner. There is
  no per-node collection and no flag that makes one. What travels is what an
  agent of the node owns; the two collections the daemon master registers itself
  are framework-owned exceptions, named in `DaemonManager` and explained in
  `docs/agents/runtime/rt-context.md`.
- `RtCollection` and `RtItem` expose read-oriented app APIs around the backing
  state rows. RT View collection reads treat `null` offsets as missing optional
  keys.
- Collection and item read helpers belong on `RtCollection`/`RtItem`, not on
  `actions`.
- Collection actions handle create/register/ensure and true collection-wide or
  bulk writes, such as clearing runtime rows.
- Item actions handle writes for one loaded `RtItem`, such as update/delete,
  per-connection upload progress, or UI state.
- Runtime state is for live, transient state: active sockets, upload sessions,
  moderation UI state, pending per-user text, and other data that can be
  rebuilt or safely lost on process restart.
- Durable business records, audit/history, catalog settings, and data that
  must survive restart belong in `Hilos::$db`, not `Hilos::$rt`.

## Workflow

1. Decide whether the data is durable DB state or runtime-only RT state.
2. Use DB/ORM for durable state and `$hilos-orm` for schema-backed work.
3. Find the existing `RtContext` collection constant, `setRepresent()` entry,
   and any `setRepresentItem()` aliases before adding new runtime logic. A
   collection registered in `_stateCollections` with no `setRepresent()` is a
   half-activation — data written to it never leaves its worker; fix that before
   anything else.
4. Inspect the matching View collection/item, State collection/item, and Actions
   classes.
5. Find the owning truth source agent before writing shared runtime state.
6. Decide whether the change belongs in an `RtState`, `RtStates` collection,
   View collection method, collection action, item action, agent, or caller code.
7. Use `Hilos::$rt` only for runtime state that should not be persisted as DB
   rows.
8. Define `RtState` subclasses with explicit typed fields.
9. If RT changes emit signals, verify routing and payload shape through
   `$hilos-signals`.
10. In custom RT action methods, write typed state fields and call `sync()`;
   reserve `applyDiff()` / `applyDiffToState()` for RT synchronization internals.
11. Put established read-only helpers on the view collection or view item. Do
   not add `actions->has*()`, `actions->can*()`, `actions->get*()`, or similar
   read APIs.
12. Use `getStateCollection()`, `getStateItem()`, `RtContext::getStateCollection()`,
    `RtContext::getStateItem()`, and `$this->stateCollection` only inside files
    under `Database/` or `Runtime/`; any other path is a violation regardless of
    the caller's role. Cure a leak by adding a delegate that returns plain values
    (ids, scalars, DTOs) on the owning View collection/item and calling it — a
    delegate that returns backing state objects outward is the same violation one
    floor up. Checked automatically by the `RT-STATE-REACH` guard on
    `test:framework:unit` — see `docs/agents/code-style/automated-checks.md`.
13. When updating or deleting one runtime item and the collection key is known,
   load the item and call `$item->actions->...`; do not add collection actions
   that accept the item key for that one-item write.
14. In collection RT actions, use `$this->stateCollection[$id]` only for
   create/ensure, clear, or real bulk logic owned by the collection.
15. Add a concrete `@property-read StateFooCollection $stateCollection` PHPDoc
   on each collection actions class; the local annotation documents the
   concrete state collection contract.
16. Prefer real PHP 8.4 typed properties on `RtState` classes over magic-only
    `@property` fields; use `private(set)` for immutable ids and property
    hooks only when a field needs normalization or invariant logic.
17. Do not implement `__get()` / `__set()` in concrete `RtState` classes for
    declared row fields; action code should read/write the declared properties
    directly, then call `sync()`.
18. In concrete `Runtime/View/Item/*` classes, rely on
    `@extends RtItem<StateFoo>` and read state fields as
    `$this->_state->fieldName`. Do not create local `/** @var StateFoo $state */`
    aliases only to recover the state type.
19. In concrete `RtStates` collections, override `get()` as nullable
    `?StateFoo` that accepts nullable IDs and `offsetGet()` as non-null
    `StateFoo`; use `get()` for optional lookups and `[]` only when the row
    must already exist. Never cast a nullable state key to string before
    deciding whether it is absent.
20. During refactors, do not invent convenience read helpers or predicates on
    `RtItem`, `RtCollection`, actions, payload objects, or adjacent view objects to
    hide a field check or shorten a caller. Examples: `has*()`, `is*()`,
    `can*()`, and `get*()` wrappers around one or two state fields. Keep field
    access explicit unless the user approved that exact method in the plan or
    the method centralizes a non-trivial reused invariant.
21. When the app needs a typed "one runtime object" access path, keep the row in
    an existing `RtStates` collection when it is collection-backed, register
    `_stateItems[$alias]`, expose it with `RtContext::setRepresentItem()`, and add a concrete
    `@property-read ?Foo $alias` PHPDoc to the project context.

## Examples

```php
Hilos::$rt->connections->actions->register($acceptKey, $userId);

// Required connection row: guard first, then use the addressed item directly.
if (!isset(Hilos::$rt->connections[$acceptKey])) {
    return;
}

Hilos::$rt->connections[$acceptKey]->actions->unregister();

// Existing collection lookup: iterate the returned collection directly.
foreach (Hilos::$rt->connections->forUser($userId) as $userConnection) {
    // Use the iterated connection item directly.
}

// Optional one-shot when a missing row is an acceptable no-op.
Hilos::$rt->connections[$acceptKey]?->actions->unregister();
```

For a context-dependent single item, register an item alias in the project
`RtContext` after the owning collection representation:

```php
/**
 * @property-read ?Connection $selfConnection Current inbound WebSocket connection
 */
final class RtChatContext extends RtContext
{
    public const string selfConnection = 'selfConnection';

    public function configure(): void
    {
        $this->_stateItems[self::selfConnection] = function (): ?StateConnection {
            return $this->_stateCollections[self::connections]->get(ExecutionContext::currentAcceptKey());
        };

        $this->setRepresent(self::connections, Connections::class, ConnectionsActions::class, ConnectionActions::class);
        $this->setRepresentItem(
            self::selfConnection,
            Connection::class,
            ConnectionActions::class,
        );
    }
}
```

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

Keep page/table code as orchestration only. If a page needs to change runtime
state, delegate to the existing `Hilos::$rt` collection or item actions instead
of duplicating runtime mutation logic in the page/table layer.

## Hard Rules

- Never run `git commit` or `git push`.
- Only the truth source agent writes to its owned RT collection, and only the
  operations its claim covers: `register()` takes a list of `TruthSourceOperation`
  and the guard refuses the others by name. An `AbstractUsersLibraryAgent` adds
  and removes rows it may never edit.
- Never write to an `RtStates` collection directly (`add()`, `remove()`,
  `clear()`): those queue no RT sync, so the change exists in the writing worker
  and nowhere else. Write through the collection or item actions.
- An agent that registers as a truth source must run on exactly one node: keep
  `AgentRegistryKey::SCOPE` at its default `AgentScope::CLUSTER`. Two nodes owning
  one collection WHOLLY is the split the daemon can only refuse and log
  (`RT collection <key> has truth sources on two nodes`); two nodes each holding
  part of the operations are lawful co-owners, and the frame says which is which.
- To change a collection this node does not own, send a signal to the owning
  agent. A replica is read-only, and writing it raises
  `RtTruthSourceWriteNotAllowedException`.
- Never register a state collection without its `setRepresent()` representation:
  with no actions class there is no write path that syncs, and every other
  worker keeps an empty collection forever.
- Rebuild an index by diffing against the current rows (one signal per real
  change), never by `clear()` + re-add.
- Do not use runtime state as a hidden durable database.
- Keep sync payloads explicit and typed.
- Do not call `getStateCollection()` or `getStateItem()` (nor the `RtContext::`
  forms or `$this->stateCollection`) outside files under `Database/` or
  `Runtime/`; add a View-collection/item delegate returning plain values and call
  it. A framework seam hook's PHPDoc must name the project View collection, not an
  RT state class, as the data source.
- Do not expose application-level `applyDiff*()` write APIs on RT actions.
- Do not put read-only helpers on runtime `actions`; use `RtCollection` or
  `RtItem` instead.
- Do not add new runtime convenience read helpers or predicates during a
  refactor unless the user explicitly approved the exact method in the plan.
- Do not add local `/** @var StateFoo $state */` aliases in concrete
  `Runtime/View/Item/*`; use the `RtItem<StateFoo>` template and
  `$this->_state->...` directly.
- Do not add ad hoc computed properties to `RtContext`; use `setRepresentItem()`
  for documented single-item aliases.
- Do not update or delete one known runtime item through collection actions that
  accept that item's key; use the loaded `RtItem` actions.
- Do not move runtime mutation logic into page/table layers without an explicit
  boundary reason.
