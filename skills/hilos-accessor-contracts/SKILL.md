---
name: hilos-accessor-contracts
description: Choose existing Hilos magic, array, result, collection, and item accessors before adding or calling extra find/helper methods. Use when reading from Hilos::$db or Hilos::$rt, choosing between $collection[$key] and findBy*(), working with settings or key-based collections, adding lookup APIs, or reviewing page/table/agent code that duplicates collection access.
---

# Hilos Accessor Contracts

Use this skill when choosing how caller code should read an existing Hilos item,
result, or collection value. Start with `agents.md`, then read
`docs/agents/orm/accessor-contracts.md`.

## Mental Model

- Accessor shape is part of the collection or item contract.
- For a documented DB View key, use `[$id]` for a required row, `isset` before
  an optional read, and `?? null` for a nullable item value or bridge. Follow
  `docs/agents/orm/accessor-contracts.md`; do not add a DB View `get()`.
- Object/Entity and RT View reads keep their nullable contracts, including
  `null` keys. Object-layer `get()` remains valid.
- RT backing `RtStates` use `get($nullableId)` for optional state lookups;
  required `[]` depends on the concrete state collection's contract.
- Key-based array access such as `Hilos::$db->settings[$key]` is preferred when
  the collection explicitly supports that business key as its offset.
- Caller code with a settings key must use `Hilos::$db->settings[$key]`; if the
  offset contract is missing, fix the collection contract instead of calling
  `Hilos::$db->settings->findByKey($key)` as a shortcut.
- `Hilos::$setting[$key]` is not the settings collection: it is a catalog-backed
  accessor whose index hands back a typed reader, and its shape is fixed by
  `docs/agents/code-style/catalog-backed-accessors.md`.
- Existing item properties, `__get()` bridges, typed DTO fields, and result
  accessors should be used before adding a new finder or helper.
- A named `findBy*()` method is still correct for complex queries, ambiguous
  criteria, business-key lookups without array support, or lookup semantics that
  need a clear name.
- Walking a collection is its own contract, not a lookup: a `foreach` observes
  the keys the collection held when the walk started, so a row removed during
  the walk is skipped and a row added during it is not seen. Read
  `docs/agents/orm/collection-iteration.md` before mutating inside a walk, and
  do not collect the keys into an array first to survive one.

## Workflow

Before choosing the accessor, read `docs/agents/orm/entity-references.md` for
internal targets, external code boundaries and the delete/recreate review.

1. Identify the key or value the caller has: primary id, collection key,
   business key, composite criteria, or a value already present on a result.
2. Inspect the View collection and item PHPDoc/methods for `offsetGet()`,
   `__get()`, item properties, `toArray()`, and existing `findBy*()`
   methods.
3. Inspect the Object or State collection when the View layer delegates there,
   including that layer's existing `get()` contract.
4. For direct DB item relations, read `docs/agents/orm/db-item-bridges.md` and
   prefer an existing bridge property before adding a finder.
5. Prefer the smallest existing contract that matches the value exactly.
6. Use array access only when the offset semantics are documented for that
   collection. Do not guess that `[]` means a business key.
7. Use a named finder when the lookup is not the collection key or when the
   method name carries important query semantics.
8. Add a new reusable lookup only on the owning collection/item layer; do not
   hide it as a page, table, or agent helper.
9. If adding a new accessor, document its key semantics in PHPDoc and keep null
   behavior explicit.

## Examples

Read a required DB row by its documented key:

```php
Hilos::$db->users[$userId]->name;
```

These callers permit absence and check existence before using the item:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

Hilos::$db->users[$userId]; // DB item by documented collection key

if (!isset(Hilos::$rt->connections[$acceptKey])) {
    return;
}

Hilos::$rt->connections[$acceptKey]->actions->unregister();
```

Prefer key-based settings access only if the settings collection documents the
setting key as its offset. If that contract is absent, add and document it on
the settings collection before changing caller code. This caller permits absence:

```php
if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]; // Setting item by documented key-based offset
```

Do not call the settings collection finder from caller code:

```php
// Wrong outside the settings collection.
Hilos::$db->settings->findByKey($dto->key);
```

Use a named finder when the lookup is not the collection key and the collection
does not document a matching offset contract:

```php
Hilos::$db->sessions->findByToken($token);
```

Use result or item accessors before adding a new finder:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

count(Hilos::$db->users[$userId]->connections);

if (!isset(Hilos::$db->events[$eventId])) {
    return;
}

Hilos::$db->events[$eventId]->eventMessage?->message;
Hilos::$db->eventMessages[$eventId] ?? null; // Nullable item value

if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]->value;
```

## Exceptions

Use or add a named method instead of magic/array access when:

- the collection offset is a different key than the value you have;
- the lookup has multiple criteria, filtering, sorting, permissions, or lazy
  loading behavior that needs a name;
- a specialized lookup needs semantics beyond the standard required/optional
  forms; ordinary optional DB View reads are not a reason to add `get()`;
- the accessor would hide a DB/RT aggregation that belongs in a typed collection
  method, item bridge, DTO, or signal payload.

## Hard Rules

- Do not add `findById()` or a page-local helper when the documented key or
  typed item accessor already expresses the contract. Object/state `get()`
  remains a separate layer's API.
- Do not add DB View `get()` or `require*()` wrappers, or use `instanceof`
  narrowing for standard reads by a documented key; follow the canonical forms.
- Do not call `Hilos::$db->settings->findByKey($key)` outside the settings
  collection. Use `Hilos::$db->settings[$key]`; if needed, first add and
  document the settings key offset contract.
- Do not replace a named finder with `[$key]` unless the collection documents
  that offset as the same business key.
- Do not duplicate collection lookup logic in pages, tables, agents, or signal
  handlers.
- Do not rebuild direct DB item relation lookups when a View item bridge
  property should own the relation.
- Keep new accessor APIs typed and discoverable on the owning collection or
  item layer.
