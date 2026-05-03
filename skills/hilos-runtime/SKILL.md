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
- RT sync signal flow: use `$hilos-signals`

## Mental Model

- `Hilos::$rt` is the typed runtime state context entry point for the
  current app.
- A project `RtContext` registers backing `RtStates` collections, then exposes
  typed `RtCollection` wrappers such as `Hilos::$rt->connections`.
- `RtStates` stores runtime-only `RtState` rows in memory.
- `RtCollection` and `RtItem` expose read-oriented app APIs around the backing
  state rows.
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
3. Find the existing `RtContext` collection constant and `setRepresent()` entry
   before adding new runtime logic.
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
12. Use `getStateCollection()`, `RtContext::getStateCollection()`, and
    `$this->stateCollection` only inside files under `Database/` or `Runtime/`.
    Agents, pages, tables, signal handlers, and tests must call typed
    collection/item APIs instead.
13. When updating or deleting one runtime item and the collection key is known,
   load the item and call `$item->actions->...`; do not add collection actions
   that accept the item key for that one-item write.
14. In collection RT actions, use `$this->stateCollection[$id]` only for
   create/ensure, clear, or real bulk logic owned by the collection.
15. Add a concrete `@property-read StateFooCollection $stateCollection` PHPDoc
   on each collection actions class; the base generic documents the contract,
   but PhpStorm often needs the local property annotation.
16. Prefer real PHP 8.4 typed properties on `RtState` classes over magic-only
    `@property` fields; use `public private(set)` for immutable ids and property
    hooks only when a field needs normalization or invariant logic.
17. Do not implement `__get()` / `__set()` in concrete `RtState` classes for
    declared row fields; action code should read/write the declared properties
    directly, then call `sync()`.
18. In concrete `RtStates` collections, override `get()` as nullable
    `?StateFoo` and `offsetGet()` as non-null `StateFoo`; use `get()` for
    optional lookups and `[]` only when the row must already exist.
19. During refactors, do not invent convenience read helpers or predicates on
    `RtItem`, `RtCollection`, actions, projections, or adjacent view objects to
    hide a field check or shorten a caller. Examples: `hasActive*()`, `is*()`,
    `can*()`, and `get*()` wrappers around one or two state fields. Keep field
    access explicit unless the user approved that exact method in the plan or
    the method centralizes a non-trivial reused invariant.

## Examples

```php
$connection = Hilos::$rt->connections[$acceptKey] ?? null;
$connections = Hilos::$rt->connections->forUser($userId);
Hilos::$rt->connections->actions->register($acceptKey, $userId);
$connection?->actions->unregister();
$connection?->actions->clearFileModerationBanner();
```

Use runtime state to complement DB-backed frontend state, not replace it:

```php
$user = Hilos::$db->users[$userId] ?? null;
$onlineConnections = Hilos::$rt->connections->forUser($userId);
```

Keep page/table code as orchestration only. If a page needs to change runtime
state, delegate to the existing `Hilos::$rt` collection or item actions instead
of duplicating runtime mutation logic in the page/table layer.

## Hard Rules

- Never run `git commit` or `git push`.
- Only the truth source agent writes to its owned RT collection.
- Do not use runtime state as a hidden durable database.
- Keep sync payloads explicit and typed.
- Do not call `getStateCollection()` outside files under `Database/` or
  `Runtime/`; add a typed collection/item API and use it from callers.
- Do not expose application-level `applyDiff*()` write APIs on RT actions.
- Do not put read-only helpers on runtime `actions`; use `RtCollection` or
  `RtItem` instead.
- Do not add new runtime convenience read helpers or predicates during a
  refactor unless the user explicitly approved the exact method in the plan.
- Do not update or delete one known runtime item through collection actions that
  accept that item's key; use the loaded `RtItem` actions.
- Do not move runtime mutation logic into page/table layers without an explicit
  boundary reason.
