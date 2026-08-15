# ORM: Migrations

Migrations are versioned SQL files applied in order. No PHP migration classes — plain SQL only.

## File location

`backend/Database/migrations/` (project-level, configured in Bootstrap)

## File naming

```
001_create_users.sql
002_add_email_to_users.sql
003_create_events.sql
```

Prefix is a zero-padded integer. Applied in numeric order.

## Up/Down

Each migration file contains UP SQL. DOWN is in a separate file or not provided.

## CLI commands

```bash
php cli.php db:migration:up      # apply all pending migrations
php cli.php db:migration:down    # roll back last migration
php cli.php db:migration:status  # show applied/pending
php cli.php db:migration:retry   # retry failed migration
```

See `cli/commands.md` for full list.

## Seeds

Seeds populate initial data. Located in `backend/Database/seeds/`.

```bash
php cli.php db:seed:apply 001
```

## Schema check

`DbSchemaStatusCommand` (`db:schema:status`) checks if the DB schema matches expected structure.

## Important rules

- Never modify an already-applied migration file — create a new one instead
- Migration runs in transaction where possible
- Test migrations in test environment before applying to production
- After schema change: update corresponding `Entity` class fields to match
- A new table needs a row in the project's PII registry — an empty column map when
  it holds nothing personal — or a restore that requires anonymization refuses
  before it imports anything
  ([../architecture/backup-anonymization.md](../architecture/backup-anonymization.md))

## Test reset

```bash
php cli.php db:test:reset  # DROP → migrate → seed (test env only)
```
