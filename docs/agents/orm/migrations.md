# ORM: Migrations

Migrations are versioned SQL files applied in order. No PHP migration classes — plain SQL only.

## File location

`backend/Database/Migration/Schema/` (project-level; a project has this one track, set by
`framework/backend/Core/Bootstrap/EntrypointPrelude.php` —
`Migration::setMigrationListPath($projectRoot . '/backend/Database/Migration')` and
`Migration::setMigrationName('Schema')`)

## File naming

```
001_create_users.sql
002_add_email_to_users.sql
003_create_events.sql
```

Prefix is a zero-padded integer. Applied in numeric order.

## Up/Down

Each migration file contains UP SQL. DOWN is in a separate file or not provided.

## CLI commands

```bash
php cli.php db:migration:up      # apply all pending migrations
php cli.php db:migration:down    # roll back last migration
php cli.php db:migration:status  # show applied/pending
php cli.php db:migration:retry   # retry failed migration
php cli.php db:migration:release <holder>  # release a rollout claim a dead holder left
```

See `cli/commands.md` for full list.

## Nodes that start together (HIL-1228)

Every node applies the pending migrations from its container watchdog on startup, so nodes
that start together on one database race for the same DDL. The race is settled in the
database itself: whoever changes the schema first takes the **schema rollout claim**, one row
of the framework table `hilos_migration_claim`, which `Migration::initialize()` creates beside
`migration`. The insert of that row is the only arbiter — a duplicate key on one server, a
certification conflict on Galera. `Migration::initialize()` creates one more framework table
there, `hilos_admin_view_mode_latch`, the row half of the admin view mode latch the start of a
daemon reads ([../architecture/admin-view-mode.md](../architecture/admin-view-mode.md)); unlike
the claim's, a restore does not empty it. And one more, `hilos_database_marker`, the name of the
database that the start of a daemon in a cluster reads and names to its peers
([../architecture/daemon-lifecycle.md](../architecture/daemon-lifecycle.md), *The database both
ends read*, HIL-1206). A restore does not bring it either: the target keeps the marker it had, and
a target that had none is left without one — the marker is the database's name, not its content.

- **A row, not `GET_LOCK`.** The server's named lock would be released by the server when its
  holder dies, but MariaDB Galera refuses `GET_LOCK` outright (since 10.6.13 / 10.11.3), and a
  multi-primary cluster on MariaDB is Galera: the node would not start at all there. One
  behavior on every topology outweighs the cleanup the row costs, listed below.
- **Taken for migrations or startup completion.** Without an `afterRollout` hook,
  `Migration::migrateUp()` takes no claim when the database is already at the code's level.
  With the hook it always takes the claim, runs pending migrations and then the hook before
  release. A journal-enabled watchdog uses this for partition preparation, Hilos
  initialization and trigger installation. The level is read again under the claim — another
  holder may have rolled the schema out meanwhile — and the claim is given up on success or
  failure. `db:migration:up`, `:down`, `:retry`, a restore and `test:db:reset` take the same
  claim; `recordAppliedLevel()` does not. A restore takes the primary claim again for journal
  trigger installation, after anonymization and the settlement of restored pending deliveries.
- **Waiting has no deadline.** A waiting process reads the row once a second and names the
  holder in its log on the first poll and every 30th after it: `Waiting for the schema rollout
  claim held by <holder> since <claimed_at>`. Only the row being gone lets it go on.
- **A migration an earlier holder left failed is refused by name**, not retried: `Migration N
  is marked failed by an earlier run and its SQL may be half applied: fix the schema by hand,
  then run db:migration:retry N`. The watchdog exits on it like on any migration failure.
- **Whose row comes back.** A node's start writes its `CLUSTER_NODE_ID` (its host name when
  that is empty) and takes a row with its own name back on the next start — the previous
  start is dead by then, and the log says `Taking back the schema rollout claim this node's
  previous start left at <claimed_at>`. Every other process writes that name plus `:<pid>` and
  never takes its own row back: two of them are alive on one host at once.
