# Architecture: Admin Feature Scaffold

Read this before generating the activation of a framework-owned admin feature —
settings, hilos-users, and future ones like roles — in a project. This is a
generation recipe for an AI agent: given a framework admin feature, produce the
project-side code that activates it. The normative boundary and the two modes
live in [admin-features.md](admin-features.md); this file is the per-feature
generation order.

**Scope: framework-owned features only.** A project's own divergent admin table
(`admin_users`, Mode 2) is not scaffolded here — that is authoring a new feature,
not activating a framework one. See [admin-features.md](admin-features.md) Mode 2
for that.

## Core Rule

Generate activation from the framework contract, not by copying another project.
The framework owns the engine — the browser-table merge, the page subscribe, the
action lifecycle. The agent generates only the project-side binding each
feature's base class declares as its extension points. Never generate a copy of
the engine.

**Access is closed by default.** Every page on the framework admin surface
inherits the `ADMIN` access level from `AbstractHilosPage` (HIL-441), and
openness is an explicit declaration on the page class. The activation needs no
per-page guard for that, but the project must wire identity —
`resolveConnectionIdentity()` and `isAdmin()` on its `BrowserContext` — or the
mounted feature denies everyone. See
[page-access-control.md](page-access-control.md).

## Generate against the contract

Each framework feature ships base classes plus the extension points a project
fills. Read the base class, then generate what it leaves abstract — do not look
for a project to clone. Two contract shapes recur:

- **Framework-owned data source (configure-only).** The framework owns the table
  and the DB source; the project supplies only content. Settings is this shape:
  `Hilos\Tables\Settings\HilosSettingsTable` is `final`, the `setting` source is
  on `Hilos\Database\Context\HilosDbContext`, and `Hilos\Pages\AbstractHilosSettingsPage`
  owns the whole add/update/delete lifecycle. The extension points are a catalog
  provider, one `SUBSCRIPTION_AGENT_TYPE`, a project `BrowserContext` (an empty
  subclass — the base context delivers the table snapshot through the
  self-snapshot path), and a thin frontend context wrapper. "Configure-only"
  means no engine code, not zero project files.
- **Project-owned data behind a framework contract (bound).** The framework owns
  the merge engine and the row/presence contract; the project owns the data and
  binds it. Hilos-users is this shape: `Hilos\Tables\Users\AbstractHilosUsersTable`
  is abstract with five hooks, `AbstractHilosUserTableRow` fixes the base
  `id`/`admin`/`block` fields, and presence flows through the
  `Hilos\Runtime\View\Collection\HilosPresenceSource` interface returning a
  `Hilos\Runtime\View\DTO\HilosUserPresenceSummary`. Bound is not "inventing a
  table" — it is implementing a framework contract.

## Generation recipe

### settings — configure-only

The framework owns everything mechanical. Generate, in any order:

