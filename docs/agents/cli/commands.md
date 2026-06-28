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
| `db:entity:diff` | Diff Entity class fields vs actual DB columns (deprecated — do not rely on it) |

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
| `daemon:status` | Show daemon status (workers, memory, uptime) |
| `daemon:monitor` | Live monitoring of daemon (continuous blocking watch — use `daemon:status` for a one-shot check, not for an AI agent) |
| `help` | List available commands |

## Test-only commands

Some operations are irreversible (deleting an orphan settings row) or time-delayed
(an account deleted N days after a request). To set up or tear down such state for a
test, do **not** engineer idempotency into the test — use a **test-only CLI command**.

These extend `Hilos\Core\CLI\Commands\TestOnlyCommand`, whose `final execute()` refuses
to run unless `APP_ENV` is non-production (same guard as `Seed::isProduction`). Subclassing
is the marker: a reader sees `extends TestOnlyCommand` and knows the command must never run
on prod. Subclasses implement `run()`.

A project registers its own commands by subclassing `CliManager` and overriding
`registerProjectCommands()`, calling `addCommand()` for each; the project's `cli.php` then
news the project manager:

```php
final class ChatCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new CreateOrphanSettingCommand());
        $this->addCommand(new DeleteOrphanSettingCommand());
    }
}
```

Worked example (chat): `test:orphan-setting:create` / `test:orphan-setting:delete` write and
remove an orphan settings row — a demonstration of the mechanism, in
`demo/chat/backend/CLI/Commands/`.

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Reset test database:
php cli.php db:test:reset
```