- **Anything else is the operator's.** A holder that never comes back leaves the others
  waiting; `db:migration:release <holder>`, run in a waiting node's container, removes the row
  only while that holder still has it, and `db:migration:status` prints who holds the claim.
- **A restore empties the table** before its migrate step: an archive may carry the row of a
  process that held the claim when the backup was taken.
- **A restore never migrates the journal connection.** Its six tables are imported alongside
  the data, and their schema moves through the primary database's migration track. Running the
  migrator on the journal would create `migration`, `hilos_migration_claim`,
  `hilos_admin_view_mode_latch` and `hilos_database_marker` there.

## A track the database cannot follow is refused (HIL-1238)

The migrator keys a migration by its number and applies only what lies above the database's
level — the highest `migration` row with `failed = 0`. Two kinds of file therefore used to stay
unapplied without a word, and both are refused now, by name:

- **A number taken by more than one file** — two up files, or two down files, of one number.
  An up and a down file of one number are a pair, not a duplicate. Refused on every database,
  a fresh one included:
  `Migration track Schema: number 63 is taken by more than one file (063_a.sql, 063_a_down.sql,
  063_b.sql, 063_b_down.sql); give each file a number of its own` — every such number in one
  message, every file of it in name order.
- **A file below the level that was never applied** — a number taken on a branch while a
  higher one landed first:
  `Migration track Schema: 059_create_hilos_file_variant.sql is below the database's level 61
  and was never applied; renumber it above 61, with SQL that holds on a database that already
  has it (IF NOT EXISTS)` — several files read `… are below … were never applied; renumber them
  …`. It is not applied out of order instead: it was written for a database without the
  migrations above it, and one history would then build two schemas.

Where the check stands:

- **Every rollout, before "nothing to apply".** `Migration::refuseInconsistentTrack()` runs in
  `migrateUp()` right after `initialize()`, so a node's start, `test:db:reset` and the migrate
  step of a restore all pass through it, and a database already at the code's level is checked
  too — that is exactly the database with a hole. Duplicates are judged first and without the
  database; the rows and the level are then read by one query.
- **Outside the rollout claim.** It takes no claim and writes nothing: a hole is a property of
  the track and the database, and a rollout of the same track adds none.
- **`db:migration:up` checks before it prints the status**, so it cannot answer "All migrations
  are up to date!" over a hole. `--force` does not lift the refusal: it is about failed
  migrations.
- **A rollback or a retry** that looks a file up by its number refuses a number with two files
  of that direction, instead of taking the first by name.
