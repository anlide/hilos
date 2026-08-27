# Where a CLI command's work happens

One rule, and every departure from it is written down in the code.

> **The daemon does the work. The CLI process initiates it and prints the answer.**

Read this before adding a CLI command, before moving work between a command and an agent,
and before writing anything from a CLI process that a running daemon also writes.

## Why the rule exists

A Hilos installation has one owner per piece of state: an agent owns its catalog, the
master owns its runtime, the daemon owns the database it holds connections to. A CLI
process that does the work itself becomes a second owner for as long as it runs. Nothing
detects that, and the damage does not surface as an error — it surfaces later as data
nobody can account for.

Doing the work in the daemon also means the work happens where the state already is: the
backup agent rescans its own storage after a change, the index agent announces a
re-decision to the pages that show it, the master closes the connection it holds. A CLI
process that edited the same files or rows would leave every one of those follow-ups
undone.

## The four sites

A command declares exactly one, through `CommandInterface::execution()`:

| Site | What it means | Owes a reason |
|---|---|---|
| `daemon` | The rule. The CLI sends a request and prints the reply. | no |
| `daemon-spawned` | A child process the daemon starts itself; no operator at its entrance. | yes |
| `cli-read` | Work in the CLI process that changes nothing. | yes |
| `cli-offline-write` | Work in the CLI process that WRITES state; admissible only while no daemon runs. | yes |

The reason is a sentence in the declaration, not a comment near it:

```php
public function execution(): CommandExecution
{
    return CommandExecution::cliOfflineWrite(
        'the schema the daemon boots on is applied before it starts, from the container'
        . ' entrypoint and composer test:db-prepare',
    );
}
```

`daemon` owes nothing because it is the rule rather than an exception to it;
`CommandExecution::daemon()` takes no argument, and its `reason` is null. The three
departures cannot be built without one — the argument is required by the factory, and a
reason that is blank fails `CommandExecutionRoleTest`.

## The transport is not the site

`daemon:status` and `daemon:monitor` reach the daemon over the HTTP status endpoint rather
than the command channel, and they are still `daemon`: the work happens in the daemon
either way. The site answers *which process does the work*, never *which socket carries the
request*.

## Writing beside a live daemon is refused

`cli-offline-write` is the one site the CLI spine acts on. Before such a command starts,
`CliApplication` asks `DaemonPresenceProbe` whether anything answers on
`HILOS_DAEMON_HOST:COMMAND_PORT` — one short TCP connect, no request and no reply, because
an accepted connect already answers the only question.

| Presence | What happens |
|---|---|
| nothing listening | the command runs |
| a daemon answers | refused, `ExitCode::ERROR` |
| the channel address cannot be formed | refused, `ExitCode::CONFIG_ERROR` |

**The third row is fail-closed on purpose.** Not knowing is not permission. The two
mistakes cost differently: a wrong refusal costs an operator one message, and a wrong
admission costs a database with two writers. The consequence is real and worth stating —
an environment that names no command channel cannot run migrations at all, so a container
that runs them has to be told the address even when no daemon exists there yet.

The other three sites are never probed. `daemon-spawned` least of all: a restore child
writes to the database *while the daemon is up*, legitimately, under protected mode, and
there is no operator in front of it to read a refusal.

## The guard

`CommandExecutionRoleTest` (framework) and each project's own equivalent read
`CliManager::executions()` — the registry answers for itself, framework and project
commands alike, because collecting the declarations any other way would mean walking the
class tree with Reflection, which this project forbids (HIL-538).

They fail when a command departs from the rule without a stated reason. They cannot fail
for a *missing* declaration, because `execution()` is a contract method: a command without
one does not compile.

A temporary exception names the ticket that ends it. Chat's local writes say `HIL-729` in
their reason, and `ChatCommandExecutionRoleTest` requires it — an exception that outlives
its plan silently is the one failure mode a written-down reason exists to prevent.

## Today's departures

**`cli-read`** — `help` (the registry is already in this process), `db:wait` (polls a
database that is still coming up), `db:migration:status`, `db:schema:status` and
`llm:ping` (they answer about an installation whose daemon did NOT start, which is when
the answer is wanted), `backup:verify` (hashing gigabytes must not run inside the
monopolistic backup agent's loop).

**`cli-offline-write`** — `db:migration:up` / `:down` / `:retry`, `db:seed:apply`,
`db:test:reset`, `test:user:seed`, `verification:test:expire`. All of them prepare the
schema and the fixtures the daemon later boots on, from the container entrypoint and from
`composer test:db-prepare`.

**`daemon-spawned`** — none in the framework. Chat has `backup:run` and
`backup:restore:run`, both spawned by the backup agent.

**Temporary** — chat's five local writes (`test:orphan:create` / `:delete`,
`test:orphan-setting:create` / `:delete`, `test:session:expire`). HIL-729 moves chat's CLI
into the framework and ends them.

## When a command's daemon half is missing

Reaching a daemon that answers nothing is not the same as reaching no daemon, and the CLI
says so in two sentences rather than one:

- `Cannot reach the daemon command channel at <host>:<port>`
- `The daemon did not answer <command> within 5s`

Both come from `CommandChannelClientTrait::printChannelFailure()`, which is the only place
either is written; a command prints its own SUCCESS, because that is its to word. Both
answer `ExitCode::ERROR`.

A command whose name reaches the daemon but which no agent serves is a third case, and it
is not built yet — the server returns an empty destination list and the request is simply
never answered, so it reads as the timeout above. HIL-730 makes it a refusal.
