---
name: hilos-app-data-access
description: Use Hilos::$db and Hilos::$rt correctly from application code. Use when reading DB or runtime data in pages, tables, agents, action handlers, table actions, signal handlers, page/table topology code, or when choosing collection access, item access, action calls, settings access, existing magic/result accessors, array access, or find helpers.
---

# Hilos App Data Access

Use this skill when writing caller code that consumes existing `Hilos::$db` or
`Hilos::$rt` APIs from pages, tables, agents, actions, and signal handlers. This
skill is for application orchestration. If the data model itself needs to grow,
switch to the focused data-layer skill first.

## Read First

- DB collections, items, actions, and `Hilos::$db` access: use `$hilos-orm`.
- Runtime collections, items, truth sources, and `Hilos::$rt` access: use
  `$hilos-runtime`.
- Adding DB/RT shape, lookup APIs, or write paths: use `$hilos-data-extension`.
- DB-backed item plus live runtime overlay: use `$hilos-db-rt-state`.
- Page/table topology registry and page-table bindings:
  `docs/agents/app-topology.md`.
- Choosing between magic, array, result, and `findBy*()` access:
  use `$hilos-accessor-contracts`.
- Page action routing and action error behavior:
  `docs/agents/code-style/page-action-handlers.md`.
- Signal routing and DTO payloads: use `$hilos-signals`.

## Mental Model

- Caller code should orchestrate existing typed APIs; it should not become the
  owner of DB lookup logic, runtime aggregation, browser/frontend payload
  shaping, and signal delivery at the same time.
- `Hilos::$db` is the durable business data entry point. Use it for persisted
  users, settings, catalog rows, history, and other state that must survive
  restart.
- `Hilos::$rt` is the live runtime state entry point. Use it for active
  connections, upload progress, pending UI flags, and state that can be rebuilt
  or safely lost.
- Collection access is for loading and querying groups of items.
- Item access is for reading one loaded model and its item-level calculated
  properties.
- DB collection reads and RT View collection reads treat `null` offsets as
  missing optional keys. Caller code should guard when an item is required, not
  just to protect `[]` from a nullable key.
- Collection actions are for create/register/ensure and true collection-wide or
  bulk writes.
- Item actions are for changing or deleting one loaded item.
- Actions are write APIs. Read-only helpers belong on collections, items,
  objects, or typed read APIs.
- Tables and pages assemble responses by calling model APIs. They should not
  duplicate filters, ad hoc joins, or mutation logic that belongs to DB/RT
  collections, items, or actions.

## Workflow

1. Classify the caller: page, table, agent, DB action, RT action, signal handler,
   or subscription.
2. Decide whether the caller needs durable DB data, runtime state, or a DB item
   with runtime overlay.
3. Search the existing collection and item APIs before adding code:
   `Hilos::$db->users`, `Hilos::$db->settings`, `Hilos::$rt->connections`,
   magic accessors, array access, `findBy*()` methods, and item properties.
4. Prefer an existing accessor or magic/array contract when it is part of that
   collection API. Use `$hilos-accessor-contracts` when the correct accessor is
   not obvious.
5. For reads, call the collection or item directly and keep local payload assembly
   minimal.
6. Never call `getStateCollection()`, `RtContext::getStateCollection()`, or
   `$this->stateCollection` from agents, pages, tables, signal handlers, tests,
   or other orchestration code. If the read API is missing, add it to the
   owning `Database/` or `Runtime/` layer first. During transparent data-shape
   refactors, keep simple field checks explicit unless a new method was
   approved by name.
7. For writes, call a collection action or item action. When a DB/RT item key is
   known and the write changes or deletes that one item, load the item and call
   `$item->actions->...` instead of a collection action that accepts the key.
8. Do not write raw DB/RT state from page, table, or signal-delivery code.
9. If a reusable lookup is missing, add it to the owning collection/item layer
   through `$hilos-data-extension`; do not hide it as a private caller helper.
10. If the value is a model-level frontend field, put it on the Object/View item,
   typed DTO, or signal payload; keep table/page code as assembly.
11. Keep signal delivery separate from business writes: perform the write through
   the owning action, then let the established subscription/signal contract emit
   or route the result.
12. Validate through the narrow composer script selected by
    `$hilos-testing-cli`.

## Choosing The API

