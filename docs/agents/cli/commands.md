# CLI Commands

Run via: `php Backend/Bootstrap/cli.php <command> [options]`

Every command below carries a **site**: the process its work actually happens in. `daemon`
is the rule and needs no explanation; the other three are departures, each with a written
reason in its `execution()` declaration. See
[command-execution.md](command-execution.md) for the rule, the guard behind it, and why a
`cli-offline-write` command refuses to start while a daemon is running.

## Migration commands

| Command | Site | Description |
|---|---|---|
| `db:migration:up` | `cli-offline-write` | Apply all pending migrations |
| `db:migration:down` | `cli-offline-write` | Roll back last applied migration |
| `db:migration:status` | `cli-read` | Show applied / pending migrations |
| `db:migration:retry` | `cli-offline-write` | Retry a failed migration |

## Schema / Entity commands

| Command | Site | Description |
|---|---|---|
| `db:schema:status` | `cli-read` | Check DB schema vs expected structure |

## Seed commands

| Command | Site | Description |
|---|---|---|
| `db:seed:apply <n>` | `cli-offline-write` | Apply seed number N |

## DB utility commands

| Command | Site | Description |
|---|---|---|
| `db:test:reset` | `cli-offline-write` | DROP all tables → migrate → seed (test env only) — runs before the database exists, but needs the server up |
| `db:wait` | `cli-read` | Wait until MySQL is accepting connections — runs while the server is still down |

## System commands

| Command | Site | Description |
|---|---|---|
| `daemon:status` | `daemon` | Show daemon status (workers, memory, uptime) — over the HTTP status endpoint, which is a transport and not a site |
| `daemon:monitor` | `daemon` | Live monitoring of daemon (continuous blocking watch — use `daemon:status` for a one-shot check, not for an AI agent) |
| `daemon:ping` | `daemon` | Probe the command channel itself |
| `help` | `cli-read` | List available commands — runs while the server is still down |

## Account and session commands

Every one of them is `daemon`: the CLI sends a request and prints the reply, because what
they change lives where the accounts and the sessions do. All six are the framework's since
HIL-729, and all six are answered by the sessions library in every project, because each of
them ends in a session being told who it is now — the merge by consequence, since a folded-away
account's open tabs have to be signed out before the merge is true. What a project is asked is
a seam apiece: whether the takeover is allowed, and — for the merge — whether these two
accounts may be merged at all plus what this project keeps for a person. A project that wires
no seam refuses rather than falling silent.

| Command | Site | Description |
|---|---|---|
| `admin:grant <userId>` | `daemon` | Grant a user the Hilos admin flag |
| `admin:revoke <userId>` | `daemon` | Take the Hilos admin flag away from a user |
| `admin:create <sessionToken>` | `daemon` | Make a browser session an administrator, minting its user when it has none |
| `impersonate:start <sessionToken> <userId>` | `daemon` | Make an admin session act as another user |
| `impersonate:stop <sessionToken>` | `daemon` | Return an impersonating session to the administrator behind it |
| `account:merge <survivorId> <loserId> [--password=…]` | `daemon` | Fold one populated account into another |

`account:merge` reports what moved as a map rather than a count: the framework moves the
sign-in identities itself and asks the project to move the rows only it knows about, so the
reply carries the project's own tally under the project's own names — in chat, `messages`.
It is also the one command of the six with a second way in: an admin table submits the same
merge as a page action, the page forwards it to the library on `hilos_account_merge`, and the
outcome comes back on `hilos_account_merge_result` for the project to ack under its own name.

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
`NonProductionGate::admitted()` before it branches at all — above both the commands the
master answers itself and the ones it parks for an agent — and answers a refusal with the
same sentence the CLI class raises (`TestOnlyCommandOnProductionException::message()`). The
connection stays open: the gate judges the command, not the caller. Why it authenticates
nobody, and why nothing will be built to change that, is settled in
[../architecture/command-server.md](../architecture/command-server.md#who-may-call-a-command--nobody-is-asked).

**Declaring a test-only command means saying so twice, in two different languages.** The
machine-readable half is `AgentCommandConfigKey::TEST_ONLY` in the same `AGENT_COMMANDS`
entry that declares the route (the commands the master answers itself have no agent entry
and are listed in `CommandConstants::MASTER_TEST_ONLY_COMMANDS` instead). The
human-readable half is the `test:` prefix on the wire name. Topology validation fails the
daemon's start if either half is missing, because a flag alone is invisible on review and a
prefix alone is a promise nothing keeps. `TestOnlyCommandRegistry` joins both halves, and it
is the only thing the gate asks.

A project registers its own commands by subclassing `CliManager` and overriding
`registerProjectCommands()`, calling `addCommand()` for each; the project's `cli.php` then
names the project manager instead of `CliManager`:

```php
final class ProjectCliManager extends CliManager
{
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new ReindexCatalogCommand());
    }
}
```

**No demo uses this seam today, and that is the point of HIL-729:** the fifteen commands
chat used to carry are the framework's, `test:orphan-setting:create` / `:delete` among
them, and they live in `framework/backend/Core/CLI/Commands/` beside every other one. What
is left in `demo/chat/backend/CLI/ChatCliManager.php` is the empty override, kept as the
worked example of the shape — the other three demos name `CliManager` in their `cli.php`
directly, which is what a project with no commands of its own does.

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
can inspect a network-partitioned node that cannot reach MySQL either), the cluster client
family `test:cluster:client:attach` / `:detach` / `:send` / `:fanout` and the two drills
beside it, `test:cluster:db:announce` and `test:cluster:agent:place` (same reason: the
master answers them out of memory, and a partitioned node is exactly where they are worth
running),
`test:notification:emit` (every row it causes is written by the agent that answers it, so
the CLI process itself has nothing to read or write), the protected-mode family
`test:protected-mode:inspect` / `:enter` / `:leave` / `:open` / `:pass` (the inspector reads
in-memory state and has to answer on a frozen node, which is exactly where a connect would
hang; the drive commands write nothing from this process, and the mint's row is the agent's)
and the account and session family `admin:grant` / `:revoke` / `:create`,
`impersonate:start` / `:stop` and `account:merge` (same reason as `test:notification:emit`:
every row they cause is written where the account lives). Everything else keeps the full
connect plus `Hilos::init()`.

Because the whole registry is now constructed *before* the connect, a command constructor
must not touch the database or Hilos state. Do that work in `execute()`.

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Reset test database:
php cli.php db:test:reset
```

## Declaring a new command

Three declarations, all made by the command about itself, all read by the spine:

1. **the site** — `execution()`, always; a departure from the daemon rule carries its
   reason ([command-execution.md](command-execution.md));
2. **the database** — implement `DatabaseFreeCommand` when the bootstrap must not connect
   (above);
3. **test-only** — extend `TestOnlyCommand`, plus the `test:` prefix and the
   `AgentCommandConfigKey::TEST_ONLY` flag when it reaches an agent (above).