1. Declare the feature: `HilosFeature::SETTINGS` in the project facade's
   `FEATURES` ([../app-topology.md](../app-topology.md#feature-declaration)).
   This is the on-switch, and it is what turns every step below into a checked
   obligation rather than a checklist item somebody may skip — a declaration
   without the page, the table binding or the catalog refuses to start, and the
   `hilos_setting` migration is checked by the project's topology test.
2. A catalog provider class holding this project's setting keys and defaults
   (project content). Bind it through the `SETTINGS_CATALOG` constant on the
   project `Hilos` facade; the framework reads it back via `Hilos::$setting->catalog()`.
3. A subscription-owner page — `final class … extends Hilos\Pages\AbstractHilosSettingsPage`
   carrying only one `SUBSCRIPTION_AGENT_TYPE` — and register it in the project
   `PAGES`.
4. Register the framework table in topology: `TableContext::settings =>
   Hilos\Tables\Settings\HilosSettingsTable::class`, returned from the project
   `createTable()`, and bind it to the page in `PAGE_TABLES`. Register it; never
   subclass or re-implement it.
5. A `BrowserContext` returned from the project `createBrowser()` — an empty
   subclass is enough. The framework default is `null`, and without a browser
   context the settings page answers a subscription with nothing; the base
   context delivers the table's snapshot through the self-snapshot path
   ([admin-features.md](admin-features.md), *Browser delivery*).
6. Mount the SDK view: map `HilosPages.SETTINGS` to the framework view from
   `@hilos/{vue,react,angular}` `admin/settings/HilosSettingsPage`, through a thin
   project wrapper that binds the `HilosSettingsContext` (`{ scopes, actions }`
   from the project session + connection). The view, the row model, and the
   add/update/delete round-trips are framework-owned — the wrapper is context
   binding, not page logic.

No entity, no RT collection, no table subclass — that is the configure-only
floor. It still includes one empty `BrowserContext` and one thin frontend context
wrapper; those bind the project, they do not re-implement the engine.

### hilos-users — bound

The framework owns the table merge, the page subscribe + actions, the presence
contract, and the base row. The project generates the data binding the contract
requires, in dependency order (the table merges sources that must exist first):

1. **Declaration.** `HilosFeature::HILOS_USERS` in the project facade's
   `FEATURES` ([../app-topology.md](../app-topology.md#feature-declaration)).
   Both pages, their table bindings and the users table become required at
   startup; the presence source in step 3 is checked by the project's topology
   test, since a runtime collection is not visible in the constants.
2. **DB user entity.** Generate the project's panel-operator entity triad and
   migration. `id`, `admin`, `block` are the framework-fixed base fields the
   project persists; add project fields beside them. Register the collection on
   the project `DbContext`. *(Contract Gate: entity fields.)*
3. **RT presence source.** Generate an RT connections collection that
   `implements Hilos\Runtime\View\Collection\HilosPresenceSource`, returning a
   `HilosUserPresenceSummary` from `summaryForUser(?int)`. Register it on the
   project `RtContext`, returned from `createRuntime()`. *(Contract Gate: RT item
   shape.)* Presence comes from this project RT collection — never framework
   analytics, which is process-local and not user-keyed.
4. **Table.** Generate a subclass of `AbstractHilosUsersTable` implementing the
   five hooks (`usersSourceKey`, `presenceSourceKey`, `presenceSource`,
   `rowForUserId`, `resolveUserIdForPresence`) and a subclass of
   `AbstractHilosUserTableRow` that folds the base fields via `baseFields()` and
   adds the project columns. The merge dispatch is `final` in the base — do not
   re-implement it.
5. **Page.** Generate thin concrete pages — `extends Hilos\Pages\Users\AbstractHilosUsersPage`
   / `AbstractHilosUserPage`; the subscribe and the action lifecycle stay
   framework-owned.
6. **Topology + frontend.** Register the page(s) in `PAGES`, the table in
   `TABLES`, the binding in `PAGE_TABLES`. Mount the SDK views: map
   `HilosPages.USERS`/`USER` to the `@hilos/{vue,react,angular}` `admin/users/`
   views, passing the thin typed context — the frontend twin of the backend
   collection binding.

### backup — configure-only engine + monopoly agent

The framework owns the whole backup engine: the monopoly `BackupAgent`
(`AgentType::HILOS_BACKUP`) that runs the cron schedule and mutates storage, the
`mysqldump` child driven by the `backup:run` command, the retention pruner, the
runtime index, and `Hilos\Pages\Backup\AbstractHilosBackupPage` with the
create / delete / set-keep action lifecycle. The project brings only
configuration and binding — no engine, no action code. It is configure-only like
settings, but the engine is a monopoly agent plus a CLI command, so activation
also registers those. Generate, in any order:

1. Declare the feature: `HilosFeature::BACKUP` in the project facade's `FEATURES`
   ([../app-topology.md](../app-topology.md#feature-declaration)). It is also
   what brings the runtime index: the framework mounts `hilosBackupHistories`
   with its own representation and `hilosBackupRuntime` for a project that
   declares it, so the project writes no mount line at all — and is refused at
   startup if it writes one anyway
   ([../runtime/rt-context.md](../runtime/rt-context.md), *Feature-Owned Runtime
   State*).
2. A backup catalog — `final class … implements
   Hilos\Core\Catalog\CatalogProviderInterface` — bound through the
   `BACKUP_CATALOG` constant on the project `Hilos` facade (the framework default
   is `null` = subsystem off). It carries three content keys: the per-connection
   reference-table registry under `BackupConstants::CATALOG_REFERENCES` (a list of
   Entity/Object collection class-strings per connection index — the framework
   derives table names from the classes, so the registry survives table renames,
   and keeps their rows under the schema-seed scope; an empty registry is valid —
   schema-seed then captures schema only, with a warning), the PII registry under
   `BackupConstants::CATALOG_PII` (a table-to-strategy map per connection index,
   classifying every table the project creates — without it a restore that requires
   anonymization refuses at the coverage gate before it imports anything, so this
   key is not optional for a project that will ever restore into a lesser
   environment: [backup-anonymization.md](backup-anonymization.md)), and an optional
   schedule under `BackupConstants::CATALOG_SCHEDULE` (omit it to take the
   framework default: one daily full backup at 03:00 on the agent mechanism).
3. Environment values through the project `EnvCatalog`: `BACKUP_ENABLED`,
   `BACKUP_DIR`, `BACKUP_CLI_ENTRY`, `BACKUP_RESTORE_TIMEOUT` (seconds the
   supervisor gives a hot restore child before killing it, default 3600;
   `BACKUP_TIMEOUT` is the create child's own budget;
   `HILOS_DB_REHYDRATE_TIMEOUT` is a third and much smaller one, default 30 —
   how long the daemon waits for every process to confirm it re-read the
   replaced database, which happens over connections they already hold, so
   minutes there would mean a dead process rather than slow work), the retention ladder
   (`BACKUP_RETENTION_DAILY` / `WEEKLY` / `MONTHLY` / `YEARLY` — each is the *age*,
   in its own unit, at which that granularity starts applying, so a backup younger
   than `BACKUP_RETENTION_DAILY` days is never thinned), and
   `BACKUP_ERROR_RETENTION_COUNT`, and the free-space gate
   (`BACKUP_SPACE_MARGIN` — the multiplier on the estimated uncompressed peak,
   default 1.5; `BACKUP_MIN_FREE_BYTES` — an absolute free-space floor checked on
   every run, default 1 GiB; `BACKUP_REFUSE_WITHOUT_ESTIMATE` — whether to refuse a
   run of a scope that has no prior successful run to size from, default false so a
   first backup is never blocked). The gate lives in `BackupAgent::startBackup()`,
   the single start point for every source: it prunes first (so rotation frees space
   that counts toward the current run), measures free space, and refuses up front a
   run that will not fit — recording the refusal as an error row (`recordFailure()`,
   no child spawned) that explains itself through the HIL-429 detail modal. An
   unmeasurable filesystem or an unreadable policy proceeds rather than stops. These
   are framework `EnvConstants` keys the
   agent and pruner read from `Hilos::$env`; the project supplies values, never new
   keys. `BACKUP_DIR` and `BACKUP_CLI_ENTRY` are what the create path *needs*:
   without either, every create is refused up front
   (`BackupAgent::missingCreateConfig()`) — a page action fails with an
   ACTION_ERROR and the agent logs the missing key on start. Give `BACKUP_DIR` a
   working default in the catalog rather than leaving it to the deployment (the
   chat demo computes `demo/chat/data/backup`, keeping the env value an override),
   or the feature activates into a state where nothing can ever be written.
   `BACKUP_DIR` is a local directory, so every archive lives on the same host and
   disk as the application it protects — and, in a cluster, on whichever node
   currently holds the monopoly agent. Shipping is what takes a copy off that
   machine, and it activates through four more values: `BACKUP_SHIP_TARGET` — the
   destination, `ssh://<user>@<host>[:<port>]/<abs-path>` or `file:///<abs-path>`
   (a mounted network share is served by the second, so it needs no scheme of its
   own); `BACKUP_SHIP_SSH_KEY` and `BACKUP_SHIP_SSH_KNOWN_HOSTS` — the private key
   and the file the receiver's host key is pinned against, both read by the ssh
   scheme only, and the ssh scheme refuses to ship without the second (shipping is
   unattended, so there is nobody to answer a first-connection prompt, and the
   payload is an unencrypted dump of the whole database); `BACKUP_SHIP_TIMEOUT` —
   seconds before the agent kills a hung transfer, default 3600, beside
   `BACKUP_TIMEOUT` and `BACKUP_RESTORE_TIMEOUT`. **No target = no shipping**: an
   empty or unparseable destination leaves the subsystem behaving exactly as it
   does without one, and the admin list says so in its Copy column. The
   destination root itself has to exist on the receiving side — rsync creates the
   per-scope directory under it and nothing above that. The copy leaves
   *after* the run, in the agent's own second process slot, so a broken link never
   turns a valid archive into an error row; the destination is a mirror, so both
   deletion paths — rotation and the row's delete action — take the remote pair
   away too.
4. A `mysqldump` binary on `PATH` in the runtime image that hosts the agent — the
   `backup:run` child shells out to it — and the `mysql` client beside it, which
   the `backup:restore-run` child replays dumps through (Debian:
   `default-mysql-client` provides both). Missing, it is not a config error but a
   failed run: the child exits non-zero and the run is recorded as an error. A
   deployment that configures shipping needs `rsync` on `PATH` as well, and
   `openssh-client` beside it for the ssh scheme (Debian: the two packages of those
   names) — the transfer is a spawned child exactly as the dump is, and a missing
   binary is likewise a failed transfer rather than a refused start.
5. Register the framework `BackupAgent` + `BackupAgentDaemon` in the project
   `AGENTS` under `BackupAgent::AGENT_TYPE` — it is monopolistic, so it claims a
   monopolistic worker slot ([../../new-project/README.md](../../new-project/README.md),
   *Worker pool*) — and expose both child commands in the project CLI command
   registry: `backup:run` (`BackupConstants::RUN_COMMAND`) and
   `backup:restore-run` (`BackupConstants::RESTORE_RUN_COMMAND`). The feature
   declaration requires both, so a forgotten registration is refused at startup
   rather than discovered by the first restore. All are framework-owned; the
   project only lists them.
6. Nothing for the runtime index — step 1 already brought it. Files are truth;
   the index is a rebuildable projection the agent rescans from `BACKUP_DIR` on
   start, so the project persists no backup DB table either. It is mounted *with*
   the framework representation because the halves cannot be separated: the agent
   is monopolistic and the page is served by whichever worker owns the browser's
   connection, and only the actions emit `RT_SYNC_*`, so a state collection
   mounted without `setRepresent()` lives in the agent's worker alone and the page
   shows an empty table forever
   ([../runtime/rt-context.md](../runtime/rt-context.md), *A collection written
   outside its actions is worker-local*). That pairing used to be the project's to
   get right; it is the framework's now.
7. Register the framework table `Hilos\Tables\Backup\HilosBackupHistoryTable` in
   the project `TableContext`, add a thin subscription-owner page — `final class …
   extends Hilos\Pages\Backup\AbstractHilosBackupPage` carrying only a
   `SUBSCRIPTION_AGENT_TYPE` — to `PAGES`, and bind the table to the page in
   `PAGE_TABLES`. Register the framework table; never subclass it. Add the page's
   nav entry to the project page catalog if it should appear in the admin shell.
8. Mount the SDK view: map `HilosPages.BACKUP` to the framework view from
   `@hilos/{vue,react,angular}` `admin/backup/HilosBackupPage`, through a thin
   project context (`HilosBackupsContext` = `{ connection, scopes, actions }` from
   the project bootstrap). The table, the row view-model, the live-row behavior,
   and the create / delete / keep round-trips are framework-owned — the context is
   binding, not page logic.

Nothing above configures archive checksums: they are unconditional. A backup taken
after HIL-435 records a `sha256` of its archive in the sidecar as part of the same
atomic publish, and the sidecar also carries `verifiedAt` / `verifyOutcome` once the
archive has been checked. A sidecar written before that carries none of the three and
reads back as null — "nothing to check", not "corrupt" — so an upgrade does not turn
the accumulated history red. The list's Checksum column reflects exactly that: a dash
when there is no digest, `present` for one nobody has checked, the check date once it
matched, and a red `MISMATCH` when it did not. The digest itself never reaches the
browser.

Checking is an operator action, not a scheduled one: `php cli.php backup:verify [id]
[--scope=<scope>]` is framework-owned and usable on production. It hashes in the CLI
process (never in the monopoly agent, where a multi-gigabyte hash would freeze backup
creation and page actions for minutes), rejects an archive whose size already
disagrees without reading it, stamps each ok/mismatch back into the sidecar, and asks
the running daemon to re-mirror its index, so an open list learns of the result without
waiting for the next rescan or restart — arriving like any other row change, behind the
list's Apply gate. A daemon that does not answer costs a warning, since files are the
truth and the index catches up on the next rescan anyway (HIL-528 replaces that poke
with filesystem watching). It exits 0 when everything it checked matched or had nothing
to check, 1 on a mismatch or on anything left unverified, 2 on an unknown id or scope,
and 3 when `BACKUP_DIR` is not configured. The summary distinguishes the two: `skipped`
is a backup carrying no digest, which is not an error, while `unverified` is an archive
that is missing or unreadable, a sidecar that could not be read or paired, or a verdict
that could not be written back.

Restoring is an operator action too (HIL-274): `php cli.php backup:restore <id>
[--scope=<scope>] --yes [--force] [--cold]`, framework-owned. The preflight runs in
the CLI on both paths — archive resolution, a digest re-check, the environment
matrix, and the explicit `--yes` a destructive operation requires. The matrix: a
prod archive restores into prod as-is (disaster recovery); a prod archive into a
non-prod target restores through the anonymization pass, which needs a declared PII
registry and refuses without one ([backup-anonymization.md](backup-anonymization.md)); a
non-prod archive never enters prod; an archive whose sidecar records no environment
needs `--force` to enter prod. By default the restore is HOT: the daemon's backup
agent freezes the node through protected mode, spawns the `backup:restore-run`
child under `BACKUP_RESTORE_TIMEOUT`, and the CLI stays a monitor — closing it
abandons nothing, and the outcome remains readable in the restore runtime row
(`hilosRestoreRuntime`). With an explicit `--cold` the engine runs synchronously in
the CLI process for a daemon that is down; a daemon that does not answer is an
error, never a silent fallback to cold. The engine replays each `db-<index>.sql`
into the connection of the same index — into that connection's *currently
configured* database name — and re-verifies the digest immediately before its
destructive steps. Tables absent from the dump are left in place; the
migration-index gate is HIL-430. A hot restore also carries the live
authenticated sessions across the swap before it thaws the node (HIL-479), so the
operator watching the restore is not logged out by it; a project whose runtime
connections do not reach the session stage of the connection base
(`HilosSessionConnections`) has no session tokens to photograph and simply
nothing to carry.

A hot restore does not end where its SQL ends (HIL-436). Between the child's exit
and the terminal outcome the run passes through `RestorePhase::REHYDRATING`: every
process holding database-backed collections — the daemon, each worker, and in a
cluster each node — re-reads them and confirms, under
`HILOS_DB_REHYDRATE_TIMEOUT`. Only a barrier that closes moves the node on to the
verification window (HIL-481); otherwise it stays shut to everyone, the processes
that did not answer are named on the restore runtime row
(`rehydrateComplete` / `rehydrateProblems`), and a human decides with
`protected-mode:open`. The child says whether it got as far as writing by
returning `BackupConstants::RESTORE_EXIT_DATABASE_INTACT` instead of a plain
error, which the supervisor records as `databaseTouched` and the monitor prints as
the difference between "the database was not touched" and "it may be left
partially replaced" — so a project's own `backup:restore-run` command must map its
failures through `RestoreFailedException::databaseTouched()` rather than
returning one error code for everything.

The CLI is not the only entrance any more (HIL-276): the backup page carries a
per-row Restore, and both entrances meet at the agent's single admission, so the
preconditions cannot drift into two sets. The button exists on every environment
except `APP_ENV=prod` — and except an installation whose `APP_ENV` names nothing
known, which is treated as production, because one that cannot say it is not live
does not get a destructive button. This is deliberately *not* `isProductionLike()`:
that question is about seeds and test-only commands, where staging is production on
purpose, while restore is exactly what a staging stand is for. Where the button is
withheld the row offers `How to restore` instead — the canonical command with the id
and scope already substituted, so nobody retypes an identifier; the value of
`BACKUP_CLI_ENTRY` is not revealed, since where the script lives inside the container
says nothing about how an operator reaches the machine. Confirmation is typing the
archive's id rather than pressing Yes: it is the one barrier muscle memory cannot
pass, and the likely mistake here is restoring the wrong archive rather than clicking
the wrong button. Everything the page checks lives in `RestoreUiGate` — environment,
the archive's presence, status and checksum, whether the subsystem is busy, and the
ENV matrix verdict — so the validation is testable without a page; the scope travels
from the index row, never from the client, and `--force`, `--cold` and
`--migration-index` have no UI at all.

While a restore runs the page cannot report from the table: the node is frozen and
the page's own agent is stopped, so no delta is produced by anyone. The agent instead
addresses a `backup_restore_progress` frame to the connection that asked — protected
mode keeps that one connection alive precisely so the operation has somewhere to
report — carrying the same snapshot the CLI monitor is answered with, at the four
points where the runtime row itself changes. The phases the supervisor sees on its
own are coarse — it watches a child's lifecycle, not the steps inside it — so the
child prints each phase it enters up the pipe (HIL-277, below). The terminal frame
is where the human is told what is
expected of them: a success reloads the page when the mode lifts, while a failure
names the reason, whether the database was touched, and — when the re-hydrate barrier
did not close — that the system stays shut until `protected-mode:open`, because in
that case no reload is coming to say so. Afterwards the outcome lives on the row of
the archive that was replayed (`restorePhase`, `restoreOutcome`, `restoreFinishedAt`,
`restoreFailureReason`, `restoreDatabaseTouched`), which is what an operator who
reloads later reads instead of the frame.

Both runs report progress the same way (HIL-277), and the runtime row carries three
anchors rather than a number: the current phase, the instant it began, and how long
the whole run is expected to take. The percentage and the time left are *computed by
whoever shows them* — the browser from the table row or the restore frame, the CLI
monitor from the status payload — over one formula that exists twice, in
`Hilos\Backup\BackupProgress` and in `@hilos/core`, with the two unit suites pinned to
the same numbers so the copies cannot drift. That is the price of the choice, and it
buys the property that matters: RT is written **only on a change of phase** — four
writes for a create, six for a restore — so there is no periodic timer anywhere and
nothing to throttle, while the bar still moves every second because the client is the
one doing the arithmetic. The phases arrive from the child process, which prints
`hilos-backup-phase <value>` to stdout on entering each (`BackupProgressMarker`); the
agent reads that off the pipe on its tick. The estimate comes from history —
`BackupEstimator` takes the median duration of the last five successful runs of the
same scope for a create, and for a restore the median seconds-per-byte of the last
five recorded restores of that scope times this archive's size, which is why a restore
first needs a history to have one: the length of the last restore of each archive is
written into its sidecar, the same postfactum rewrite `recordVerification()` already
does, and the scanner lifts it into the index. A run that cannot be estimated shows no
percentage at all rather than an invented one — the surfaces fall back to the phase
name and an indeterminate bar — and a run that outlives its estimate is told in words
("taking longer than usual"), because a negative number and a frozen zero both read as
"almost done".

### a future framework feature (roles, …)

A new framework admin feature ships the same shape: a base page (subscribe +
actions), a base table (the engine), and either a framework-owned source or a
project-bound contract. Pick the recipe by which one it is:

- framework owns the data source → follow the **settings** recipe (catalog +
  register the `final` table + thin page + mount);
- data is project-owned behind a framework contract → follow the **hilos-users**
  recipe (generate the binding: entity / presence source + the abstract hooks +
  thin page + mount).

If neither fits — there is no framework base yet — the feature is not ready to
scaffold; it must first be built or graduated per [admin-features.md](admin-features.md).

## Preferred Shape

```php
// configure-only: the generated page is the subscription owner only.
final class SettingsPage extends Hilos\Pages\AbstractHilosSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

// configure-only still ships a browser context — an empty subclass; the base
// context delivers the settings snapshot through the self-snapshot path.
final class AppBrowserContext extends Hilos\Core\Browser\Context\BrowserContext
{
}

// bound: the generated subclass implements the hooks; the merge stays in the base.
final class UsersTable extends Hilos\Tables\Users\AbstractHilosUsersTable
{
    protected function usersSourceKey(): string { return DbContext::users; }
    protected function presenceSourceKey(): string { return RtContext::connections; }
    protected function presenceSource(): Hilos\Runtime\View\Collection\HilosPresenceSource
    {
        return Hilos::$rt->connections;
    }
    // rowForUserId(), resolveUserIdForPresence() — bind the sources, do not re-merge.
}
```

## Anti-Patterns

- Do not generate a copy of the engine (table query/merge/mutation, or a page
  `onAction` lifecycle); extend the framework base and fill its extension points.
- Do not back presence with framework analytics; implement `HilosPresenceSource`
  on a project RT collection.
- Do not scaffold a project's own divergent table here; that is Mode-2 authoring
  ([admin-features.md](admin-features.md)), not activation of a framework feature.
- Do not generate the table before its DB/RT sources are registered.
- Do not skip the project `BrowserContext` for a configure-only feature; without
  it the page answers a subscription with nothing. An empty subclass is the fix.

## Contract Gate

A bound feature's generated entity and RT item are contract surfaces. Stop and
ask for explicit confirmation, per the root `AGENTS.md` gate, before generating:

- the DB entity fields or migration shape (for hilos-users, the framework-fixed
  `id`/`admin`/`block` contract);
- the RT connection/presence item shape consumed by the merge.

Signals, action DTOs, and routing for a framework feature ship with the framework
base, not per project — they are not generated here.

## Validation

Use `$hilos-testing-cli` to choose composer scripts. After generating, keep the
target project's admin e2e green and add coverage for the project-owned entity
and presence source of a bound feature. The framework base classes named above
are the contract the generated code binds to; do not modify them to make
activation fit.
