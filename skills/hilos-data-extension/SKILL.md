---
name: hilos-data-extension
description: Choose and extend Hilos data models across Hilos::$db and Hilos::$rt. Use when adding a DB collection, DB item property, collection action, item action, runtime state, runtime collection, or when deciding whether new state belongs in durable ORM or transient runtime.
---

# Hilos Data Extension

Use this skill before adding or changing Hilos data shape, lookup APIs, or write
paths. Start with `agents.md`, then use the focused skill and docs for the layer
you choose.

## Read First

- DB-backed data, `DbContext`, `DbCollection`, entities, objects, actions, and
  migrations: use `$hilos-orm` and `docs/agents/orm/db-collection.md`.
- Runtime state, `RtContext`, `RtState`, runtime collections, sync, and actions:
  use `$hilos-runtime`, `docs/agents/runtime/rt-context.md`, and
  `docs/agents/runtime/rt-state.md`.
- DB-backed item plus live runtime overlay: use `$hilos-db-rt-state`.
- Frontend-safe computed DB item fields and `toFrontend` serialization:
  use `$hilos-frontend-representation`.
- Truth source ownership: `docs/agents/agent-system/monopolistic-agent.md`.
- Repository/service anti-pattern:
  `docs/agents/antipatterns/no-repository-service.md`.
- Frontend state contracts, if the value is sent to a page:
  use `$hilos-frontend-sdk`.
- Test command selection: use `$hilos-testing-cli`.

## DB vs RT

| Data need | Use |
|---|---|
| Must survive restart, reload, audit, or reporting | `Hilos::$db` |
| Business entity, settings, history, catalog, account state | `Hilos::$db` |
| Live sockets, sessions, upload/progress UI, transient flags | `Hilos::$rt` |
| Cross-worker live state that can be rebuilt or safely lost | `Hilos::$rt` |
| Durable entity plus live overlay such as online status | DB item plus RT lookup |
| Derived display data from existing state | Object/View layer, not ad hoc page logic |

If the data is business truth, default to DB. Choose RT only when loss on process
restart is acceptable and a truth source can own writes.

## Checklist

1. Define the lifecycle: durable DB state, transient RT state, or a DB item with
   an RT overlay.
2. Find the owner before writing: existing truth source agent, DB collection, RT
   collection, or page subscription boundary.
3. Search existing contexts before adding names: `extends HilosDbContext`,
   `extends RtContext`, collection constants, `setRepresent()` calls, and
   `setRepresentItem()` item aliases.
4. Inspect the existing View collection/item, Object or State collection/item,
   and collection/item Actions classes.
5. Add the smallest missing API to the owning layer.
6. Update frontend representation only when the new data is part of page state:
   View item/collection shape, DTO or signal payload, and subscription response.
7. Run the targeted composer validation from `$hilos-testing-cli`.

## Where Logic Belongs

| Change | Put it in |
|---|---|
| New DB table or column | Migration plus matching Entity field |
| Raw DB row mapping | Entity item/collection |
| DB-backed derived value | Object item or View item |
| Reusable DB lookup | Object collection plus View collection wrapper |
| DB create/register/import/bulk operation | Collection actions |
| DB update/delete for one loaded item | Item actions |
| New runtime collection | `RtContext`, `RtStates`, `RtCollection`, and actions |
| App-level single runtime item alias | Existing runtime collection plus `_stateItems` resolver and `RtContext::setRepresentItem()` |
| New runtime row field | `RtState` typed property, `toArray()`, `fromRow()`, `applyDiff()` |
| Runtime lookup | State collection plus View collection wrapper |
| Runtime create/register/ensure/clear/bulk cleanup | Collection actions with `sync()` behavior |
| Runtime update/delete for one loaded item | Item actions that mutate typed state and call `sync()` or remove |
| Page/table behavior | Orchestration through existing DB/RT APIs only |

## Workflow

For DB changes:

1. Add or update the migration first when schema changes.
2. Update Entity fields to match the schema.
3. Put transformed or frontend-facing state in Object/View classes.
4. Put create/register/import/bulk writes in collection actions and per-item
   update/delete writes in item actions.
5. Call APIs directly from callers, for example
   `Hilos::$db->users->actions->register(...)` or
   `$user->actions->update(...)`.

For RT changes:

1. Register the runtime collection in the project `RtContext`.
   If the app needs a typed single-item shortcut such as `selfConnection`,
   register `_stateItems[$alias]` with a resolver that returns an `RtState` row
   or `null`, then call `setRepresentItem()` and document it with
   `@property-read` on the context. For collection-backed aliases that expose
   normal item actions, call the owning collection's `setRepresent()` first so
   `RtContext` can attach the parent collection automatically.
2. Define `RtState` fields as real typed properties.
3. Keep serialization and sync methods explicit:
   `toArray()`, `fromRow()`, `applyDiff()`, and `sync()`.
4. Put writes behind runtime collection/item actions.
5. If updating or deleting one runtime item and the key is known, load the item
   and call `$item->actions->...`; do not add a collection action that accepts
   the item key for that one-item write.
6. Verify the truth source agent owns writes before mutating shared RT state.
7. Keep direct backing-state access (`getStateCollection()`,
   `RtContext::getStateCollection()`, `$this->stateCollection`) inside
   `Database/` or `Runtime/` files only; callers need typed collection/item
   APIs.

## Hard Rules

- Do not create a temporary workaround in table/page code when the item,
  collection, action, object, or state layer should be extended.
- Do not introduce Repository or Service classes over `DbCollection` or
  `RtCollection`.
- Only the truth source agent writes to its owned DB/RT collection.
- Do not store durable business state only in `Hilos::$rt`.
- Do not duplicate DB or RT lookup/filter logic in pages.
- Do not use `actions` for read-only helpers; actions are write APIs.
- Do not update or delete one known DB/RT item through collection actions that
  accept that item's key; use the loaded item actions.
- Do not expose arbitrary runtime `applyDiff*()` application APIs; those are sync
  internals.
- Do not use runtime backing-state APIs outside files under `Database/` or
  `Runtime/`; add a typed owning-layer API instead.
- Keep internal backend APIs typed. Use DTOs, value objects, or typed
  collections instead of unstructured arrays unless there is a clear boundary.
