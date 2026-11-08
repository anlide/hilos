# CLI Commands

Run via: `php Backend/Bootstrap/cli.php <command> [options]`

`--help` / `-h` after the command print its help and run nothing. An option the
command has not declared is refused with exit 2, before the daemon is asked and
before the database is connected.

Every command below carries a **site**: the process its work actually happens in. `daemon`
is the rule and needs no explanation; the other four are departures, each with a written
reason in its `execution()` declaration. See
[command-execution.md](command-execution.md) for the rule, the guard behind it, and why a
`cli-offline-write` command refuses to start while a daemon is running.

## Migration commands

| Command | Site | Description |
|---|---|---|
| `db:framework:baseline` | `cli-source-write` | Write one composite framework baseline pair for the current project's new, empty Schema track; does not apply SQL |
| `db:framework:pull [--projects=<path>]` | `cli-source-write` | Copy missing framework SQL pairs into versioned project tracks without applying SQL or contacting a daemon |
| `db:migration:up` | `cli-offline-write` | Apply all pending migrations |
| `db:migration:down` | `cli-offline-write` | Roll back last applied migration |
| `db:migration:status` | `cli-read` | Show applied / pending migrations |
| `db:migration:retry` | `cli-offline-write` | Retry a failed migration |
| `db:migration:release` | `cli-offline-write` | Release a schema rollout claim a dead holder left |

`db:framework:pull` defaults to the current project's configured Schema track and facade.
`--projects=<path>` reads an explicit JSON object with a `projects` list of `root` and
`facade` strings. Each root is relative to the registry file and names a project directory;
its Composer `autoload.psr-4` paths load the facade without installing dependencies,
reading `.env`, initializing a database, or switching the active facade. The repository's
`composer db:framework:pull` uses `scripts/framework-migrations.php`, the same CLI manager
and command, and `framework-projects.json` to address all six demos.

`db:framework:baseline` takes no options or positional arguments. It uses only the current
project's configured Schema track and facade, and writes its single initial
`001_framework_baseline.sql` / `_down.sql` pair from the framework units selected for that
facade. Run it for a new project, commit the pair, then apply it through the ordinary
`db:migration:up` command. It prints the actual files created and their paths; a verified
baseline is skipped on repeat with advice to use `db:framework:pull` for later updates. An
interrupted pair is completed only from an exact matching half. Other occupied tracks are
refused. Failures go to stderr with a nonzero exit and report any partial result. The
command does not initialize the application or database, contact a daemon, or apply SQL;
use `db:framework:pull` for later framework updates.

The pull command supports origin-aware tracks. It does not infer provenance from an unknown
legacy track. It checks every target before publishing, then rechecks under each track's
source lock. It prints created files or skipped targets on stdout and failures on stderr;
any failure returns nonzero. Publication is per file, not a transaction across projects:
after an I/O failure, keep the reported files and repeat to complete a valid partial pair.
Commit the generated SQL before deploying and applying migrations through the existing
offline path. No presence address is needed to generate these source files.

## Schema / Entity commands

| Command | Site | Description |
|---|---|---|
| `db:schema:status` | `cli-read` | Check DB schema vs expected structure |
| `db:change-log:generate` | `cli-offline-write` | Generate changed journal trigger files from the applied schema; refuses a live daemon |

## Seed commands

| Command | Site | Description |
|---|---|---|
| `db:seed:apply <n>` | `cli-offline-write` | Apply seed number N |

## DB utility commands

