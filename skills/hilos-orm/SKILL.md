---
name: hilos-orm
description: Work with Hilos ORM entities, object layers, DbCollection queries, collection actions, migrations, seeds, schema consistency, Hilos::$db usage, accessor contracts, and database-backed features. Use when creating or modifying Entity classes, Object classes, migrations, DB actions, schema checks, collection access, or persistence behavior.
---

# Hilos ORM

Use this skill for database-backed Hilos work and for any code that reads or
writes through `Hilos::$db`. Start with `agents.md`, then read the smallest ORM
document that matches the change.

## Read First

- `Hilos::$db`, `DbContext`, `DbCollection`, `DbItem`, actions, and examples:
  `docs/agents/orm/db-collection.md`
- Magic, array, result, and finder selection:
  `docs/agents/orm/accessor-contracts.md` or `$hilos-accessor-contracts`
- Frontend-safe DB item serialization and computed item fields:
  `docs/agents/orm/frontend-representation.md` or
  `$hilos-frontend-representation`
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
- `DbItem` is the read-only item facade returned from a `DbCollection`.
- Entity classes mirror raw DB rows; Object classes hold enriched or transformed
  view data; View collection/item classes expose the app-facing DB API.
- Collection actions handle create/register/add and true collection-wide or
  bulk writes.
- Item actions handle update/delete writes for one loaded item.
- Read helpers belong on `DbCollection`, `DbItem`, Object helpers, or typed
  projection APIs, not on `actions`.

## Workflow

1. Find the existing `DbContext` collection constant and `setRepresent()` entry
   before adding new data logic.
2. Inspect the matching View collection/item, Object collection/item, Entity, and
   Actions classes.
3. Decide whether the change belongs in an Entity, Object, View collection
   method, collection action, item action, migration, or caller code.
4. Use existing `Hilos::$db` access paths and accessor contracts directly from
   callers:
   `Hilos::$db->users`, `Hilos::$db->users->actions->register(...)`,
   `$user->actions->update(...)`.
5. Prefer documented array/magic/result accessors before adding or calling a
   redundant finder. Use named finders for business-key or complex queries when
   the collection does not document matching array access.
6. Put new DB writes in collection actions or item actions, not in page/table
   handlers.
7. When updating or deleting one DB item and the collection key is known, load
   the item and call `$item->actions->...`; do not add collection actions that
   accept the item key for that one-item write.
8. Put read helpers and lookup methods on the existing collection/item layer
   before introducing new API surface. During transparent data-shape refactors,
   keep simple field checks explicit unless a new method was approved by name.
9. Add migrations for schema changes and update Entities to match schema.
10. Run schema/test validation through composer scripts, never direct host
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
- Never update or delete one known DB item through collection actions that
  accept that item's key; use the loaded `DbItem` actions.
- Never put business logic or DB queries inside Entity classes.
- Only the truth source agent writes to its owned DB/RT collection.