| Need | Prefer |
|---|---|
| Load a known DB item | `Hilos::$db->collection[$id]` when supported |
| Load by business key | Array access only when documented; otherwise an existing accessor such as `findBySession()` |
| Read key-based settings | `Hilos::$db->settings[$dto->key]` only when settings documents key-based offsets |
| Create a DB item | `Hilos::$db->collection->actions->create(...)` |
| Update one DB item | `$item->actions->update(...)` |
| Read runtime item | `Hilos::$rt->collection[$id]` when supported |
| Read runtime rows for one DB item | Existing RT collection helper such as `forUser(...)` |
| Create/register/ensure runtime state | RT collection action owned by the truth source |
| Update/delete one runtime item | Loaded `RtItem` action owned by the truth source |
| Add a missing reusable lookup | Collection/item layer, not page/table private helper |
| Build frontend row data | Browser/table row contract from model API or View item serialization |

If both array access and a finder exist, use the contract that best matches the
collection semantics. For settings and other key-based collections, array access
by key is the clearer API when the collection documents it; otherwise use the
named finder that matches the actual lookup or add a typed collection contract
first.

## Examples

Read settings through the collection contract instead of rebuilding a lookup in
the page. If the key-based collection documents array access, use that contract:

```php
if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]; // Setting item by documented key-based offset
```

Use a named accessor only when the lookup is not a documented collection offset:

```php
Hilos::$db->users->findBySession($sessionToken);
```

Use actions for writes:

```php
if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]->actions->updateValue($dto->value);
```

Do not add a helper when a result or item accessor already exposes the value:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

count(Hilos::$db->users[$userId]->connections);

if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]->value;
```

Read runtime state through `Hilos::$rt` without storing durable business truth
there:

```php
foreach (Hilos::$rt->connections->forUser($userId) as $userConnection) {
    // Use the iterated connection item directly.
}

Hilos::$rt->connections[$acceptKey]?->actions->unregister();
```

Keep table row assembly thin:

```php
foreach (Hilos::$db->users as $user) {
    $rows[] = $this->rowFromUser($user)->toArray();
}
```

Use an item-level bridge when the frontend row needs DB plus RT state:

```php
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;

if (!isset(Hilos::$db->users[$userId])) {
    return;
}

$result[AdminUserTableRow::onlineSessionCount] = count(Hilos::$db->users[$userId]->connections);
```

## Anti-Patterns

Do not mix business write, payload assembly, and manual signal routing in one handler:

```php
$payload = $this->buildManualPayload($id, $value); // WRONG
$this->manualSave($payload); // WRONG
$this->sendToUser($userId, $payload); // WRONG
```

Call the owning action and use the existing page/table/signal contract instead:

```php
$item->actions->updateValue($dto->value);
```

Do not build ad hoc arrays from DB and RT collections when a collection/item API
should expose the relationship:

```php
use Demo\Chat\Tables\AdminUser\AdminUserTableRow; // WRONG

$rows = []; // WRONG
foreach (Hilos::$db->users as $user) { // WRONG
    $rows[] = [ // WRONG
        AdminUserTableRow::id => $user->id, // WRONG
        AdminUserTableRow::onlineSessionCount => count(Hilos::$rt->connections->forUser($user->id)), // WRONG
    ]; // WRONG
} // WRONG
```

Move reusable relationships to the DB item, RT collection, or typed payload contract,
then call that API from the table/page.

## Hard Rules

- Search existing magic/accessor results before adding a new finder or helper.
- Do not use `[$key]` blindly; verify that the collection documents the offset
  key you plan to use.
- Do not call `getStateCollection()`, `RtContext::getStateCollection()`, or
  `$this->stateCollection` outside files under `Database/` or `Runtime/`.
- Do not bypass `Hilos::$db` or `Hilos::$rt` with raw arrays, raw SQL, or
  duplicated filters in page/table/agent code.
- Do not duplicate project page or table registries in caller code; use the
  project `Hilos` topology registry.
- Do not store durable business state in `Hilos::$rt`.
- Do not write DB/RT state directly from pages, tables, or signal handlers when
  a collection/item action owns the mutation.
- Do not update or delete one known DB/RT item through collection actions that
  accept that item's key; use the loaded item actions.
- Do not put read-only helpers on `actions`.
- Do not introduce Repository or Service wrappers over DB or RT collections.
- Keep internal backend APIs typed with DTOs, value objects, typed collections,
  or explicit model APIs.
- Run validation through composer scripts selected by `$hilos-testing-cli`.
