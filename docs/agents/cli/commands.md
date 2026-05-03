# CLI Commands

Run via: `php Backend/Bootstrap/cli.php <command> [options]`

## Migration commands

| Command | Description |
|---|---|
| `db:migration:up` | Apply all pending migrations |
| `db:migration:down` | Roll back last applied migration |
| `db:migration:status` | Show applied / pending migrations |
| `db:migration:retry` | Retry a failed migration |

## Schema / Entity commands

| Command | Description |
|---|---|
| `db:schema:status` | Check DB schema vs expected structure |
| `db:entity:diff` | Diff Entity class fields vs actual DB columns |

## Seed commands

| Command | Description |
|---|---|
| `db:seed:apply <n>` | Apply seed number N |

## DB utility commands

| Command | Description |
|---|---|
| `db:test:reset` | DROP all tables → migrate → seed (test env only) |
| `db:wait` | Wait until MySQL is accepting connections |

## System commands

| Command | Description |
|---|---|
| `status` | Show daemon status (workers, memory, uptime) |
| `monitor` | Live monitoring of daemon |
| `help` | List available commands |

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Check what's different between Entity and DB:
php cli.php db:entity:diff

# Reset test database:
php cli.php db:test:reset
```