- The refusals extend `DatabaseException`: a node's watchdog exits with `Docker migration failed
  on startup: …`, the CLI prints `✗ Database Error`, a restore fails after its import as on any
  migration failure.

**The lowest row.** A file counts as skipped only when the `migration` table has no row for it
**and** its number lies strictly between the table's lowest row and the level. A rollout writes
one row per file, in order, so above the lowest row every applied file has its row. Below it
lies history a schema archive restored before this rule declared with a single row at its own
level; such a database is not refused, and stays blind to a wrong number below that level. A row
with `failed = 1` counts as present — it is `db:migration:retry`'s business, not this check's.

**A restore of a schema archive writes a row for every file of the track up to its level**
(`Migration::recordAppliedLevel()`), and for the level itself, so the restored database is judged
afterwards exactly like one built from scratch.

**The cure is in code, never in the table.** Renumber the file above the level, with SQL that
holds where it already ran under its old number — a database built from scratch in between ran
it in order (`CREATE TABLE IF NOT EXISTS`, `ADD KEY IF NOT EXISTS`). There is no command that
marks a migration applied: the same hole stands on every database at that level.

## A Key Onto A Table That Already Holds Rows

A migration that adds a foreign key to a table that already holds rows cleans, in
the same file and right before the `ADD CONSTRAINT`, every row the key would
refuse: a row of the table that points at nothing is deleted; a column that only
mentions the parent inside somebody else's row is set to `NULL`, and the row
stays. The migration never stops to leave the cleanup to an operator.

The rows it removes are dead already: nothing reaches a row whose parent is
gone. And the migration does not run on a live database only. A restore of an
archive taken before the key imports the old rows first and migrates after
(`BackupRestorer`), so the database is already overwritten when this file runs,
and a single refused row would fail the restore halfway.

The owner's frame (2026-10-03, the HIL-1202 interview), in the owner's words
rendered in English: *the migration cleans them itself, right before the key —
and write it down, so similar cases do the same.*

Examples: `demo/chat/backend/Database/Migration/Schema/051_add_passkey_credential_identity_foreign_key.sql`
(HIL-1111) and the person keys of HIL-1202 (`NNN_add_person_foreign_keys.sql`
in each demo).

## Framework Tables In A Project (HIL-1326)

A framework table reaches a project through `db:framework:pull`, as numbered SQL up/down
pairs in the project's own `Schema` track. The framework writes each change once; the
command creates the project copies. Keep framework SQL separate from project SQL. What
the project adds to a framework table — a column ([inheritance.md](inheritance.md),
*the project's columns arrive by a project migration*), an index, its own rows moved into
it — remains a separate project migration.

### Schema units and immutable changes

The sources live under `framework/backend/Database/Migration/`:

- `FrameworkSchemaCatalog` defines stable schema units, their tables, stub pairs,
  dependencies and connections. Selection uses `FeatureRegistry` requirements:
  `requiredDbTables`, metadata-only `requiredSchemaUnits`, and feature dependencies.
  It also includes core `user`/`session` and the four bootstrap-owned units
  (`migration`, `migration_claim`, `admin_view_mode_latch`, `database_marker`).
  `verifier_circle` is selected when the facade has a runtime factory, independently of
  `BACKUP`. Bootstrap-owned DDL remains with migration initialization, without stubs.
- `Stub/create_<table>.sql` and its `_down` hold the current form. The `analytics`
  and `change_log` units each keep their aggregate stub intact. Journal SQL travels in
  the primary track with `{{change_log_database}}` targeting the derived database;
  there is no migrator on the journal connection.
- `catalog.json` fixes the genesis stub hashes. `FrameworkMigrationCatalog` reads it
  and the immutable `Change/<id>/change.json` groups with their plain up/down SQL parts.
  A part belongs to one schema unit; a shared table stays one unit even when several
  features require it. Selection is the union of units, not the name of an SQL folder.
- A unit added after genesis, a new framework table, is a new unit in
  `FrameworkSchemaCatalog` with its stub pair, opened by exactly one change part with
  `"introducesUnit": true` at the head of its explicit chain; genesis stays as it is.
  The introducing part's `afterStub*Sha256` is its first snapshot, the last part of its
  chain the current one. A unit outside genesis without that part — whatever stub hashes
  its ordinary parts carry — a second introducing part, one that is not the head of the
  chain, and an introduction of a genesis or bootstrap-owned unit are refused when the
  catalog is read. A project that selects the unit receives its current stub as an
  `install` pair recording all its parts; origin headers carry no new field.

For the next framework table change:

1. Append an immutable change group with a UTC timestamp, ticket key and slug. Declare
   one part per affected unit, exact `requires` dependencies and the resulting stub hashes.
   Follow the [change-source format](../../../framework/backend/Database/Migration/Change/README.md)
   for the manifest, origin and canonical UTF-8/LF hashing; do not invent another format.
2. Update the current stub pair once, together with the Entity metadata and personal-data
   verdict. Keep the [journal trigger files](../architecture/change-log.md#triggers-are-files)
   current for every affected project. Do not rewrite genesis, published changes or old
   project migrations. A stub differing from genesis or its last declared change is refused
   as `Framework stub drift for unit <unit>; declare its immutable change part`.
3. Run pull for the projects that carry the unit, review and commit the generated pairs,
   then apply them through the ordinary project migrator. Do not hand-write the same
   framework delta into each project.

Parts are identified by `(changeId, unit)` with explicit dependencies. A later timestamp
does not prove coverage. A newly installed unit copies its current form and records the
parts that form already includes; it does not replay past ALTER statements. Existing
copies keep their fixed body hashes when the current stub changes.

### Pull sources before deployment

```bash
php cli.php db:framework:pull  # current project's configured Schema track and facade
php cli.php db:framework:pull --projects=/path/to/framework-projects.json
composer db:framework:pull    # repository root: all six demos
```

`FrameworkMigrationPullCommand` is a database-free `CLI_SOURCE_WRITE` command. It writes
versioned source files, without applying SQL or contacting a daemon. The repository
wrapper `scripts/framework-migrations.php` uses the same `CliManager` and command with
`framework-projects.json`. An explicit registry is a JSON object with a `projects` list
of `{root, facade}` entries; roots resolve relative to that file. The registry loads
project PSR-4 paths from `composer.json`, without `initEnv`, DB initialization or dependency
installation. See [CLI commands](../cli/commands.md#migration-commands) and the
[execution-site rule](../cli/command-execution.md#todays-departures) for that boundary;
applying SQL retains the existing offline-write guard.

`FrameworkMigrationInstaller` preflights all targets before publishing SQL, then replans
under each track's exclusive `.hilos-framework.lock`. It appends new numbers after every
occupied number, including orphan directions, and never replaces an existing destination.
A complete, verified origin pair is skipped on repeat. After an interrupted publication,
repeat the command: it completes only an exact matching half-pair at its existing number;
an unknown or damaged half-pair is refused. Publication is per file and track, without a
transaction across projects. Failures return nonzero and report the files actually created;
keep that report and complete the partial result before deployment.

Both directions carry the same first-line `-- hilos-framework: ` JSON header, format 1,
parsed by `FrameworkMigrationOrigin`. Kinds are `adopt`, `install`, `change` and `baseline`.
It records exact units, part identities and hashes, plus the two SQL body hashes excluding
the header. Coverage is the verified set of parts in these actual files, never a maximum
change id. Duplicate installation or part coverage is refused. The full field and hashing
contract belongs to the change-source format linked above.

### Source coverage and applied coverage are separate gates

`EntrypointPrelude::run()` calls `FrameworkMigrationStartupGuard::prepare()` after env
and track configuration, before persistence initialization. Under the shared source lock
it verifies the actual origin headers, body hashes, complete pairs, selected units and
exact change coverage. A missing unit, part or pair direction names what is missing:
`Framework migration <unit, part or file> is missing from this project; run db:framework:pull`.
It neither copies files nor applies SQL.

At the entrance to `Hilos::init()`, before typed application reads, the applied gate checks
every prepared origin file's exact number against successful rows of the primary
`migration` table. A higher successful level does not cover a missing or failed row.
An absent migration table supplies no successful rows, without creating it; other read
errors still fail. The refusal is `Framework migration <file> is not applied; run
db:migration:up`. Docker can run its normal `migrateUp()` between preparation and Hilos
initialization. The CLI uses `initEnvironment()` alone and does not arm these startup
gates, so pull and migration repair stay reachable. Startup does not repair sources or
waive either gate.

`TableNotActivatedException` still names the missing `create_<table>.sql` stub and says
to copy it. For a catalog-initialized project, use pull and then apply the generated
migrations; do not take that older wording as a second source-delivery recipe.

### Initial adoption and new-project baselines

The catalog was connected to the six reviewed demos by an appended no-op `adopt` up/down
pair in each project. Existing migration history stays unchanged. Adoption records only
the verified framework composition already present in that project, not every known unit.
Binance-btc-tracker, ecommerce-shop and online-testing also received separate `install`
pairs for the two missing AUTH units, `oauth_provider` and `legal_acceptance_export`;
chat, tasks and polls already had those tables. Adoption and installation together cover
the selected composition without overlap. Subsequent changes use pull.

An unknown legacy external track is refused: `This project has no framework catalog
origin; only projects initialized for this catalog are supported`. Pull does not infer an
adoption from table names or certify an external schema. There is no general legacy
adoption procedure here.

A new project declares its framework features in `Hilos::FEATURES`, creates an empty
`backend/Database/Migration/Schema/` track, and runs the baseline command before deployment:

```bash
php backend/Bootstrap/cli.php db:framework:baseline
```

`FrameworkMigrationBaselineCommand` is database-free and uses the current
`Migration::trackPath()` and `Hilos::appClass()` only. It writes one composite pair,
`001_framework_baseline.sql` and `001_framework_baseline_down.sql`, from the selected
framework units' catalog-verified current stubs in canonical UTF-8/LF form. Selection
includes core `user` and `session`, units required by the declared features, and
`verifier_circle` when the facade has a runtime factory. The origin records the exact units
and selected change parts, including bootstrap-owned units; those units contribute no DDL.
The up body follows unit dependency order and the down body reverses it. Both files carry
origin metadata and exact body hashes.

On first use, the numeric `Schema` track must be empty. A completed, verified baseline is
skipped on repeat without rebuilding it, even if the feature declaration or catalog has
since advanced. If publication stopped after one direction, a repeat completes it only when
that file exactly matches the expected baseline. Any other occupied track on first use or
damaged baseline is refused. Publication uses the shared exclusive source lock and
no-replace writer described above. Commit the pair, then apply it through the ordinary
`db:migration:up`; its rollback is the ordinary guarded `db:migration:down`. The baseline
command never initializes the application or database or applies SQL. Use
`db:framework:pull` for later framework changes or newly selected features. This creates
sources for a new project; it does not certify or adopt an unknown legacy schema.

**Why not a migration track of the framework's own.** Considered, and set aside by the owner
on 2026-10-08. A track of its own needs a track in the `migration` journal, two levels in
every reader of the level — the archive sidecar, the dump marker, the restore gate, the
migrate step after an import, `--migration-index`, the stamp of the journal trigger files,
the migration receipt — and a move of every project. The owner's frame, in the owner's words
rendered in English: *such a switch could only be done once the first version of the
framework is finished — earlier it is inconvenient, later it is problematic.* So the
`migration` journal, the level, the archives, the restore gate, the journal triggers and the
receipts keep one track per database; the copies stay, and the command brings them.

**Feature composition.** A table smaller than its feature (`hilos_oauth_provider`,
`hilos_legal_acceptance_export`) comes with the feature whole and may stay empty when the
project does not use it. Source selection follows the requirements above; turning a
feature off does not remove previously installed tables or rewrite migration history.

## Seeds

Seeds populate initial data. Located in `backend/Database/Migration/Seed/`.

```bash
php cli.php db:seed:apply 001
```

## Schema check

`DbSchemaStatusCommand` (`db:schema:status`) checks if the DB schema matches expected structure.

## Important rules

- Never modify an already-applied migration file — create a new one instead
- Migration runs in transaction where possible
- Test migrations in test environment before applying to production
- A foreign key onto a table that already holds rows cleans the rows it would
  refuse in the same migration — see *A Key Onto A Table That Already Holds Rows*.
- After schema change: update corresponding `Entity` class fields to match
- A new table needs a `_pii` verdict on its Entity — an empty column map when it
  holds nothing personal — or a restore that requires anonymization refuses before
  it imports anything. A new column needs naming too: in `_pii` when it holds
  personal data, in `_piiNotPersonal` when it does not. In a project that carries
  backup the cost arrives sooner than a restore: the daemon does not start while
  anything the live schema holds carries no verdict
  ([../architecture/backup-anonymization.md](../architecture/backup-anonymization.md))
- A migration that changes a table under the change log regenerates that table's
  trigger files with `db:change-log:generate` in the same commit. A node will not
  start on a trigger file that disagrees with what the generator writes today; see
  [../architecture/change-log.md](../architecture/change-log.md).
- Before incompatible DDL on a journaled table (including DROP/RENAME of a column),
  finish all migration DML on that table while the old triggers still work. After
  the DDL, do not write that table again in the migration batch before final trigger
  installation. Commit the new generated trigger files with the migration. If DML
  reaches a broken old trigger, keep the normal failed-migration refusal; do not
  disable journaling or catch the error to let the write pass.

## Test reset

```bash
php cli.php test:db:reset  # DROP → migrate → seed (test env only)
```
