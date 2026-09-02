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

`CommandExecutionRoleTest` (framework) reads `CliManager::executions()` — the registry
answers for itself, framework and project commands alike, because collecting the
declarations any other way would mean walking the class tree with Reflection, which this
project forbids (HIL-538). Asking the registry is also what lets one guard reach a registry
the framework does not own, so a project that adds commands of its own is covered by it
without writing a second guard.

It fails when a command departs from the rule without a stated reason. It cannot fail for a
*missing* declaration, because `execution()` is a contract method: a command without one
does not compile.

A temporary exception names the ticket that ends it, and the project guard that watched
chat's five local writes for `HIL-729` was deleted with them: a demo has no CLI of its own
any more, so there is nothing project-side left to watch. An exception that outlives its
plan silently is the one failure mode a written-down reason exists to prevent, so the
naming rule stays for the next temporary one.

`TestOnlyNameContractTest` (framework) stands beside it and reads the same registry through
`CliManager::commandClasses()`, for the other rule a command declares about itself: a
command is test-only when it is named `test:*`, and that name has to agree with what the
class does (HIL-742). It fails in both directions — a `test:*` name whose class does not
refuse on a production-like environment, and a class that refuses under a name without the
prefix. Before it there were two half guards, one over agent-owned commands and one over
the channel family, and neither ever looked at `cli-offline-write`; both commands that lived
in that gap were misnamed, which is how the gap was found.

What neither guard covers is a wire name no registry holds — `backup:restore-request` and
`backup:restore-status` have no terminal half at all. Those are refused where they go on the
wire, by the latch in `AbstractCommandChannelTestCommand::sendCommand()`.

## Today's departures

**`cli-read`** — `help` (the registry is already in this process), `db:wait` (polls a
database that is still coming up), `db:migration:status`, `db:schema:status` and
`llm:ping` (they answer about an installation whose daemon did NOT start, which is when
the answer is wanted), `backup:verify` (hashing gigabytes must not run inside the
monopolistic backup agent's loop).

**`cli-offline-write`** — `db:migration:up` / `:down` / `:retry`, `db:seed:apply`,
`test:db:reset`, `test:user:seed`, `test:verification:expire`, `test:session:expire`,
`test:orphan:create` / `:delete` and `test:orphan-setting:create` / `:delete`. All of them
prepare the schema and the fixtures the daemon later boots on, from the container
entrypoint and from `composer test:db-prepare`.

**`daemon-spawned`** — `backup:run` and `backup:restore-run`, both spawned by the backup
agent (HIL-729 brought them in from chat; before that the framework had none).

## When a command's daemon half is missing

Reaching a daemon that answers nothing is not the same as reaching no daemon, and the CLI
says so in two sentences rather than one:

- `Cannot reach the daemon command channel at <host>:<port>`
- `The daemon did not answer <command> within 5s`

Both come from `CommandChannelClientTrait::channelFailureText()`, which is the only place
either is written; a command prints its own SUCCESS, because that is its to word. Both
answer `ExitCode::ERROR`.

## When the daemon has nobody to hand the command to

A name that reaches the daemon and finds no agent behind it used to read as the timeout
above: the router returned an empty destination list, nothing answered, and the operator
waited out the five-second budget to be told the daemon was silent — which it was not. The
daemon knows, at the moment it happens, that the work will not be done, so it says so
(HIL-730). Three sentences, one per way it knows:

- `No agent in this installation answers <command>` — no entry in
  `Hilos::getCommandAgentRoutes()` for that name. One sentence covers both a command that
  does not exist and a command whose feature this installation never activated: telling
  them apart needs the CLI registry, and the master deliberately does not hold it.
- `No node of this cluster runs the agent that answers <command>` — nothing placed the
  owning agent anywhere.
- `The node running the agent for <command> is unreachable` — the node is known and the
  link to it is not. Worded apart from the one above it because the two are fixed apart.

A fourth silence stays: a handler that neither threw nor replied is still waited out, and
nothing on the path knows it happened. What is no longer silent is a handler that threw —
its `AgentException` becomes `<command> failed: <reason>`, the same relay the payload check
beside it already did. The command port is closed, so the reader is the installation owner.

A request carrying no correlation id is logged and left alone in all three daemon cases:
nothing is held for it, and a reply addressed at nobody is a delivery that fails later and
further away than the drop it replaced.

## Which stream a command writes to

A command's SUCCESS goes to stdout, because that one is the result. Everything else — the
two transport sentences above, and any refusal the daemon answers with — goes to stderr
through `CommandChannelClientTrait::printRefusal()` / `printChannelFailure()`, which relay
the daemon's reason as `Refused: <message>` and answer `ExitCode::ERROR`.

A command does not word a refusal itself, for the reason it does not word a transport
failure: thirty-five of them used to, and twenty-seven called it `Command failed` while
eight called it `Refused`, for one and the same reply. A command that has a FACT beyond the
refusal — `backup:restore` knows the run may still be going, and that there is a `--cold`
road — writes it as a second line in the same stream, under the shared one. It does not get
a parameter on the shared printer: two facts are two lines, not one sentence with a slot.

The wording and the writing are separate methods on purpose. Nothing in this repository can
read stderr back — PHPUnit's output expectations see stdout only — so the sentences are
returned by `channelFailureText()` / `refusalText()` and pinned there, and a test double
stands in for the stream by overriding `printToStandardError()`.
