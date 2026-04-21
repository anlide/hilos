---
name: hilos-orm
description: Work with Hilos ORM entities, object layers, DbCollection queries, collection actions, migrations, seeds, schema consistency, Hilos::$db usage, and database-backed features. Use when creating or modifying Entity classes, Object classes, migrations, DB actions, schema checks, or persistence behavior.
---

# Hilos ORM

Use this skill for database-backed Hilos work. Start with `agents.md`, then read the smallest ORM document that matches the change.

## Read First

- Entity classes and DB table mapping: `docs/agents/orm/entity.md`
- Object layer and view transformations: `docs/agents/orm/object.md`
- Queries, collection actions, `Hilos::$db`: `docs/agents/orm/db-collection.md`
- Migrations and seeds: `docs/agents/orm/migrations.md`
- Repository/service anti-pattern: `docs/agents/antipatterns/no-repository-service.md`
- Test commands: use `$hilos-testing-cli`

## Workflow

1. Decide whether the change belongs in Entity, Object, DbCollection action, migration, or caller code.
2. Keep Entity classes as typed DB row containers only.
3. Keep Object classes responsible for transformed/enriched view data.
4. Use `Hilos::$db->collection->actions->...` directly for writes and actions.
5. Add migrations for schema changes and update Entities to match schema.
6. Run schema/test validation through composer scripts, never direct host phpunit.

## Hard Rules

- Never run `git commit` or `git push`.
- Never add Repository or Service classes on top of `DbCollection`.
- Never put business logic or DB queries inside Entity classes.
- Only the truth source agent writes to its owned DB/RT collection.
