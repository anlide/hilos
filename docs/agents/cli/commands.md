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
| `db:test:reset` | DROP all tables → migrate → seed (test env only) — runs before the database exists, but needs the server up |
| `db:wait` | Wait until MySQL is accepting connections — runs while the server is still down |

## System commands

| Command | Description |
|---|---|
| `daemon:status` | Show daemon status (workers, memory, uptime) |
| `daemon:monitor` | Live monitoring of daemon (continuous blocking watch — use `daemon:status` for a one-shot check, not for an AI agent) |
| `help` | List available commands — runs while the server is still down |

## Test-only commands

Some operations are irreversible (deleting an orphan settings row) or time-delayed
(an account deleted N days after a request). To set up or tear down such state for a
test, do **not** engineer idempotency into the test — use a **test-only CLI command**.

These extend `Hilos\Core\CLI\Commands\TestOnlyCommand`, whose `final execute()` refuses
to run unless `APP_ENV` is non-production (same guard as `Seed::isProduction`). Subclassing
is the marker: a reader sees `extends TestOnlyCommand` and knows the command must never run
on prod. Subclasses implement `run()`.

**The socket refuses a test-only command too, and a handler must not repeat it** (HIL-566).
The CLI class guards the CLI process, but a stray caller does not use the CLI: the command
socket authenticates nobody and the routes exist in every project. So `CommandClient` asks
`TestOnlyCommandGate::admitted()` before it branches at all — above both the commands the
master answers itself and the ones it parks for an agent — and answers a refusal with the
same sentence the CLI class raises (`TestOnlyCommandOnProductionException::message()`). The
connection stays open: the gate judges the command, not the caller.

**Declaring a test-only command means saying so twice, in two different languages.** The
machine-readable half is `AgentCommandConfigKey::TEST_ONLY` in the same `AGENT_COMMANDS`
entry that declares the route (the three commands the master answers itself have no agent
entry and are listed in `CommandConstants::MASTER_TEST_ONLY_COMMANDS` instead). The
human-readable half is the `test:` prefix on the wire name. Topology validation fails the
daemon's start if either half is missing, because a flag alone is invisible on review and a
prefix alone is a promise nothing keeps. `TestOnlyCommandRegistry` joins both halves, and it
is the only thing the gate asks.

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

## Database-free commands

The CLI bootstrap connects the database before running a command. A command that must work
when there is no database to connect to declares that itself, by implementing the empty
marker `Hilos\Core\CLI\Commands\DatabaseFreeCommand` — the same "implementing it is the
whole declaration" shape as `TestOnlyCommand`, and a project command declares it the same
way. `CliApplication` asks `CliManager::requiresDatabase()` and skips the connect; an
unregistered command name skips it too, so a typo answers `Unknown command` instead of a
connection failure.

Marked today: `db:wait` (its poll *is* the connect, and it has to run before the server
answers), `db:test:reset` (opens its own server-level connection, because it drops and
recreates the database `DB_DATABASE` names), `help` (prints the registry it was handed),
`test:cluster:inspect` (talks only to the local command socket, so the multi-node harness
can inspect a network-partitioned node that cannot reach MySQL either),
`test:notification:emit` (every row it causes is written by the agent that answers it, so
the CLI process itself has nothing to read or write) and the protected-mode family
`test:protected-mode:inspect` / `:enter` / `:leave` / `:open` / `:pass` (the inspector reads
in-memory state and has to answer on a frozen node, which is exactly where a connect would
hang; the drive commands write nothing from this process, and the mint's row is the agent's). Everything else keeps the full connect plus
`Hilos::init()`.

Because the whole registry is now constructed *before* the connect, a command constructor
must not touch the database or Hilos state. Do that work in `execute()`.

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Reset test database:
php cli.php db:test:reset
```
