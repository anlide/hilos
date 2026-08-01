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

### Time-based features: no universal clock

There is deliberately **no injectable clock, `now()` override, or global time-mock** in the
framework, and we do not add one. A single knob that fast-forwards time — "purge the account
in 5 seconds instead of 21 days" — is exactly what must never be reachable on prod, and a test
cannot test its own harness, so the only guard is discipline: keep the capability small and
per-feature so it is obvious in review.

So each time-based feature gets its **own** narrow test-only command that ages the one stored
timestamp it needs, making the scheduled logic fire now. For example `test:account:force-purge`
writes the deletion timestamp into the past so the scheduled purge runs immediately. Write these
per-feature and carefully; never generalise them into a shared time-travel utility.

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Reset test database:
php cli.php db:test:reset
```
