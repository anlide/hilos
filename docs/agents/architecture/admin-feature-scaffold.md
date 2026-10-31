# Architecture: Admin Feature Scaffold

Read this before generating the activation of a framework-owned admin feature —
settings, hilos-users, Status, Analytics, Moderation and future ones like roles
— in a project. Moderation's recipe below marks the code still owned by future
leaves. This is a generation recipe for an AI agent: given a framework admin
feature, produce the project-side code that activates it. The normative
boundary and the two modes live in [admin-features.md](admin-features.md); this
file is the per-feature generation order.

**Scope: framework-owned features only.** A project's own divergent admin table
(the chat demo's bots table, Mode 2) is not scaffolded here — that is authoring
a new feature, not activating a framework one. See
[admin-features.md](admin-features.md) Mode 2 for that.

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
`resolveConnectionIdentity()` on its `BrowserContext` — or the mounted feature
denies everyone. `isAdmin()` is answered by the framework from
`hilos_user.admin`; the project wires nothing for it. See
[page-access-control.md](page-access-control.md).

For framework SQL in an existing catalog-initialized project, declare the feature,
run `db:framework:pull`, then review and commit the generated up/down pairs before
applying them through the normal project migrator. Use the
[migration authoring and activation canon](../orm/migrations.md#framework-tables-in-a-project-hil-1326),
including its initial-adoption boundary. The command selects the required units;
do not copy stubs by hand. Current stubs, Entity metadata, personal-data verdicts
and generated journal trigger files remain required.

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
- **Framework data with a project-bound presence source (bound).** The framework
  owns the merge engine, the row contract, and the data — the people table
  `hilos_user`; the project binds only its
  presence. Hilos-users is this shape: `Hilos\Tables\Users\AbstractHilosUsersTable`
  is abstract with one hook — the presence collection's key; the rest, presence
  included, are the base's defaults. `HilosUserTableRow` is the one row of every
  project (`id`/`admin`/`block`/`name`/`lastActivity` + presence), and presence
  flows through the `Hilos\Runtime\View\Collection\HilosPresenceSource`
  interface returning a `Hilos\Runtime\View\DTO\HilosUserPresenceSummary`.
  Bound is not "inventing a table" — it is implementing a framework contract.
  The rules of the table itself are in [people-table.md](people-table.md).

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
6. Register the settings library in `AGENTS`:
   `Hilos\Database\Settings\Library\SettingsLibraryAgent::AGENT_TYPE` with
   `WORKER => SettingsLibraryAgent::class`, `DAEMON =>
   SettingsLibraryAgentDaemon::class` and `PLACEMENT => AgentPlacement::POLICY`.
   Both classes are framework-owned and concrete: register them directly, with no
   project subclass. This is the single writer of the settings collection
   (HIL-946) — the admin screens check the caller and forward, and nothing else
   may write a settings row.
   **The step is shared with `LOGS` and `NOTIFICATION_DELIVERY`** and is done once:
   all three declare the library under `requiredSharedAgents`, so a project holding
   any of them needs this entry, and a project holding all three still needs only
   the one.
7. Mount the SDK view: map `HilosPages.SETTINGS` to the framework view from
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
   test, since a runtime collection is not visible in the constants. The test
   checks no block source: there is none to check.
2. **The people table.** Run `db:framework:pull` and apply its generated pairs
   through the project migrator. The core `user` unit supplies the current
   `create_hilos_user.sql` form if it is not already covered; no entity is
   generated — the person is the framework's `hilos_user`.
   A project that needs columns of its own adds them by subclassing the whole
   ORM chain and mounting it under the
   framework's `users` key ([../orm/inheritance.md](../orm/inheritance.md));
   *(Contract Gate: the subclass's columns.)* No block source and no line in
   `processWideReadCollections()` is needed from the project: `block` is a
   framework column the framework reads itself, and `users` is among the
   framework's own process-wide reads.
3. **RT presence source.** Generate an RT connections collection that
   `implements Hilos\Runtime\View\Collection\HilosPresenceSource`, returning a
   `HilosUserPresenceSummary` from `summaryForUser(?int)`. Register it on the
   project `RtContext` under the key `connections`
   (`HilosUserDetailBrowserTable::CONNECTIONS`), returned from `createRuntime()`:
   the card of one person reads presence under that key, and a collection
   mounted under another one is refused by the start's source check.
   *(Contract Gate: RT item shape.)* Presence comes from this project RT
   collection — never framework analytics: the cluster writer loads those records
   seconds later; they are not live connection state.
4. **Table.** Generate a subclass of `AbstractHilosUsersTable` with two things:
   a `BROWSER` built from the base's `USERS_SOURCE` and `USERS_ROW` plus the row
   of the project's own connections collection (its `userId` as the row key,
   `presence` and `onlineSessionCount` computed), and `presenceSourceKey()`
   naming that collection. There is no row subclass: `HilosUserTableRow` is
   `final`, one shape for every project. Override `presenceSource()` and
   `resolveUserIdForPresence()` only for presence kept in a collection of
   another kind than the framework's `HilosConnections`. Reading the people,
   the query, sort and search, and the merge dispatch are the base's — do not
   re-implement them. A project that merges accounts registers the candidates
   window as the framework class itself, `Hilos\Tables\Users\HilosMergeCandidatesTable`,
   the way it registers `HilosSettingsTable`. The card of one person is the
   framework's too: `Hilos\Tables\Users\HilosUserDetailBrowserTable`, a browser
   table the project registers as it is (step 6) — the project writes neither
   the card nor the counting behind it (presence, the session count, the
   password fields and the date a deletion falls due are computed by the
   framework's `BrowserContext`). A user page bound to anything else is refused
   by the activation of `HILOS_USERS` (HIL-1254).
5. **Page.** Generate thin concrete pages — `extends Hilos\Pages\Users\AbstractHilosUsersPage`
   / `AbstractHilosUserPage`; the subscribe and the action lifecycle stay
   framework-owned.
6. **Topology + frontend.** Register the page(s) in `PAGES`, the table in
   `TABLES`, the card in `BROWSER_TABLES`
   (`HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class`),
   the bindings in `PAGE_TABLES` — the people table on the users page, and on
   the user page the card's ready binding
   (`HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING`).
   Mount the SDK views: map
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
   State*). The verifier circle's table is no part of this activation: it
   belongs to the freeze ([protected-mode.md](protected-mode.md)), so every
   installation that can freeze carries it, backup or not. `db:framework:pull`
   selects `verifier_circle` when the facade has a runtime factory and brings
   the `create_hilos_verifier_circle.sql` form into the project track; apply
   the generated pairs as above. The project's topology unit test also asks
   for this table. Its surface is a section of its own, `hilos_maintenance`
   (see *a framework-owned
   section with no feature switch* below).
2. A backup catalog — `final class … implements
   Hilos\Core\Catalog\CatalogProviderInterface` — bound through the
   `BACKUP_CATALOG` constant on the project `Hilos` facade (the framework default
   is `null` = subsystem off). It carries three content keys: the per-connection
   reference-table registry under `BackupConstants::CATALOG_REFERENCES` (a list of
   Entity/Object collection class-strings per connection index — the framework
   derives table names from the classes, so the registry survives table renames,
   and keeps their rows under the schema-seed scope; an empty registry is valid —
   schema-seed then captures schema only, with a warning), the map naming the
   project's tables that live outside the ORM under
   `BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY` (a `TablesWithoutEntityProvider`
   class-string per connection index; omit it when every table the project creates
   has an Entity, as the chat demo does), and an optional schedule under
   `BackupConstants::CATALOG_SCHEDULE` (omit it to take the framework default: one
   daily full backup at 03:00 on the agent mechanism). What of the data is personal
   is declared on the tables themselves rather than here — `_pii` and
   `_piiNotPersonal` on each Entity, and the provider above for a table without one;
   a table nobody classified is what a restore that requires anonymization refuses
   on at the coverage gate, before it imports anything
   ([backup-anonymization.md](backup-anonymization.md)).
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
   `BACKUP_ERROR_RETENTION_COUNT`, and `BACKUP_MAX_TOTAL_BYTES` (the total byte
   ceiling for the store, default 0 = unbounded, thinning oldest-first past the
   ladder while never removing an error record, the newest backup of each scope, a
   row whose scope or timestamp does not read, a pin, or an archive that has not
   been shipped yet — six guards, and the agent log names the ones that held the
   store whenever it stays over the ceiling), and the free-space gate
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
   `BACKUP_DIR` is a local directory, so an archive belongs to the node that took
   it and lives on that node's disk. Environments of one project share that one
   directory: an archive belongs to the project, not the environment. The
   environment rides in the archive name and is decided by the restore matrix, so
   a copy taken in one environment can be restored in another (prod onto a stand,
   through anonymization) without moving files. The computed project-directory
   default (`ChatEnvCatalog::defaultBackupDir()`, and the same shape in tasks)
   gives that by construction; `BACKUP_DIR` remains an override for a deployment
   that wants otherwise. In a cluster the monopoly agent is pinned to
   its node by `AgentPlacement::POLICY`, so a change of leadership — every master
   restart is one — no longer moves it away from its archives. A dead node still
   moves it, and that is wanted: the agent comes up elsewhere and goes on taking
   backups into that node's directory. The archives it leaves behind stay in the
   index, marked out of reach and named by their node, rather than being dropped —
   an empty list would read as "no backups were ever taken" — and restore, delete,
   the keep pin and rotation all refuse them. Shipping is what takes a copy off that
   machine, and it activates through five more values: `BACKUP_SHIP_TARGET` — the
   destination, `ssh://<user>@<host>[:<port>]/<abs-path>` or `file:///<abs-path>`
   (a mounted network share is served by the second, so it needs no scheme of its
   own); `BACKUP_SHIP_SSH_KEY` and `BACKUP_SHIP_SSH_KNOWN_HOSTS` — the private key
   and the file the receiver's host key is pinned against, both read by the ssh
   scheme only, and the ssh scheme refuses to ship without the second (shipping is
   unattended, so there is nobody to answer a first-connection prompt, and the
   payload is a dump of the whole database — encrypted or not, a receiver that is
   whoever answered is a problem of its own); `BACKUP_SHIP_ENCRYPT_RECIPIENTS` — an
   absolute path to a file of `age` public keys, one per line, which the copy that
   leaves is encrypted to; `BACKUP_SHIP_TIMEOUT` — seconds before the agent kills
   a hung transfer, default 3600, beside
   `BACKUP_TIMEOUT` and `BACKUP_RESTORE_TIMEOUT`. **No target = no shipping**: an
   empty or unparseable destination leaves the subsystem behaving exactly as it
   does without one, and the admin list says so in its Copy column. The
   destination root itself has to exist on the receiving side — rsync creates the
   per-scope directory under it and nothing above that. The copy leaves
   *after* the run, in the agent's own second process slot, so a broken link never
   turns a valid archive into an error row. The receiver loses only what was
   deleted here, and only by name: a local delete of a shipped backup leaves an
   empty `<base>.deleted` marker beside the pair, the mirror pass includes those
   names, and a successful pass drops the markers it covered. Copies no marker
   points at — archives of a dead node, history from before a disk replacement —
   stay on the receiver until the operator removes them by hand.

   **Encryption is one behaviour, not a switch.** An empty
   `BACKUP_SHIP_ENCRYPT_RECIPIENTS` — the default — is the *absence* of encryption:
   the copy leaves in the clear, exactly as it did before the value existed. A
   configured file makes what leaves ciphertext. The local archive is never
   encrypted, so a local restore is untouched, and the sidecar travels in the clear
   because it carries no personal data and is how the far side knows what is lying
   there. The file holds **public** keys only: the machine can encrypt and not
   decrypt, so breaking into it does not open what has already left — and, as the
   deliberate other half of that, the framework cannot restore from the remote copy
   at all. A file that is configured but unreadable, empty, or naming no recipient
   turns shipping off **altogether** rather than falling back to a clear copy: the
   fallback is the exposure the value was configured against. The agent says so
   once in its log, and the Copy column reads such an installation as `none`.
   Changing the value re-sends the whole store — turning a key on, off, or over is
   the same event, and one mark beside the shipping outcome closes all three
   without a migration command of its own.

   **Restoring from the remote copy is an operator procedure, not code**, and one
   step longer than it was: `age -d -i <private key> -o <base>.tar.gz <what was
   fetched>`, then put the decrypted archive into `BACKUP_DIR` under its scope
   directory and run `backup:restore` as usual. The name on the receiver does not
   change — the file is called `<base>.tar.gz` whether or not it is ciphertext — so
   what says which it is are the sidecar beside it and the file's own first bytes,
   the text header `age-encryption.org/v1`. The `sha256` in the sidecar describes
   the **decrypted** archive, so it matches again after the round trip and
   `backup:verify` checks it the way it always did.
4. A `mysqldump` binary on `PATH` in the runtime image that hosts the agent — the
   `backup:run` child shells out to it — and the `mysql` client beside it, which
   the `backup:restore-run` child replays dumps through (Debian:
   `default-mysql-client` provides both). Missing, it is not a config error but a
   failed run: the child exits non-zero and the run is recorded as an error. A
   deployment that configures shipping needs `rsync` on `PATH` as well, and
   `openssh-client` beside it for the ssh scheme (Debian: the two packages of those
   names) — the transfer is a spawned child exactly as the dump is, and a missing
   binary is likewise a failed transfer rather than a refused start. One that also
   configures `BACKUP_SHIP_ENCRYPT_RECIPIENTS` needs `age` (Debian: the package of
   that name, which carries `age-keygen` too), on the same bargain: an installation
   that ships nowhere never calls it, so its absence is a failed transfer and not a
   refused start.
5. Register the framework `BackupAgent` + `BackupAgentDaemon` in the project
   `AGENTS` under `BackupAgent::AGENT_TYPE` — it is monopolistic, so it claims a
   monopolistic worker slot ([../../new-project/README.md](../../new-project/README.md),
   *Worker pool*). Nothing to do about the two child commands: `backup:run`
   (`BackupConstants::RUN_COMMAND`) and `backup:restore-run`
   (`BackupConstants::RESTORE_RUN_COMMAND`) are registered by `CliManager` itself
   since HIL-729, so there is no registration left to forget and the feature stops
   asking the project for one. All of it is framework-owned.
6. Nothing for the runtime index — step 1 already brought it. Files are truth;
   the index is a rebuildable projection the agent rescans from `BACKUP_DIR` on
   start, so the project persists no backup DB table either. It is mounted *with*
   the framework representation because the halves cannot be separated: without
   `setRepresent()` the page has no view to read and the agent has no actions
   that check its write permission. Direct membership writes bypass that check
   and are refused by `RT-STATE-MUTATE`
   ([../runtime/rt-context.md](../runtime/rt-context.md), *A collection written
   outside its actions skips its owner*). That pairing used to be the project's to
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
[--scope=<scope>] [--migration-index=<N>] --yes [--force] [--cold]`,
framework-owned. The preflight runs in
the CLI on both paths — archive resolution, a digest re-check, the environment
matrix, and the explicit `--yes` a destructive operation requires. The matrix: a
prod archive restores into prod as-is (disaster recovery); a prod archive into a
non-prod target restores through the anonymization pass, which needs every table to
carry a personal-data verdict and refuses without one
([backup-anonymization.md](backup-anonymization.md)); a
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
migration-index gate is HIL-430. A schema archive carries the migration table
without its rows, so its level travels as a marker written into the dump
(HIL-570) and the engine records that level before it migrates forward; an
archive taken before the marker existed declares none, and such a restore is
refused before the first import unless the operator names the level with
`--migration-index=<N>` (schema scopes only, and `--force` does not lift it).
A hot restore also carries the live
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
the archive's presence, status and checksum, its migration levels against this code,
whether the subsystem is busy, and the ENV matrix verdict — so the validation is
testable without a page; the scope travels from the index row, never from the client,
and `--force`, `--cold` and `--migration-index` have no UI at all.

The migration-index verdict is read before the click rather than after it (HIL-559):
the row's Restore cell carries a red `incompatible` badge for an archive taken on code
newer than this installation's — refused forever, there is no downgrade path — and an
amber `+N migrations` for one taken behind it, which restores and then migrates
forward. The badge speaks only where no restore of that archive has an outcome or a
phase to report: an event outranks a property. Where the badge says "no", the button
is disabled and carries the reason, the same way a checksum mismatch disables it, and
the per-connection detail waits in the confirmation modal above the id field —
a yes-or-no in the list, the sentences where the operator is already reading which
archive they picked. The production surface shows them in the `How to restore` modal
too, so nobody walks to a terminal to be refused there. The sentences are the CLI
preflight's own, because they are computed in one place
(`RestoreMigrationDecisionResult::describeGaps()`) and two presentations of one verdict
have no right to word it differently. The verdict is not live and needs no signal: an
archive's levels are written into its sidecar forever, and the level the code expects
changes only with a restart — so it is computed while the table builds the row and
travels with it, as three flat fields. Which is also why every process now names the
schema migration track in `EntrypointPrelude`, not only the container entrypoint and
the CLI: the worker that serves the page applies no migrations, and without a level of
its own to compare against it called every archive unjudgeable.

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
reloads later reads instead of the frame. Beside them every stored row carries its
migration verdict (`restoreMigrationDecision`, `restoreMigrationBehind`,
`restoreMigrationNotice`) — a property of the archive, not of a run, so it is on all
of them rather than on the one that was restored.

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

### i18n — the languages and countries section

Configure-only: the framework owns the five reference tables, their library and the section's pages. The languages browser table is registered in all six demos (HIL-1474), and so is the countries table (HIL-1475).
The project lists the feature in `Hilos::FEATURES` and registers the library's agent pair in `Hilos::AGENTS`.
It registers three thin section pages in the server topology (HIL-1470).
Run `db:framework:pull` and apply the generated project migrations for the required
units: the five reference tables and the library's reflow record.
Vue, React and Angular serve the root and seven reference pages. React exports
`HilosI18nLanguagesPage`, `HilosI18nLanguagePage`, `HilosI18nLanguageNamesPage`,
`HilosI18nLanguageLocalesPage`, `HilosI18nCountriesPage`, `HilosI18nCountryPage`
and `HilosI18nCountryNamesPage`, with their public props (HIL-1502). Tasks and
ecommerce-shop mount seven thin consumers with their own language and country
contexts. Angular exports the same seven pages with a `context` input
(HIL-1503), and polls and online-testing mount seven thin wrappers over their own
language and country contexts. The context-required pages stay outside
`hilosAdminViews()`; the root remains the self-contained default shell, showing
Languages and Countries.
It supplies `HILOS_DEFAULT_LANGUAGE` with an exact built-in code.
Startup refuses a missing page or agent; topology tests check the required migrations.
For the section's rules and activation details, read [languages-and-countries.md](languages-and-countries.md), *Switching The Section On*.

### daemon — the Daemon section

Configure-only: the framework owns the node agent, the collector, the abstract page agent and the section's pages.
The project lists the feature in `Hilos::FEATURES` and registers the three agents in `Hilos::AGENTS`, the page agent as an empty subclass.
It registers the section's eight thin pages; six node child addresses require a node ID, while the root and Env mismatch do not.
The environment child page inherits its node-agent subscription route from the framework; its project subclass declares no page-agent owner.
Startup refuses a partially activated feature.
In Vue, React and Angular projects, mount the seven built pages — root, Workers, Agents, Cron, HTTP,
Environment and Env mismatch — through thin wrappers with the project's `connection` and `scopes`
context, as for the Logs pages; Agents also receives the project's action lifecycle.
Tasks supplies these React mounts and polls supplies the Angular mounts. The context-required SDK pages stay outside the default admin view map.
For the section's rules, read [daemon-section.md](daemon-section.md), *Switching The Section On*.

### status — Status section

Status is a framework-owned Mode-1 feature. The project supplies its public
component catalog, activates the feature and binds the framework pages and
tables; it does not copy the incident machine, subscriber storage, guest-action
handling or table implementation. Follow
[status-page.md](status-page.md#components-and-activation) for the full
contract.

1. Declare `HilosFeature::STATUS` in the project `FEATURES` and merge
   `StatusSettingsCatalog` into its settings catalog. Status requires `DAEMON`,
   `NOTIFICATION_DELIVERY` and `AUTH_THROTTLE`, the shared
   `MailDeliveryChannelAgent`, the Status agent pair, the catalog fragment, the
   Status database tables, three pages and two page-table bindings. Startup
   validates these obligations.
2. Run `db:framework:pull` and apply the generated project migrations for the
   existing `status_incident`, `status_incident_update` and `status_subscriber`
   schema units. The same immutable change generates the separate `auth_block`
   scope upgrade pair that adds `email` to its existing enum. The framework
   migration catalog already carries these generated pairs; use the ordinary
   migration path rather than writing a project schema.
3. Register `StatusAgent` and `StatusAgentDaemon` in `AGENTS` with cluster
   scope and policy placement. The owner remains the writer of incident,
   timeline and subscriber rows and the host of the guest subscription actions.
4. Register the thin Overview, Components and Subscribers pages in `PAGES`.
   Each uses the project's existing `HILOS_INDEX` as
   `SUBSCRIPTION_AGENT_TYPE`; no new page-serving agent is needed. The framework
   page catalog places Components and Subscribers under the shared Status head.
5. Register `HilosStatusComponentsTable` and
   `HilosStatusSubscribersTable` in `TABLES`. Bind the Components table to the
   Components page and the Subscribers table to the Subscribers page in
   `PAGE_TABLES`; Overview receives its data in the existing `page_response`
   and has no table binding. The current framework tables read their sources
   through `Hilos::$db` and `Hilos::$rt`, so they need no new project
   `TableContext` property.
6. Set `HILOS_STATUS_URL` to an absolute public HTTP or HTTPS `/status` address.
   The root `.env.example` leaves it blank; each demo environment example names
   its own local address, and each test compose file points at that demo's
   frontend `/status`.
7. In Chat and Binance BTC Tracker, mount the public Vue reader from
   `@hilos/vue/status` with `{connection, actions}` and mount the three admin
   pages from `@hilos/vue/admin/status` through thin wrappers carrying the
   existing Status context. Core keys `HilosPages.STATUS`,
   `HilosPages.STATUS_COMPONENTS` and `HilosPages.STATUS_SUBSCRIBERS` map to
   `/hilos/status`, `/hilos/status/components` and
   `/hilos/status/subscribers`; `HilosPages.PUBLIC_STATUS` is the separate
   `/status` file-reader route. React and Angular backends are ready, but their
   Status views stay in the unbuilt-page set until HIL-1695 and HIL-1696; do not
   expose empty tabs.

The shared framework tables and the existing project `TableContext` remain the
only table binding path. Do not generate a second subscription table, a project
mail queue, an alternate public URL handler, or a Status CLI scaffold.

### analytics — the Analytics section

Declare `HilosFeature::ANALYTICS` in `FEATURES`, run `db:framework:pull` and apply
the generated project migrations. The `analytics` unit keeps the aggregate
`create_hilos_analytics.sql` stub and its down file intact. Fold
`AnalyticsSettingsCatalog` into the project settings catalog, register
`FsContext::ANALYTICS_JOURNAL` as a `DirectoryScope::NODE` directory, and publish
a current Privacy revision declaring the `standard.deletion` exception for
numbered analytics left after account erasure. The startup checks the catalog,
directory and Privacy declaration; migration coverage belongs to the project's
topology test.

Register three agent pairs in `AGENTS`: one thin subclass of
`AbstractHilosAnalyticsAgent` with a monopolistic daemon (one cluster reader on
the default leader placement), `AnalyticsJournalAgent` / its daemon with
`AgentScope::NODE`, and `AnalyticsWriterAgent` / its daemon with
`AgentPlacement::POLICY`. The framework feature requires all three pairs. Add
a thin page subclass of `AbstractHilosAnalyticsPage` with
`SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS` to `PAGES`; startup
requires it and refuses that page or the reader agent without the feature.

Register both framework tables in `TABLES` and the project's `TableContext`:
`Hilos\Tables\Analytics\HilosAnalyticsActionsTable` under `hilosAnalyticsActions`
and `Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable` under
`hilosAnalyticsBrowserSessions`. The feature requires both registrations.
The backend reader supplies typed, bounded SQL results, and the frontend core
supplies the controllers and column declarations. Every demo with the feature
registers a thin `AbstractHilosAnalyticsBrowserSessionPage` subclass in `PAGES`
and binds `hilosAnalyticsActions` under that page in `PAGE_TABLES`; its route
supplies the `browserSession` table preset. It also registers a thin
`AbstractHilosAnalyticsPersonPage` subclass in `PAGES` and binds
`hilosAnalyticsBrowserSessions` under that page in `PAGE_TABLES`; its route's
`userId` supplies the `user` table preset. On its frontend the demo mounts its
own view layer's SDK views — `HilosAnalyticsBrowserSessionPage` and
`HilosAnalyticsPersonPage` in Vue, React or Angular — through thin wrappers that
pass the project's connection and scopes. The Analytics Overview is
context-dependent too, so a project mounts it through its own thin wrapper
rather than the context-free default admin view map; Tasks and ecommerce-shop
do this for React. The admin user card links to the person page by itself once
the feature is on and the person page is in `PAGES`; the project binds nothing
for the link.

The People page is an optional extension; `HilosFeature::ANALYTICS` does not
require its page or table. A project that opts in registers a thin `PeoplePage`
declared `final` and extending `AbstractHilosAnalyticsPeoplePage`, with only
its existing Analytics agent as
`SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS`, in `PAGES`.
Add the framework `HilosAnalyticsPeopleTable` to the project's `TableContext`
with `@property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople` and
`public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE`,
and to `TABLES`; bind that table only to `PeoplePage::PAGE` in `PAGE_TABLES`.
The base page supplies its existing empty route params, admin guard and
subscription; no custom subscribe handler, query, table subclass or new URL is
needed. Its address remains
`/hilos/analytics/people`.

The Vue SDK exports `HilosAnalyticsPeoplePage` from
`@hilos/vue/admin/analytics`; Chat and Binance mount it under the
`hilos_analytics_people` lazy page with a thin wrapper that supplies their
`HilosAnalyticsContext`. The React SDK exports `HilosAnalyticsPeoplePage` and
`HilosAnalyticsPeoplePageProps` from `@hilos/react/admin/analytics`; its props
require `{ context: HilosAnalyticsContext }`, supplied by a thin project
wrapper. Tasks and ecommerce-shop mount this optional page under the literal
`HilosPages.ANALYTICS_PEOPLE` lazy page entry. The Angular SDK exports the
standalone `HilosAnalyticsPeoplePage` from `@hilos/angular/admin/analytics`; its
required `context` input takes `HilosAnalyticsContext`, which Polls and
OnlineTesting supply from thin wrappers in their lazy People pages.

The Analytics Overview also requires project context in Vue, React and Angular.
Every project mounts it through a thin wrapper outside the context-free default
admin view map: Chat and Binance in Vue, Tasks and ecommerce-shop in React, and
Polls and OnlineTesting in Angular. People is not bound to Overview or Person,
and projects without this opt-in continue to use the rest of Analytics
independently.
For its data and privacy boundaries, read
[analytics.md](analytics.md#the-admin-section), *The Admin Section*.

### change_log — the Change Log section

1. Declare `HilosFeature::CHANGE_LOG` in `FEATURES`. It mounts no runtime row
   and does not depend on Backup.
2. Run `db:framework:pull` to bring the aggregate `change_log` unit into the
   project's primary migration track. Its current `create_hilos_change_log.sql`
   form uses `{{change_log_database}}` for the derived schema. Apply the generated
   pairs after provisioning it in step 3; never start a second migrator on
   connection 1.
3. Provision `<primary>-change-log` on the same server and grant the application
   access. Configure/connect index 1 through `ChangeLogDatabase` after the
   primary connection and schema, before `if ($initHilos)`, keeping primary
   active. Mount `init-change-log.sh` at
   `/docker-entrypoint-initdb.d/init-change-log.sh` and `provision-change-log.sh`
   at `/usr/local/bin/provision-change-log.sh` on each MariaDB service: local,
   test and cluster. The init script calls the same repeatable root script
   used to provision an existing ordinary MariaDB directory before Hilos starts;
   it creates/grants the journal without clearing either database. All existing
   cluster stands use one ordinary MariaDB server each. Their transition and
   the separate new directory for online-testing are in
   [change-log.md](change-log.md#where-the-journal-lives) and the demo READMEs.
   The shared `test:db:reset` recreates the pair when the feature is declared.
4. In the Docker application's `postMigration` hook, call
   `ChangeLogPartitions::ensureUnderClaim()` under the existing rollout claim.
   Startup initializes Hilos, validates the complete trigger-file set and runs
   the framework installer before releasing that claim.
5. Declare the Entity journal policies, apply test migrations and run
   `db:change-log:generate`. Commit generated trigger files; unchanged bodies
   keep their older valid-from headers. Generate the complete set before
   starting a daemon.
6. Register a thin `AbstractHilosChangeLogAgent` subclass and a monopolistic
   daemon under `HilosAgentType::HILOS_CHANGE_LOG`. Register thin subclasses of
   `AbstractHilosChangeLogDashboardPage`, `AbstractHilosChangeLogTablesPage` and
   `AbstractHilosChangeLogTablePage`. Bind the framework feed, tables and history
   tables respectively in `TABLES`, the project `TableContext` and `PAGE_TABLES`.
   The journal's six typed collection chains mount under `CHANGE_LOG`; the serving
   agent declares reads of the five trigger-written collections and receipt update
   ownership. Feed windows/counts and receipt/entry reads use the typed
   collections through `Hilos::$db->changeLogReceipts` and
   `Hilos::$db->changeLogEntries`. Overview, table summaries, noisy columns,
   table detail and history use `sectionOverview()`, `sectionTables()`,
   `sectionNoisyColumns()`, `sectionTable()`, `historyWindow()`,
   `historyCount()` and `historyCountBefore()` through the same facade
   collections. The bounds, immutable snapshots, connection handling and
   reading rights are in
   [Reading The Journal](change-log.md#reading-the-journal).
   With `CHANGE_LOG` enabled, this framework journal registration is enough for
   the leader lifecycle to start the `CLUSTER` + `LEADER` agent directly. Do not
   add it to the project's `INITIAL_AGENTS_START` list; this feature-specific
   hook is not a general auto-start registry.
7. In a Vue, React or Angular project, mount the section's three pages —
   `HilosChangeLogPage`, `HilosChangeLogTablesPage` and
   `HilosChangeLogTablePage` — from `@hilos/vue`, `@hilos/react` or
   `@hilos/angular` through thin wrappers carrying the project's connection,
   scopes and tracked actions; none of them is in the context-free default admin
   view map.

The schema, receipt, trigger and restore contracts live in
[change-log.md](change-log.md). All six demos activate the backend section.
Chat journals five framework tables, `hilos_moderation_rule` among them, plus
`bot`; tasks and polls also journal those five framework tables after declaring
`MODERATION`. Binance-btc-tracker, ecommerce-shop and online-testing each journal
only the other four framework tables. Chat and binance-btc-tracker mount all
three Vue pages of the section. The remaining
view mounts keep the leaf boundaries above. Where
Backup is already active, it collects the six journal verdicts from the journal
Entities mounted by `CHANGE_LOG`, under schema index 1. Do not register
`ChangeLogTablesWithoutEntity` for the journal; providers remain only for
genuinely unmapped tables. Activating the journal does not activate Backup; the
thin project bindings and database/DDL setup above remain required.

### moderation — the Moderation section

Framework-owned: the project activates the mechanism and supplies its own
kinds, never a copy of the moderator or admin engine. Read
[moderation.md](moderation.md) for the rules and current implementation status.
The activation order is below; steps 1 to 4, the table registrations and kind
Overview and Rules bindings in step 5, and the Vue root, Overview and Rules
mounts in step 6 are in the code. Add, Edit and Delete registrations and mounts
stay with their marked leaves:

1. Declare `HilosFeature::MODERATION` in `FEATURES`. This does not enable
   `PROFILE_PHOTO`.
2. Keep the framework kind directory, which `MODERATION_KIND_DIRECTORY` names
   by default, or point that constant at a project subclass retaining its
   parent entries and adding the project's kinds.
3. Run `db:framework:pull`: with `MODERATION` declared it brings the
   framework schema unit `moderation_rule` — the `hilos_moderation_rule`
   table the ORM mounts as `moderationRules` — into the project's
   migrations. Register the rules' single writer, the framework pair
   `ModerationRulesLibraryAgent` and its daemon, under
   `HilosAgentType::HILOS_MODERATION_RULES_LIBRARY` with
   `AgentPlacement::POLICY` and no project subclass. Do not invent a project
   storage path for rules.
4. Register the moderator's agent/daemon pair, `ModerationAgent` and
   `ModerationAgentDaemon`, under `HilosAgentType::HILOS_MODERATOR` with
   `AgentPlacement::POLICY` and no project subclass. Fold
   `ModerationSettingsCatalog::getCatalog()` into the project's settings
   catalog, and run the settings library pair beside it: startup reads the
   project's catalog, not the accessor's own fold of the same entries. The
   framework profiles `hilos.moderation.text` and `hilos.moderation.image` need
   no project declaration; a stand points them at its model with
   `HILOS_MODERATION_TEXT_URL` and `HILOS_MODERATION_IMAGE_URL`.
5. Register the framework browser tables in the project topology:
   `Hilos\Tables\Moderation\HilosModerationKindsTable::TABLE`
   (`hilosModerationKinds`) maps to
   `Hilos\Tables\Moderation\HilosModerationKindsTable::class`, and
   `Hilos\Tables\Moderation\HilosModerationRulesTable::TABLE`
   (`hilosModerationRules`) maps to
   `Hilos\Tables\Moderation\HilosModerationRulesTable::class` in `Hilos::TABLES`,
   under the matching `TableContext` keys. `ChatTableContext` declares matching
   key constants, imports the framework classes and lists their typed
   `@property-read` entries; its `configure()` mounts the registered tables.
   Register the section root page (`AbstractHilosModerationPage`) and bind
   `hilosModerationKinds` in `PAGE_TABLES`.
   Register the kind Overview with `AbstractHilosModerationKindPage`, mounted
   in `PAGES` by Chat's `ModerationKindPage` on `HILOS_INDEX`; bind
   `ChatTableContext::hilosModerationKinds` in `PAGE_TABLES`. Its route is
   `/hilos/moderation/{kind}` and its page key is `hilos_moderation_kind`.
   Register Chat's `ModerationKindRulesPage`, extending
   `AbstractHilosModerationKindRulesPage`, in `PAGES`, served by `HILOS_INDEX`;
   its route is `/hilos/moderation/{kind}/rules` and its page key is
   `hilos_moderation_kind_rules`. In `PAGE_TABLES`, bind both
   `ChatTableContext::hilosModerationKinds => []` and
   `ChatTableContext::hilosModerationRules => []`, so the page receives its
   selected kind metadata and own-rules windows. The browser table classes
   remain registered once under `TABLES` and mounted by `ChatTableContext`.
6. Mount the chosen frontend's SDK views through thin project bindings:
   Vue/chat mounts the root (`HilosModerationPage`).
   It mounts Overview with `HilosModerationKindPage` through the lazy Chat
   wrapper `views/Hilos/Moderation/ModerationKind.vue`, supplying
   `hilosModerationContext`.
   It mounts Rules with `HilosModerationKindRulesPage` through the lazy Chat
   wrapper `views/Hilos/Moderation/Rules/Rules.vue`, supplying the same
   `hilosModerationContext`; `App.vue` lazy-loads that wrapper for
   `HILOS_MODERATION_KIND_RULES`.
   Its Add dialog follows.
   Its Rules view mounts the Edit dialog.
   Its Delete dialog follows.
   React/tasks mounts the root, kind Overview and Rules pages through three
   lazy wrappers, using the framework's Profile name and Profile photo kinds;
   Add, Edit and Delete remain internal to the Rules page. The public
   `@hilos/react/admin/moderation` entry exports those three pages and their
   `Props` types, while the compatibility root exports only
   `HilosModerationPage` from moderation.
   Angular/polls mounts the root, kind and rules pages through lazy wrappers in
   `views/hilos/moderation/`; all three use the shared `hilosModerationContext`,
   and `app.ts` maps their routes to those wrappers.

The name window's framework wiring and `Checking your name…` line are separate
from mounting the admin section.
Profile photos in tasks are separate work. (not in the code yet — HIL-1644)
Profile photos in polls are separate work. (not in the code yet — HIL-1645)

Apply the [admin view mode](admin-view-mode.md) with each surface: the viewer
looks without mutation, with the framework controls and server refusal. Do not
generate against unbuilt class names: each marked leaf supplies its concrete
contract, and the detailed behavior stays in [moderation.md](moderation.md).

### a future framework feature (roles, …)

A new framework admin feature ships the same shape: a base page (subscribe +
actions), a base table (the engine), and either a framework-owned source or a
project-bound contract. Pick the recipe by which one it is:

- framework owns the data source → follow the **settings** recipe (catalog +
  register the `final` table + thin page + mount);
- data is project-owned behind a framework contract → follow the **hilos-users**
  recipe (generate the binding: entity / presence source + the abstract hooks +
  thin page + mount);
- a framework-owned section with no feature switch → activation is registering
  its page and table in the topology, and there is nothing to declare in
  `FEATURES` ([admin-features.md](admin-features.md), Mode 1). The first one is
  `hilos_maintenance`, and its recipe is:
  - a thin Overview page `MaintenancePage extends
    Hilos\Pages\Maintenance\AbstractHilosMaintenancePage` and a thin child
    page `MaintenanceCirclePage extends
    Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage`; both set
    `SUBSCRIPTION_AGENT_TYPE` to the project's hilos index agent;
  - `PAGES` — both page keys → their classes;
  - `TABLES` — `HilosVerifierCircleTable::TABLE` under the project's
    `TableContext` key, bound to `HilosVerifierCircleTable`;
  - `PAGE_TABLES` — bind the table to Circle in all six demo backends. The four
    React/Angular demos (`tasks`, `ecommerce-shop`, `polls` and `online-testing`)
    also keep its transitional Overview `PAGE_TABLES` entry; `chat` and
    `binance-btc-tracker` have removed that binding;
  - the Overview mount — `HilosMaintenancePage` from `@hilos/vue`, `@hilos/react`
    or `@hilos/angular` with the project's `{ connection, scopes, actions }`
    context under `HilosPages.MAINTENANCE`. Vue also mounts
    `HilosMaintenanceCirclePage` from `@hilos/vue/admin/maintenance` under
    `HilosPages.MAINTENANCE_CIRCLE`. React and Angular still render the circle
    table on Overview; Circle remains unbuilt in those SDKs, so its tab is hidden
    and a direct address returns `not_served` until HIL-1681 and HIL-1682;
  - `createHilosMaintenanceCircleTable(context, view, page)` scopes the table
    window, viewport, rendered fields and row focus to the selected page. Vue
    passes `HilosPages.MAINTENANCE_CIRCLE`; existing two-argument calls continue
    to target Overview. Circle add/remove actions belong to the Circle page, but
    dispatch does not require a Circle subscription, so the transitional Overview
    actions continue to work;
  - no line in `FEATURES`, and nothing more to migrate: the circle's table is
    already required of every installation that can freeze (HIL-1118).
  - manual maintenance needs no extra wiring: the index agent already serves
    the page and owns manual entry ([protected-mode.md](protected-mode.md),
    *Manual Maintenance*). To let an admin change its restart policy in Settings,
    merge `ProtectedModeSettingsCatalog` into the project's settings catalog;
    without that key, the default treats a restart as normal.
  A project that registers no page for the section answers its address with a
  404 (HIL-1090), and the dashboard draws no card for it.

If none fits — there is no framework base yet — the feature is not ready to
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

// bound: the generated subclass declares its browser sources and names its
// connections; reading the people, the presence defaults, and the merge stay in
// the base.
final class UsersTable extends Hilos\Tables\Users\AbstractHilosUsersTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [self::USERS_SOURCE, AppBrowserSource::RT_CONNECTIONS],
        BrowserTableConfigKey::ROWS => [
            self::USERS_ROW,
            // the connections row: userId as the row key, presence and
            // onlineSessionCount computed
        ],
    ];

    protected function presenceSourceKey(): string { return RtContext::connections; }
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

- the DB entity fields or migration shape — for hilos-users, the columns a
  project's subclass of `hilos_user` adds; the base fields are the framework's
  and not the project's to change;
- the RT connection/presence item shape consumed by the merge.

Signals, action DTOs, and routing for a framework feature ship with the framework
base, not per project — they are not generated here.

## Validation

Use `$hilos-testing-cli` to choose composer scripts. After generating, keep the
target project's admin e2e green and add coverage for the presence source of a
bound feature and for the project's subclass of `hilos_user`, where it has one.
The framework base classes named above are the contract the generated code
binds to; do not modify them to make activation fit.
