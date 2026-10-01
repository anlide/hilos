# ORM: Migrations

Migrations are versioned SQL files applied in order. No PHP migration classes — plain SQL only.

## File location

`backend/Database/migrations/` (project-level, configured in Bootstrap)

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
- **Taken only when there is something to apply.** A database already at the code's level is
  read without the claim and takes none. Otherwise the level is read again under the claim —
  another holder may have rolled the schema out meanwhile — and the claim is given up whether
  the rollout succeeds or fails. `db:migration:up`, `:down`, `:retry`, a restore and
  `test:db:reset` take the same claim; `recordAppliedLevel()` does not.
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

## Seeds

Seeds populate initial data. Located in `backend/Database/seeds/`.

```bash
php cli.php db:seed:apply 001
```

## Schema check

`DbSchemaStatusCommand` (`db:schema:status`) checks if the DB schema matches expected structure.

## Important rules

- Never modify an already-applied migration file — create a new one instead
- Migration runs in transaction where possible
- Test migrations in test environment before applying to production
- After schema change: update corresponding `Entity` class fields to match
- A new table needs a `_pii` verdict on its Entity — an empty column map when it
  holds nothing personal — or a restore that requires anonymization refuses before
  it imports anything. A new column needs naming too: in `_pii` when it holds
  personal data, in `_piiNotPersonal` when it does not. In a project that carries
  backup the cost arrives sooner than a restore: the daemon does not start while
  anything the live schema holds carries no verdict
  ([../architecture/backup-anonymization.md](../architecture/backup-anonymization.md))

## Test reset

```bash
php cli.php test:db:reset  # DROP → migrate → seed (test env only)
```
