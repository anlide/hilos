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
the claim's, a restore does not empty it.

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
