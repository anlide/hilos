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
- `Hilos::$db->collection[$id]` and `Hilos::$rt->collection[$id]` are preferred
  for known collection keys when the collection documents that key.
- Key-based array access such as `Hilos::$db->settings[$key]` is preferred when
  the collection explicitly supports that business key as its offset.
- Existing item properties, `__get()` bridges, typed DTO fields, and result
  accessors should be used before adding a new finder or helper.
- A named `findBy*()` method is still correct for complex queries, ambiguous
  criteria, business-key lookups without array support, or lookup semantics that
  need a clear name.

## Workflow

1. Identify the key or value the caller has: primary id, collection key,
   business key, composite criteria, or a value already present on a result.
2. Inspect the View collection and item PHPDoc/methods for `offsetGet()`,
   `get()`, `__get()`, item properties, `toArray()`, and existing `findBy*()`
   methods.
3. Inspect the Object or State collection when the View layer delegates there.
4. Prefer the smallest existing contract that matches the value exactly.
5. Use array access only when the offset semantics are documented for that
   collection. Do not guess that `[]` means a business key.
6. Use a named finder when the lookup is not the collection key or when the
   method name carries important query semantics.
7. Add a new reusable lookup only on the owning collection/item layer; do not
   hide it as a page, table, or agent helper.
8. If adding a new accessor, document its key semantics in PHPDoc and keep null
   behavior explicit.

## Examples

Prefer collection key access when the key is the documented offset:

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
setting key as its offset:

```php
if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]; // Setting item by documented key-based offset
```

Use a named finder when the lookup is not the collection key and the collection
does not document a matching offset contract:

```php
Hilos::$db->users->findBySession($sessionToken);
```

Use result or item accessors before adding a new finder:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

count(Hilos::$db->users[$userId]->connections);

if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]->getEffectiveValue($catalog);
```

## Exceptions

Use or add a named method instead of magic/array access when:

- the collection offset is a different key than the value you have;
- the lookup has multiple criteria, filtering, sorting, permissions, or lazy
  loading behavior that needs a name;
- null/missing semantics are not the standard collection semantics;
- the accessor would hide a DB/RT aggregation that belongs in a typed collection
  method, item bridge, DTO, or signal payload.

## Hard Rules

- Do not add `findById()` or a page-local helper when `[$id]`, `get($id)`, or a
  typed item accessor already expresses the contract.
- Do not replace a named finder with `[$key]` unless the collection documents
  that offset as the same business key.
- Do not duplicate collection lookup logic in pages, tables, agents, or signal
  handlers.
- Keep new accessor APIs typed and discoverable on the owning collection or
  item layer.