| Command | Site | Description |
|---|---|---|
| `test:db:reset` | `cli-offline-write` | DROP all tables → migrate → seed (test env only) — runs before the database exists, but needs the server up |
| `test:orm:forget daemon` / `test:orm:forget worker <workerId>` | `daemon` | Forget loaded ORM state in one live process; test-only use and owner restriction: [Forgetting loaded ORM state](../testing.md#forgetting-loaded-orm-state) |
| `db:wait` | `cli-read` | Wait until MySQL is accepting connections — runs while the server is still down |

## System commands

| Command | Site | Description |
|---|---|---|
| `daemon:status` | `daemon` | Show daemon status (uptime, node cores, daemon and machine load, memory against the ceiling, workers) — over the command channel; exits 0 when no daemon answers, non-zero when one answers late or refuses |
| `daemon:monitor` | `daemon` | Live monitoring of daemon (continuous blocking watch — use `daemon:status` for a one-shot check, not for an AI agent) |
| `daemon:ping` | `daemon` | Probe the command channel itself |
| `agent:restart <agentId> [--node=<nodeId>]` | `daemon` | Stop one running agent and start the same instance again; the daemon reports the result |
| `help` | `cli-read` | List available commands — runs while the server is still down |

## Account and session commands

Every one of them is `daemon`: the CLI sends a request and prints the reply, because what
they change lives where the accounts and the sessions do. The first six are the framework's since
HIL-729, and all six are answered by the sessions library in every project, because each of
them ends in a session being told who it is now — the merge by consequence, since a folded-away
account's open tabs have to be signed out before the merge is true. Whether the takeover is
allowed is the framework's own judgement over the people table and the impersonation settings
(HIL-1197, HIL-1170); what a project is asked is the merge's seam — whether these two accounts
may be merged at all plus what this project keeps for a person. A project that wires no merge
refuses it rather than falling silent. The seventh, `second-factor:unlock` (HIL-1285), is answered
by the users library instead: it coordinates the second factor, and the person's agent lifts the
lock on the person's row on its frame (HIL-1406) ([../architecture/second-factor.md](../architecture/second-factor.md)).

| Command | Site | Description |
|---|---|---|
| `admin:grant <userId>` | `daemon` | Grant a user the Hilos admin flag |
| `admin:revoke <userId>` | `daemon` | Take the Hilos admin flag away from a user |
| `admin:create <sessionToken>` | `daemon` | Make a browser session an administrator, minting its user when it has none |
| `impersonate:start <sessionToken> <userId>` | `daemon` | Make an admin session act as another user |
| `impersonate:stop <sessionToken>` | `daemon` | Return an impersonating session to the administrator behind it |
| `account:merge <survivorId> <loserId> [--password=…]` | `daemon` | Fold one populated account into another |
| `second-factor:unlock <userId>` | `daemon` | Lift a person's second-factor app-code lock |

`admin:create` remains a daemon command answered by the sessions holder. Today
that holder's `mintAdminUser()` creates a person when the session has none.
The new person, including the first administrator, will be created through the
users library; the holder still authorizes the operation and binds the session
(not in the code yet — HIL-1898). The command's usage, reply and behavior for a
session that already names a person stay the same.

`account:merge` reports what moved as a map rather than a count: the framework moves the
sign-in identities itself and asks the project to move the rows only it knows about, so the
reply carries the project's own tally under the project's own names — in chat, `messages`.
It also has a second way in, as `impersonate:start` and the grant pair do: an admin table
submits the same merge as the `hilos_user_merge` action on the single-user page, the page
forwards it to the library on `hilos_account_merge`, and the outcome comes back to that
page on `hilos_account_merge_done` so the tracked action is completed where it began. That
second way in asks what the command does not: an active administrator and their fresh
confirmation of the merge (the step-up operation `merge_accounts`, see
[step-up.md](../architecture/step-up.md)) — the operator at the console has nothing to
confirm with.

Under the detailed [merge canon](../architecture/account-operations.md#merge-cutover-and-observation),
`account:merge` will distinguish preparing, wait, refusal and end, with the chosen
fates, through the daemon channel. Waiting must not block the event loop; a channel
disconnect is neither cancellation nor success. Success means durable end. The exact
response is that leaf's contract, with no new command declared here
(not in the code yet — HIL-2001). This becomes the live command path only at the
single merge switch (not in the code yet — HIL-1999).

`impersonate:start` is judged by the same `assertImpersonationAllowed()` as the person's
card, the impersonation settings included (HIL-1170,
[people-table.md](../architecture/people-table.md)): it is refused while impersonation is
switched off, and for a blocked person, a frozen person or another administrator whose
switch is off, in the card's words on the error reply. It asks no step-up — the card's
`impersonate` operation is the browser's alone.

## Settings commands

| Command | Site | Description |
|---|---|---|
| `settings:set <key> <value>` | `daemon` | Set a settings value |
| `settings:reset <key>` | `daemon` | Reset a settings value to its catalog default |

Equal to the general settings screen: catalog, type, key rule; without step confirmation.

## Protected mode commands

| Command | Driver | What it does |
|---|---|---|
| `protected-mode:inspect` | daemon | reads current freeze state, phase, operation, and active passes |
| `protected-mode:pass` | daemon | mints an operator pass during verification window of restore |
| `protected-mode:open` | daemon | operator window entry |
| `protected-mode:close` | daemon | operator window exit |
| `maintenance:enable` | daemon | index agent manual maintenance entry into verification window |
| `maintenance:disable` | daemon | index agent manual maintenance exit to inactive |
| `maintenance:pass` | daemon | index agent manual maintenance pass minting |

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

**Declaring a test-only command is naming it `test:` — that is the whole declaration**
(HIL-742). `TestOnlyCommandRegistry::isTestOnly()` reads the prefix, and it is still the only
thing the gate asks; there is simply nothing behind it to join any more.

It used to be three declarations: the prefix, an `AgentCommandConfigKey::TEST_ONLY` flag in
the agent's `AGENT_COMMANDS` entry, and a `CommandConstants::MASTER_TEST_ONLY_COMMANDS` list
for the commands the master answers itself — sewn together by two guards that between them
never looked at the `cli-offline-write` role. Both commands living in that gap were named
without the prefix and nothing said so. The prefix is the one kept because a flag is
invisible to whoever reads a name in a log line, a compose file, or a review diff, which is
exactly where both holes were eventually found.

Dropping the flag also made the answer installation-independent: under it, a name was
test-only only where an agent declaring it was registered, so the same name could be gated in
one project and open in another.

What still has to be checked is the other direction — a class that refuses on production must
be named `test:`, and a name saying `test:` must have a class that refuses. That is one unit
guard over the command registry, `framework/tests/Unit/Core/CLI/TestOnlyNameContractTest.php`,
reading `CliManager::commandClasses()`. A wire name the registry never sees — the restore pair
has no terminal half — is held where it goes on the wire, by the latch in
`AbstractCommandChannelTestCommand::sendCommand()`.

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

Use this seam for a test-only command over a feature owned by one project. Commands over
framework-owned state stay in `framework/backend/Core/CLI/Commands/`, even when one
project is their only current consumer.

### Fixture account and session commands

| Command | Site | Description |
|---|---|---|
| `test:user:create` | `daemon` | Create a verified fixture user and identity through the users library |
| `test:session:bind` | `daemon` | Bind an idle anonymous fixture session to an existing user through the sessions library |

```text
test:user:create <email> <passwordHash|none> [--phone=<E.164>] [--theme=light|dark|system]
test:session:bind <sessionToken> <userId>
```

`test:user:create` accepts a recognized password hash, or `none` for a magic-link identity.
The optional phone creates a verified SMS identity; the account theme defaults to `null`.
The user, identity and current legal acceptances are written in one transaction, and the
users-library hook runs after commit. It creates a new fixture rather than upserting an
existing identity. Success prints JSON with `userId`, `email` and `name`; it does not print
the password hash.

`test:session:bind` accepts only an existing idle anonymous session and an active,
unblocked user. It refuses a session with an owner, impersonation state, pending
registration or second-factor state, a pending acknowledgement, or a blocked-user marker.
It binds through the ordinary session authentication path and records the sign-in without
rotating the session token or sending a registration acknowledgement. Success prints JSON
with `sessionId` and `userId`; it does not print the session token. It refuses an already
authenticated session rather than taking it over.

### Status moment and settings write commands

Four commands bring a moment to now, or make a write at a moment the test chooses, so a
spec waits for no production clock (HIL-1856). Each is the per-feature shape of
[Time-based features](#time-based-features-no-universal-clock): the CLI sends the request
and prints the reply, and the agent that owns the state does the work.

| Command | Site | Description |
|---|---|---|
| `test:status:heartbeat:stale` | `daemon` | Publish the Status heartbeat aged past the reader's stale limit, and hold the owner's publication |
| `test:status:heartbeat:now` | `daemon` | Release that hold and publish a heartbeat now |
| `test:status:work:start` | `daemon` | Move an announced maintenance work's start to now, by its public id |
| `test:settings:write` | `daemon` | Write example settings keys through the settings library, one write per pair, in order |

```text
test:status:heartbeat:stale
test:status:heartbeat:now
test:status:work:start <publicId>
test:settings:write <key>=<value> [<key>=<value> ...]
```

The first three are answered by the Status owner (`HILOS_STATUS`, one per cluster), which
alone publishes the public files and writes the announcements. What each does there, why the
owner holds its publication after the stale command, and what the moved start leaves untouched
is in [status-page.md](../architecture/status-page.md#test-only-moments). Each prints one
line with the moment it caused, in epoch milliseconds, and exits 0:

```text
Aged the Status heartbeat: alive at epoch ms <aliveAt>
Published a Status heartbeat now: alive at epoch ms <aliveAt>
Started maintenance announcement <publicId>: starts at epoch ms <startsAt>, version <version>
```

The two heartbeat commands take no argument. `test:status:heartbeat:stale` refuses with
`No public Status pair is published yet.` (exit 1) when the owner has no coherent pair to age.
`test:status:heartbeat:now` refuses with the same sentence whenever its publication step
publishes nothing: no coherent pair is published yet, but also a retry barrier left by a failed
file write, or a database read that failed in that portion. `test:status:work:start`
refuses a blank or missing public id itself, with usage and exit 2, and the owner refuses in
the administrator's words: the announcement no longer exists; it was canceled, started or
expired; the new window would overlap another announcement; or Status changes are
unavailable during maintenance.

**A spec that ages the heartbeat owns the release.** The owner keeps the aged moment only by
publishing nothing more, so the spec that called `test:status:heartbeat:stale` calls
`test:status:heartbeat:now` in its `finally`
([Preconditions — test commands with owned cleanup](../frontend/testing-strategy.md#preconditions--test-commands-with-owned-cleanup)).
The e2e helpers are `staleStatusHeartbeat`, `beatStatusHeartbeat` and `startStatusWork` in
`framework/frontend/scripts/statusMoments.mjs`, each taking an optional `{host}`.

`test:settings:write` is answered by the settings library, the one writer of settings. Each
argument is split at its first `=`, so a value may itself carry `=`, and the pairs travel
in the order given. The library judges the batch whole before the first write:
1 to 32 pairs of two strings, and only the catalog's example keys `example_string`,
`example_integer` and `example_boolean`. Then each pair is written by the path the settings
screen's add takes, so a pair is one transaction and one table change of its own, and the
first refused write ends the batch: the refusal names the pair's number and why, and the pairs
before it stay written. Success prints one line per pair, so a spec can line what it sees up
with when each write happened:

```text
Wrote <key> = <value>, accepted at epoch ms <acceptedAt>
```

A foreign key is refused with `Only the example settings keys can be written by this test
command.` and an empty, longer or malformed batch with `A test settings write carries 1 to 32
key and value pairs.` — in both cases before anything is written. The policy keys — the
second factor, its trust term, impersonation — are not let in: they answer through the
library's own settle path, with a deferred answer and installation-wide frames, which a batch
does not repeat. The e2e helper is `writeSettings` in
`framework/frontend/scripts/settingsWrites.mjs`; `writeDeliverySettings` in
`framework/frontend/e2e/deliverySettings.ts` wraps it for the delivery window specs and times
each write on the runner's clock.

### Daemon node report and cron firing commands

Two commands bring the Daemon section's own waits to now, so a spec sits out neither a node
agent's reporting interval nor a cron schedule (HIL-1857). Each is the per-feature shape of
[Time-based features](#time-based-features-no-universal-clock): the CLI sends the request and
prints the reply, and the daemon does the work — the master and its node agent for the report,
the master or the worker running the agent for the rule.

| Command | Site | Description |
|---|---|---|
| `test:daemon:node:report` | `daemon` | Have this node's Daemon agent publish its picture now, with fresh master frames |
| `test:cron:fire` | `daemon` | Fire one cron rule once now by its ordinary path; leave its expression unchanged |
| `test:cron:pause` | `daemon` | Pause natural checks on one current rule; an explicit test fire still runs |
| `test:cron:cancel-pause` | `daemon` | Cancel one exact schedule pause without running the rule |

```text
test:daemon:node:report
test:cron:fire <owner>/<rule>
test:cron:pause <owner>/<rule>
test:cron:cancel-pause <id>
```

`test:daemon:node:report` takes no argument. The master raises every frame it owes its node
agent — the set the node agent is sent when it starts: processes, cron, standing, HTTP and
measurements — waits until its process roster has gone out, and only then asks the node agent
(`HILOS_DAEMON_NODE`) to publish its picture at once, past its change throttle; the next change
waits the throttle counted from this report. So the answer says that the report has left and
carries everything the master knew when it was asked; the collector's fan-out to open pages
follows it. Where that report sits in the node's circulation is in
[daemon-section.md](../architecture/daemon-section.md#the-circulation).

`test:cron:fire` takes one argument, cut at its first `/`, so a rule name may carry `/` itself.
The owner is `daemon` for a rule of the daemon itself, sent as no agent on the wire, or the id
of the agent that holds the rule. A daemon rule is fired by the leader's master at once. An
agent's rule is armed by the worker running the agent and fires at the agent's next check; the
answer leaves after that tick's cron report, so the node agent is sent the new run before the
console has its answer. Only a rule the agent returns from `cronRules()` — the set the Cron page
shows — can be armed. Either way the rule runs by the path its schedule takes: its expression
stays unchanged and the schedule does not fire it again in the same minute
([daemon-section.md](../architecture/daemon-section.md#the-schedule)). Each report calculates
`nextRunAt` from that expression and the report time, so a later report may carry a later
`nextRunAt` without another run.

The report and firing commands ask the node whose command socket they reach: the report is
that node's; a daemon rule fires there only if that node is the leader, and an agent's rule only
if the agent runs there.
Each prints one line with the moment, in epoch milliseconds, and exits 0:

```text
Reported node <nodeId>: at epoch ms <reportedAt>
Fired <owner>/<rule>: ran at epoch ms <firedAt>
```

An argument given to the report, or a firing argument with no owner or no rule, is refused by
the command itself, with usage and exit 2. The daemon refuses the report with
`No Daemon node report during protected mode.` while the node's freeze holds,
`The Daemon node agent is not running on this node.`,
`The node could not build its process roster: <problem>` and
`The Daemon node agent did not report in time.` when the roster has not gone out within the
agent's window (`CommandChannelWindows::AGENT_WAIT_SECONDS`). A daemon rule is refused where its
schedule would not run it either: `The daemon has no cron rule <rule>.`,
`Daemon cron rules run on the leader; this node is not the leader.` and
`The daemon's workers are not ready yet.` An agent's rule is refused with
`No agent <agentId> runs on this node.` by the master, `No agent <agentId> runs on this worker.`
and `Agent <agentId> holds no cron rule <rule>.` by the worker, and with
`Agent <agentId> did not check cron rule <rule> in time.`,
`The worker running agent <agentId> went down before the rule fired.` or
`Agent <agentId> stopped before the rule fired.` when it was armed but never fired. Every
refusal exits 1.

The e2e helpers are `reportDaemonNode`, `fireCronRule`, `pauseCronRule` and `cancelCronPause` in
`framework/frontend/scripts/daemonMoments.mjs`, each taking an optional `{host}`.
`fireCronRule` and `pauseCronRule` take the `<owner>/<rule>` key; `pauseCronRule` returns the id
that `cancelCronPause` removes. Which helper a spec calls, and when it repeats the report, is in
[The Daemon section](../frontend/testing-strategy.md#the-daemon-section--names-and-checks) of the
testing strategy.

For a cluster clock-offset spec, set the measured node's probe-answer shift with
`test:cluster:clock:shift --ms=800` (or a negative value); omit `--ms` to reset it to zero.
The command changes only the master's in-memory `peer_clock_reply` wall time, and the shift
lasts until reset or master restart. On the current leader, fire
`test:cron:fire daemon/hilos_cluster_clock_check`, then use
`test:daemon:node:report` on that leader to carry the fresh `standing.clock` through the
existing picture chain. This is a controlled probe answer, not a clock override: browser and
system clocks do not change. The command refuses on production, outside a cluster, for a
non-integer `--ms`, or when its absolute value exceeds 86,400,000 milliseconds.

#### Pausing a cron schedule while its test checks the rule

The pause and cancellation commands are test-only and refuse on a production-like node.
The `test:cron:pause` owner and address checks match `test:cron:fire`:
`daemon/<rule>` addresses a daemon rule, and `<agentId>/<rule>` addresses a rule held by that
agent. The address is split at its first slash, so the rule name may contain further slashes.
A daemon rule is changed by the leader's master after its workers are ready; an agent rule is
changed by the current worker running that agent.

`test:cron:pause <owner>/<rule>` applies a pause to that current rule instance and prints:

```text
Paused <owner>/<rule>: pause <id>
```

`test:cron:cancel-pause <id>` removes only the pause identified by that id and prints:

```text
Canceled pause <id>
```

An unknown or already canceled id is refused with the reason `No pause <id>`. Each successful
pause or cancellation is logged by the daemon.

While any pause id remains on a rule, natural schedule checks skip it without marking a run
or remembering a missed match. An explicitly armed `test:cron:fire` run is checked before the
pause and still runs by the ordinary path. A spec whose checks could be changed by the rule's
own natural firing pauses it before arming that run, then cancels its id in awaited `finally`
cleanup. Cancellation removes only its own id and does not run the rule; after the last id is
removed, later ordinary checks follow the expression without a catch-up run. The pause belongs
to the current rule instance and disappears when that instance is replaced. The pause itself
changes neither the expression nor run history. Reports keep the ordinary expression and
`lastRunAt`; `nextRunAt` is recalculated from the expression at each report and may advance as
time passes without a run.

### Time-based features: no universal clock

There is deliberately **no injectable clock, `now()` override, or global time-mock** in the
framework, and we do not add one. A single knob that fast-forwards time — "purge the account
in 5 seconds instead of 21 days" — is exactly what must never be reachable on prod, and a test
cannot test its own harness, so the only guard is discipline: keep the capability small and
per-feature so it is obvious in review.

So each time-based feature gets its **own** narrow test-only command that brings its moment to
now. Time-based is any wait a feature owns, not only a stored timestamp: a grace period or an
expiry, a scheduled round or a cron rule, a delivery window, a polling or reporting interval, a
heartbeat and the threshold that calls it stale, a cooldown or a rate limit, a rotation by age,
a delay before an answer. The command ages the one moment the feature reads — or releases what
it holds — so the logic fires now. For example `test:account:force-purge` initiates and prints;
the live sessions holder moves the request's moment to now and runs the existing erasure.
In the split erasure, the same command triggers the live owners, which write and move the
moment, and reports the actual start, wait or end without a direct CLI database write
(not in the code yet — HIL-1953). Its live entry switches together with due erasure
(not in the code yet — HIL-1952); exact new response forms belong to HIL-1953, under the
[erasure canon](../architecture/account-operations.md#erasure-cutover-and-observation).
Write these per-feature and carefully; never generalise them into a shared time-travel
utility. The Status heartbeat and
its stale limit, and an announced maintenance start, have theirs, with a batch of settings
writes beside them: see
[Status moment and settings write commands](#status-moment-and-settings-write-commands). A
Daemon node report and a cron rule's run have theirs in
[Daemon node report and cron firing commands](#daemon-node-report-and-cron-firing-commands).
Running a rule once is not a time machine: `test:cron:fire` serves every cron rule, yet it runs
the one rule now by its ordinary path without changing its expression. A reported `nextRunAt`
is calculated from that expression at the report time and may advance as time passes without a
run. When the test's own assertions can be changed by that rule's natural firing, use its
test-only schedule pause and cancel it in awaited cleanup
([Pausing a cron schedule while its test checks the rule](#pausing-a-cron-schedule-while-its-test-checks-the-rule)).

**The command also stamps the moved moment where its reader reads it.** A browser counts down
from the moment the server sent it, and a pause shortened in advance kills the very state the
test has to see. So `test:verification:end-pause` ages the address's resend gate *and* stamps
that browser's line at now, changing neither the production cooldown nor the cap (HIL-1186,
[../architecture/verification-codes.md](../architecture/verification-codes.md)). Try this
shape first whenever a test meets a timer.

Use the feature command by default. The canonical
[Time rules](../frontend/testing-strategy.md#time--a-command-brings-the-moment-to-now)
also allow a setup that catches the same regression without waiting for the clock and a
narrow temporary e2e real-wait exception; follow their conditions. Real-clock waits outside
that exception, including every such PHP-test wait, and any shortening of a production
timer through test env or a compose overlay need the owner's approval in the leaf's contract
([../../../agents.md](../../../agents.md#contract-approval-gate-hard-stop)). A smaller
stand timer changes the clock for every spec on that stand.

A production value is not proved by waiting it out: the default or the constant is a unit
test's assertion.

Where the period itself is the behavior under test — the cadence of a delivery window — the
test observes a few real periods at the value the product runs with, and a command drives the
source instead of a slow UI; it does not stretch or shrink the period to fit. Those real
waits follow the same exception and approval boundaries in the canonical Time rules.

**A test-only command is not a production lever.** It extends `TestOnlyCommand` and is refused
on production and on the socket (above), so a plan does not rule one out as "a new CLI" or "a
new production action". The feature command needs no approval under the time gate.

Specs written before this rule still wait one clock: the OAuth agent's request deadline, waited
out through a stand gateway delay or hold. It is debt, not a pattern to copy.

## Database-free commands

The CLI bootstrap connects the database before running a command. A command that must work
when there is no database to connect to declares that itself, by implementing the empty
marker `Hilos\Core\CLI\Commands\DatabaseFreeCommand` — the same "implementing it is the
whole declaration" shape as `TestOnlyCommand`, and a project command declares it the same
way. `CliApplication` asks `CliManager::requiresDatabase()` and skips the connect; an
unregistered command name skips it too, so a typo answers `Unknown command` instead of a
connection failure.

Marked today: `db:framework:baseline` and `db:framework:pull` (write versioned source files
without reading a database),
`db:wait` (its poll *is* the connect, and it has to run before the server
answers), `agent:restart` (the master coordinates the stop and start over its command
socket, and the CLI process has no database work), `test:orm:forget` (only the addressed
live process forgets ORM state; see [the test-only canon](../testing.md#forgetting-loaded-orm-state)),
`test:db:reset` (opens its own
server-level connection, because it drops and recreates the database `DB_DATABASE`
names), `help` (prints the registry it was handed),
`test:cluster:inspect` (talks only to the local command socket, so the multi-node harness
can inspect a network-partitioned node that cannot reach MySQL either), the cluster client
family `test:cluster:client:attach` / `:detach` / `:send` / `:fanout` and the two drills
beside it, `test:cluster:db:announce` and `test:cluster:agent:place` (same reason: the
master answers them out of memory, and a partitioned node is exactly where they are worth
running), `test:notification:emit`, `test:user:create` and `test:session:bind` (the CLI sends
each to its owning library and prints the reply; database work stays in the daemon),
`test:status:heartbeat:stale`, `test:status:heartbeat:now`, `test:status:work:start` and
`test:settings:write` (the same shape: the Status owner or the settings library answers and
writes, and the CLI process has nothing to read or write — HIL-1856),
`test:account:force-purge`, `test:verification:sweep`,
`test:legal:hold`, `test:i18n:language:on <code>`, `test:i18n:language:off <code>`,
`test:i18n:language:delete <code>`,
`test:i18n:locale:on <code>`, `test:i18n:country:off <code>` and `test:i18n:country:on <code>` (every row they cause is written by
the agent that answers them, so the CLI process itself has nothing to read or write),
`test:connection:liveness` (the master answers it out of memory, from the browser sockets
it holds, and the flag it may cause is written by the owner of the connections collection,
so the CLI process has nothing to read or write either — HIL-1860),
`test:daemon:node:report` and `test:cron:fire` (the master, its node agent and the worker
running an agent answer them, and whatever a fired rule writes it writes in the daemon, so the
CLI process has nothing to read or write either — HIL-1857),
`test:table:lag` (the master answers it out of memory and writes its own node's lag row,
so the CLI process has nothing to read or write either — HIL-1020), `test:table:refuse`
(the same shape: the master answers it out of memory and writes its own node's refusal row
— HIL-1131), `test:admin-view-mode` (the master answers it out of memory and writes its
own node's admin view mode row — HIL-1249; refused on a node of a cluster — HIL-1274),
`protected-mode:inspect` (reads in-memory state and has to answer on a frozen node, which
is exactly where a connect would hang), the operator family `protected-mode:pass` /
`:open` / `:close` (the backup agent answers them and drives the freeze, so the CLI
process writes nothing), the manual maintenance family `maintenance:enable` / `:disable` /
`:pass` (the index agent answers them and drives the freeze, so the CLI process writes
nothing), the protected-mode drive family `test:protected-mode:enter` / `:leave` / `:open`
/ `:pass` / `:close` (the drive commands write nothing from this process, and the rows the
mint and the close move are the agent's) and the account and session family `admin:grant`
/ `:revoke` / `:create`, `impersonate:start` / `:stop` and `account:merge` (same reason as
`test:notification:emit`: every row they cause is written where the account lives),
`settings:set` / `settings:reset` (every row they cause is written by the library), and
the cluster TLS family `cluster:tls:ca` / `:issue` / `:trust` (they need no database at
all: they issue the certificates of a cluster that may not exist yet, print PEM and
nothing else, and are run exactly where there is no installation to connect to —
HIL-1034). Everything else keeps the full connect plus `Hilos::init()`.

Because the whole registry is now constructed *before* the connect, a command constructor
must not touch the database or Hilos state. Do that work in `execute()`.

## Typical development flow

```bash
# After changing DB schema (add column, new table):
php cli.php db:migration:up

# Reset test database:
php cli.php test:db:reset
```

## Declaring a new command

Four declarations, all made by the command about itself, all read by the spine:

1. **the site** — `execution()`, always; a departure from the daemon rule carries its
   reason ([command-execution.md](command-execution.md));
2. **the database** — implement `DatabaseFreeCommand` when the bootstrap must not connect
   (above);
3. **test-only** — extend `TestOnlyCommand` and name the command `test:*` (above); the
   prefix is the declaration, and the guard fails if the two disagree either way;
4. **the options** — `OPTIONS` names every option that `execute()` or its helpers and
   parents read. Read an option by name (a constant or a literal), not through a
   variable. Do not hand `$options` to another class. An option the command has not
   declared never reaches the command.

Checked automatically: `CLI-OPTIONS-DECLARED`, see
[automated-checks.md](../code-style/automated-checks.md).
