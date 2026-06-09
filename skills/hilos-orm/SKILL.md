---
name: hilos-orm
description: Work with Hilos ORM entities, object layers, DbCollection queries, collection actions, migrations, seeds, schema consistency, Hilos::$db usage, accessor contracts, and database-backed features. Use when creating or modifying Entity classes, Object classes, migrations, DB actions, schema checks, collection access, or persistence behavior.
---

# Hilos ORM

Use this skill for database-backed Hilos work and for any code that reads or
writes through `Hilos::$db`. Start with `agents.md`, then read
`docs/agents/orm/README.md` and follow its mandatory reading matrix for the
touched ORM surfaces.

## Read First

- ORM document index and mandatory follow-up matrix:
  `docs/agents/orm/README.md`
- `Hilos::$db`, `DbContext`, `DbCollection`, `DbItem`, actions, and examples:
  `docs/agents/orm/db-collection.md`
- Direct relation bridge properties on `Database/View/Item/*` and their
  collection contracts: `docs/agents/orm/db-item-bridges.md`
- Magic, array, result, and finder selection:
  `docs/agents/orm/accessor-contracts.md` or `$hilos-accessor-contracts`
- Entity classes and DB table mapping: `docs/agents/orm/entity.md`
- Object layer and view transformations: `docs/agents/orm/object.md`
- Migrations and seeds: `docs/agents/orm/migrations.md`
- Repository/service anti-pattern: `docs/agents/antipatterns/no-repository-service.md`
- Test commands: use `$hilos-testing-cli`

## Mental Model

- `Hilos::$db` is the typed database context entry point for the current app.
- A project `DbContext` registers object collections, then exposes typed
  `DbCollection` wrappers such as `Hilos::$db->users` or
  `Hilos::$db->settings`.
- `DbCollection` is the read/query facade for a table-backed object collection.
  DB collection reads treat `null` offsets as missing optional relation keys.
- `DbItem` is the read-only item facade returned from a `DbCollection`.
- Entity classes mirror raw DB rows; Object classes hold enriched or transformed
  view data; View collection/item classes expose the app-facing DB API.
- Collection actions handle create/register/add and true collection-wide or
  bulk writes.
- Item actions handle update/delete writes for one loaded item.
- Read helpers belong on `DbCollection`, `DbItem`, Object helpers, or typed
  read payload APIs, not on `actions`.

## Workflow

1. Read `docs/agents/orm/README.md` and every document required by the
   touched-surface matrix.
2. Find the existing `DbContext` collection constant and `setRepresent()` entry
   before adding new data logic.
3. Inspect the matching View collection/item, Object collection/item, Entity, and
   Actions classes.
4. Decide whether the change belongs in an Entity, Object, View collection
   method, collection action, item action, migration, or caller code.
5. Use existing `Hilos::$db` access paths and accessor contracts directly from
   callers:
   `Hilos::$db->users`, `Hilos::$db->users->actions->register(...)`,
   `$user->actions->update(...)`.
6. Prefer documented array/magic/result accessors before adding or calling a
   redundant finder. Use named finders for business-key or complex queries when
   the collection does not document matching array access.
7. Put direct relations from loaded DB items on View item bridge properties;
   keep parent View items limited to their own scalar fields plus bridge
   properties. Do not add pass-through bridges through another bridge item.
   Document collection offset semantics when a bridge uses `[]`. Do not add
   caller-side guards only to protect DB collection access from nullable keys.
   For direct one-to-one DB/RT overlays, add both View-item bridge directions
   immediately. If the overlay/status model starts with the parent model name,
   name the reverse bridge by the remaining semantic suffix, such as
   `$bot->agentStatus` for `BotAgentStatus`.
   Do not create temporary id or foreign-key aliases inside bridge `__get()`
   methods when the alias only feeds relation lookups.
8. Put new DB writes in collection actions or item actions, not in page/table
   handlers.
9. When updating or deleting one DB item and the collection key is known, load
   the item and call `$item->actions->...`; do not add collection actions that
   accept the item key for that one-item write.
10. Put read helpers and lookup methods on the existing collection/item layer
   before introducing new API surface. During transparent data-shape refactors,
   keep simple field checks explicit unless a new method was approved by name.
11. Add migrations for schema changes and update Entities to match schema.
12. Run schema/test validation through composer scripts, never direct host
   phpunit.

## Examples

```php
Hilos::$db->users->findBySession($sessionToken);
Hilos::$db->users->actions->register($sessionToken);

if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]->actions->updateValue($value);
```

When a collection supports array-style access, prefer the collection API instead
of rebuilding queries in a page:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

Hilos::$db->users[$userId]; // DB item by documented collection key

if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]; // If the collection supports key access
```

Use the named finder when the offset contract does not match the business key:

```php
Hilos::$db->users->findBySession($sessionToken);
```

## Hard Rules

- Never run `git commit` or `git push`.
- Never add Repository or Service classes on top of `DbCollection`.
- Never bypass the DB layer with raw SQL or manual mutation logic in page/table
  layers when a collection/item/action exists or should own the behavior.
- Never add a `findById()` or caller-local helper when the collection already
  documents `[$id]`, `get($id)`, or a typed item/result accessor for the same
  value.
- Never replace a named finder with `[$key]` unless the collection documents
  that offset as the same key.
- Never use `actions` for read-only helpers; actions are write APIs.
- Never rebuild direct DB item relation lookups in pages, tables, agents, or
  signal handlers when a View item bridge property should own the relation.
- Never flatten scalar fields from related detail rows onto a parent DB View
  item; callers must read those fields through the bridge item.
- Never expose pass-through DB View bridges that only forward through another
  bridge item.
- Never create pass-through id or foreign-key aliases inside bridge `__get()`
  methods only to shorten relation lookups.
- Never leave a direct one-to-one DB/RT overlay one-sided; expose both
  View-item bridge directions even if one side is not currently used.
- Never repeat the parent model name in a reverse overlay/status bridge when
  the related model name is the parent plus a semantic suffix; use the suffix
  in `lowerCamelCase`.
- Never add reverse one-to-many View item bridges just because the schema can
  be traversed; require a direct key plus a caller-facing domain or payload
  contract.
- Never update or delete one known DB item through collection actions that
  accept that item's key; use the loaded `DbItem` actions.
- Never put business logic or DB queries inside Entity classes.
- Only the truth source agent writes to its owned DB/RT collection.
