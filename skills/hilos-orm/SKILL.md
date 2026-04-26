---
name: hilos-orm
description: Work with Hilos ORM entities, object layers, DbCollection queries, collection actions, migrations, seeds, schema consistency, Hilos::$db usage, and database-backed features. Use when creating or modifying Entity classes, Object classes, migrations, DB actions, schema checks, or persistence behavior.
---

# Hilos ORM

Use this skill for database-backed Hilos work and for any code that reads or
writes through `Hilos::$db`. Start with `agents.md`, then read the smallest ORM
document that matches the change.

## Read First

- `Hilos::$db`, `DbContext`, `DbCollection`, `DbItem`, actions, and examples:
  `docs/agents/orm/db-collection.md`
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
- Collection actions handle collection-wide writes such as add/register.
- Item actions handle writes for one loaded item such as update/delete.

## Workflow

1. Find the existing `DbContext` collection constant and `setRepresent()` entry
   before adding new data logic.
2. Inspect the matching View collection/item, Object collection/item, Entity, and
   Actions classes.
3. Decide whether the change belongs in an Entity, Object, View collection
   method, collection action, item action, migration, or caller code.
4. Use existing `Hilos::$db` access paths directly from callers:
   `Hilos::$db->users`, `Hilos::$db->users->actions->register(...)`,
   `$user->actions->update(...)`.
5. Put new DB writes in collection actions or item actions, not in page/table
   handlers.
6. Put read helpers and lookup methods on the existing collection/item layer
   before introducing new API surface.
7. Add migrations for schema changes and update Entities to match schema.
8. Run schema/test validation through composer scripts, never direct host
   phpunit.

## Examples

```php
$user = Hilos::$db->users->findBySession($acceptKey);
$user = Hilos::$db->users->actions->register($sessionToken);
$settings = Hilos::$db->settings;
$setting = $settings->findByKey($key);
$setting?->actions->update(['value' => $value]);
```

When a collection supports array-style access, prefer the collection API instead
of rebuilding queries in a page:

```php
$user = Hilos::$db->users[$userId] ?? null;
$setting = $settings[$key] ?? null; // If the collection supports key access.
```

## Hard Rules

- Never run `git commit` or `git push`.
- Never add Repository or Service classes on top of `DbCollection`.
- Never bypass the DB layer with raw SQL or manual mutation logic in page/table
  layers when a collection/item/action exists or should own the behavior.
- Never put business logic or DB queries inside Entity classes.
- Only the truth source agent writes to its owned DB/RT collection.
