# Change Log (The Database Writes It, The Entity Declares It)

Read this before declaring a table journaled or placing a column of a journaled
table; writing to the database from a new channel — a page action, an agent
without a person, a CLI command, a scheduled job or a migration — or handing a
write to another agent; changing how a node installs triggers or adding a trigger
of any kind; touching the backup dump or the restore order; or reading the journal
for a screen.

The change log lets an administrator see who did what. It is not a store of
personal data. Phase 1 is HIL-351; the archive and custom project triggers belong
to phase 2, HIL-1413.

Phase 1 is being built by HIL-1445 through HIL-1465. A sentence whose behavior is
not built yet carries the marker of the leaf that will land it. That leaf clears
the marker in the same commit, by
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

Four shared names are contracts here: the receipt variable, the journal database
name, the trigger file format and the channel values. The owning leaf's interview
may rename one and must update this page in the same commit. All other names
belong to their leaf, not to this page.

## Core Rule

`JournalTriggerGenerator` generates service triggers — `AFTER INSERT`,
`AFTER UPDATE` and `AFTER DELETE` on each journaled table.
`JournalTriggerInstaller` installs them at node startup after migrations.

The Entity declares which tables are journaled; nobody switches them on or off.
The screens only show what is declared (not in the code yet — HIL-1461).

For attribution a journal row carries only the receipt number; the receipt says
who acted, on whose behalf and through what.

The journal lives in its own database on the same server.

Triggers are files, and the database holds exactly what those files say.

## What An Entity Declares

`Entity::_journaled = true` puts its mounted table under the journal. The inherited
default is `false`. `Entity::_journalSecrets` is a list of column names, and
`Entity::_journalNoise` maps each column to a non-empty reason the screen shows.
Each live column of a journaled table has exactly one mode:

| Mode | What is written | Where it comes from |
|---|---|---|
| Value | Old → new | Explicitly in `_piiNotPersonal` and not binary |
| Personal | Only the fact that it changed, no value | The Entity's `_pii`; all remaining columns of a `PURGE` table |
| Secret — hashes, keys, codes | Nothing, neither value nor fact | `_journalSecrets` |
| Noisy — last activity, sign-in time, counters, the `updated_at` stamp | Nothing; an update touching only noisy columns is not written at all | `_journalNoise`, with a reason for each column |
| Binary | Only the fact | SQL `binary`, `varbinary`, `blob` or `bit` family, after a non-personal verdict |
| Record key | The row's identity in the journal, not a field of it | The live primary key |

A column left without a mode keeps the node from starting, naming the table and
column, just as the personal-data coverage gate does. The same refusal catches a
missing primary key, a personal/secret/noisy key, an unknown secret or noise column,
their overlap, or an empty noise reason. It reads the live SQL schema, including
columns the ORM does not map, when the journal connection is configured. A table
with no Entity cannot opt in through this declaration.

The framework tables under the journal are `hilos_user`, `hilos_identity`,
`hilos_second_factor` and `hilos_setting`. A secret is declared separately from
its anonymization strategy: `NULLIFY` can describe data that is not a secret,
and `hilos_second_factor` is purged whole. Its key still identifies a row, while
every non-key, non-secret, non-noisy column is journaled only as a fact.

In a demo, journal what a person changes by a decision, not the stream: chat bots
are journaled, messages are not; polls are journaled, votes are not; quotes in
binance-btc-tracker are not (not in the code yet — HIL-1453).
The exact table list per demo and the feature's activation shape belong to
HIL-1453. The column mode for a custom trigger belongs to phase 2, HIL-1413.

## What A Trigger Writes

- An insert records the fact that the row was created; its values are in the row
  itself.
- An update records only columns that actually changed, old → new; a personal
  column contributes only the fact, a secret contributes nothing, and an update
  of noisy columns alone produces no journal row.
- A delete records a snapshot without personal or secret values, keeping the
  column modes above.
- Values follow the real column type in the live database — the ORM does not
  know lengths: numbers, short strings and dates fit in the journal row; long
  text and JSON go into a neighboring journal table.
- A bridge insert or delete is a create or delete in the bridge table's own
  history. Showing the link in the history of both ends begins with the first
  journaled bridge table (not in the code yet — HIL-1453).
- A write without a receipt is allowed and produces a journal row with a NULL
  receipt number. The section reader returns one feed row per changed record
  and includes it in the table history. The feed's core view model labels its
  actor “Unknown”; the screen explains that the change was made past the
  application (not in the code yet — HIL-1459).

The journal row commits and rolls back with the write it describes: a trigger
runs inside its statement's transaction, and both databases are on the same
server.

## The Receipt

One action has one receipt: who is at the keyboard, on whose behalf, the session,
the channel, the action, the agent, the source and the time. The application
inserts it on the primary SQL connection before the handler or migration file.

Under impersonation the administrator is at the keyboard and the session's
person is the one on whose behalf the action runs. That is already the session's
answer: `Session::userAtKeyboard()` in
`framework/backend/Database/Object/Item/Session.php`.

| Channel value | Who | Agent | Source | Implementation |
|---|---|---|---|---|
| `web` | The person; both people under impersonation | The page's agent | The session | `PageSignalRouter` around the accepted synchronous handler |
| `migration` | Empty | None — the migration runner | The migration file | `Migration` around one SQL file |
| `agent` | Empty, shown as “System” | That agent | The agent itself | (not in the code yet — HIL-1450) |
| `cli` | Empty | The executing agent | The command, without the values it was given | (not in the code yet — HIL-1455) |
| `cron` | Empty | The job's agent | The job and its time | (not in the code yet — HIL-1456) |
| `mcp` | The person | — | The tool | Outside phase 1; when the MCP server arrives, HIL-356 |

The CLI command channel has no person by design; see
[Who may call a command — nobody is asked](command-server.md#who-may-call-a-command--nobody-is-asked).

The action is the name the server knows — `bot.update`, `settings.set` — not the
button's label.

Set `@hilos_receipt` before the write and clear it afterward:

```sql
SET @hilos_receipt = <number>;
-- The write runs here.
SET @hilos_receipt = NULL;
```

A worker shares its connection among agents and reconnects silently. Leaving a
receipt number on it would attribute the next write to the wrong person. An
empty variable means an empty receipt number in the journal. `Database::connect()`
also installs the active number on a replacement primary link before a replayed
statement; it never sets it on the journal connection. A failed clear closes
the primary link, so the next handler cannot inherit its value.

A receipt under which nothing journaled was written is deleted after the scope,
including a no-op or a handler error. Cleanup checks for journal rows by receipt
id and is safe to repeat. A process that dies before cleanup can leave an empty
row; the feed requires a journal row. Physical orphan cleanup must
wait for the handover lifetime in HIL-1454, so a recipient agent can still write.

A web scope starts after the action guards and ends before the success or deferred
ack. It stores the acting administrator and impersonated person separately, and
stores only the session number as source. A migration scope covers one up, down
or retry SQL file. Files before the receipt table exists run without one. A file
that creates or drops that table also runs without one, including a retry of its
partially applied creation: it cannot create a receipt in a table it is building
or removing. The migration's failed marker stays outside the scope.

A page action whose write is handed to another agent keeps one receipt with the
person and the writing agent, including when the recipient is on another node:
the journal database is shared and the number means the same thing there
(not in the code yet — HIL-1454).

An agent that writes later on its own, on a timer, uses its own receipt with the
`agent` channel (not in the code yet — HIL-1454).

When a person has been erased, the soft reference keeps their number. The
section reader returns the current name when the row still exists and
`Deleted user #N` when it does not. The screen presents that label
(not in the code yet — HIL-1460).

## Where The Journal Lives

The journal has its own database on the same MariaDB server — a trigger cannot
write to another server.

Its name is the main database's name plus `-change-log`: `hilos-demo-chat` becomes
`hilos-demo-chat-change-log`, and `hilos-framework-test-1` becomes
`hilos-framework-test-1-change-log`.

The name cannot be one constant shared by all installations: integration tests
have [a database per piece](../testing.md), named by
`scripts/framework-pieces.php`. The separator is a hyphen because an underscore
in a database name in `GRANT` is a wildcard for one character.

The journal has no foreign keys onto data rows: otherwise deleting a row would
either be refused or erase its history. The four append-only tables have monthly
partitions over `created_at` in UTC, with `p_future` catching writes after the
prepared window. The project migration creates `p_future`; before `Hilos::init`,
chat's Docker bootstrap extends the current month and the following 24 under the
primary database's `hilos_migration_claim`. It runs no DDL when the window is
already complete. A node that runs past it keeps writing to `p_future`; its next
bootstrap reorganizes that partition and moves those rows into their months.

A receipt refers to a person softly, without a foreign key. MariaDB 11.4 does not allow foreign keys on
partitioned tables, and a partition brought back from an archive could name a
person who has been erased. See
[Foreign Keys Onto The Person](people-table.md#foreign-keys-onto-the-person).

The six tables are two stable dictionaries (`hilos_change_log_table`,
`hilos_change_log_field`) and four append-only tables: receipts
(`hilos_change_log_receipt`), one row per changed record (`hilos_change_log`),
one row per changed field (`hilos_change_log_change`), and long values
(`hilos_change_log_value`). The latter four have composite `PRIMARY KEY
(id,created_at)`. No foreign keys point at a partitioned table. The field
dictionary alone has a foreign key to the table dictionary. The receipt carries
soft actor, subject and session numbers; the log carries a canonical record key
and nullable hash. Field rows distinguish inline, fact-only and long values;
their presence flags distinguish an absent side from SQL NULL. The new verdict
for every column is `ChangeLogTablesWithoutEntity` on connection 1.

A fresh chat local or test volume runs `init-change-log.sh` and grants the app
access to exactly the derived database. An existing local volume needs the
repeatable root bootstrap before the new code starts:

```bash
docker compose -f demo/chat/docker/docker-compose.local.yml exec mysql-local \
  /usr/local/bin/provision-change-log.sh
```

An external MariaDB operator does the same two statements, with the actual
main name, app user and host. Escape `_` and `%` in the database part of the
`GRANT` identifier because MariaDB treats them as patterns there:

```sql
CREATE DATABASE IF NOT EXISTS `<main>-change-log`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `<escaped-main>-change-log`.* TO '<app-user>'@'<app-host>';
```

Without the schema or its grant, chat's connection 1 refuses startup and names
the needed database. Migration 085 in chat's primary track creates the six
tables in that schema and drops the April tables from the primary database only
when all four are empty; down checks every new table before restoring the April
form. Applied migration 016 stays unchanged. `test:db:reset` recreates the pair
and prepares partitions. Phase 1 retains rows forever; archiving and retention
belong to HIL-1413.

The backup creator dumps both configured connections. It records the primary
migration level for the derived journal connection as well: asking the migrator
for a level on connection 1 would create its framework tables there. HIL-1451
owns the restore policy for the pair; a dump alone does not settle it.

Only triggers write journal rows. The `hilos_change_log` agent reads them for
the section through `ChangeLogSectionReader`. It is one cluster agent in a
monopolistic worker, and the reader uses raw SQL: Entity models only the primary
database, while the journal lives in a separate one.

**The April placeholder is not the model.** Its remaining traces are:

- `demo/chat/backend/Database/Migration/Schema/016_create_hilos_change_log.sql`;
- its temporary compatibility verdicts in
  `framework/backend/Database/Schema/FrameworkTablesWithoutEntity.php` until HIL-1451.

That shape describes a person number on every row, retention cleanup,
`track_values`, foreign keys, and an administrator's dry run. It is superseded,
not a worked example to copy. The framework stub now holds the six-table form;
the receipt scope replaces the old attribution shape.

## Reading The Journal

Read the journal for admin screens only through the cluster's
`ChangeLogSectionReader`. It runs SQL on the primary connection with qualified
journal table names and restores the caller's active connection afterward.
The reader returns typed values, not browser frames. Its feed is nonempty
receipts plus one row for each journal entry without a receipt; table history
includes both. Windows use a complete time-and-row anchor and carry at most 50
rows. Counts stop at the table count ceiling plus one, so a caller can show a
bounded count without scanning an unbounded result into memory.

The reader fetches current person names for the selected window and keeps the
number with each label. It exposes live table names and column policies without
exposing journal dictionary row numbers. A service trigger's valid-from
migration comes from the header of its project SQL file, never from the SQL
body sent to a browser.

The framework tables `hilosChangeLogFeed` and `hilosChangeLogHistory` serve
the feed and one table's history through that reader. They are frozen windows:
a filter, search, sort, page change, or reopening the page asks for a fresh one;
journal writes do not stream into an open window. A period starts at the time
the server reads the request. Both tables hide person names from an admin-view
mode viewer and search only by person number there. Numbered pages skip from
the nearer edge of an exact set, while a count above the ceiling can only skip
from the start. Their inline row slots have a `rowKey` but no `id` field: an
`id` would make the frontend normalizer mistake the slot for an entity and
lose the other row fields.

## Triggers Are Files

As with procedures in hleb's `main/App/Routines`, each trigger has its own file
with the latest body; its history is the file's `git blame`.

The trigger names are `hilos_cl_<table>_after_insert`,
`hilos_cl_<table>_after_update` and `hilos_cl_<table>_after_delete`; the file is
`<trigger name>.sql`, for example `hilos_cl_hilos_user_after_update.sql`.
MariaDB's 64-character identifier limit leaves
42 characters for the table name. The longest table name with an Entity today is
`hilos_second_factor_backup_code`, at 31 characters.

The first line is `-- valid from migration #<N>`: N is the migration from which
this body is valid.
When a table leaves the journal, keep a tombstone — the same file, the same
header and `DROP TRIGGER IF EXISTS`.

In the body, use `{{change_log_database}}` for the journal database name; do not
spell out one installation's name in the SQL. The installer substitutes the
quoted derived database name only for execution and live-catalog comparison.

The files live in the project's `backend/Database/Migration/Triggers` directory,
including triggers on framework tables. `JournalTriggerGenerator` reads the Entity
chain mounted by the project and its database's real column types; the migration
number belongs to the project. Framework migration stubs are copied into numbered
project migrations.

Only `db:change-log:generate` writes service trigger files. Run it after applying
project migrations; it refuses pending or failed migrations, a mismatched live
schema, missing journal tables and unsupported column types. It writes changed
files atomically and leaves unchanged files, including their older valid-from
headers, alone. It does not install SQL in the database.

For a project with the journal connection configured, `DockerApplication` runs
this sequence before starting a daemon, worker or socket:

1. Connect the databases and take the primary `hilos_migration_claim`.
2. Apply project migrations.
3. Run `postMigration`; chat prepares journal partitions through
   `ChangeLogPartitions::ensureUnderClaim()`, without taking the claim again.
4. Initialize Hilos so the generator reads the project's mounted Entities.
5. Validate the complete trigger-file set against `JournalTriggerGenerator::plan()`.
6. Read every trigger in the primary database, reconcile it and verify the result.
7. Release the claim, including on failure, then start the daemon watchdog loop.

`Migration::migrateUp(afterRollout: ...)` holds that one claim through steps 3–6
even with no pending migrations. Callers without the hook keep their existing
no-pending fast path. Projects without the journal connection keep their former
startup sequence. The claim is described in
[Nodes that start together](../orm/migrations.md#nodes-that-start-together-hil-1228).

Before any trigger DDL, the installer checks all files: the complete three-event
set and tombstones, exact names, the first-line header with N no later than the
applied migration level, and the exact generated CREATE or DROP statement. An
unchanged body may keep an older N; the header does not make it drift. The
installer never rewrites source files. It refuses a missing or extra SQL file,
a body differing from the generator, or any live primary-database trigger
without a file, and names the offending files or triggers. Phase 1 admits no
custom triggers, whatever their prefix.

A matching live trigger stays untouched. An absent trigger is created, a changed
one is dropped and recreated, and a tombstone removes its old trigger if present.
The comparison reads table, event, AFTER timing and the statement body from
`INFORMATION_SCHEMA.TRIGGERS`; server-added DEFINER and SHOW CREATE formatting
are not drift. A final catalog read must match the active files exactly and
contain none of the tombstones. MariaDB does not roll back trigger DDL: a failure
names the trigger and refuses startup; the next start safely completes the set.
The unused `Migration::applyRoutines()` and its path configuration are removed;
existing Routines files are not executed by this mechanism.

A migration changing a journaled table regenerates that table's trigger files
in the same commit; otherwise the node refuses to start. For incompatible DDL,
including dropping or renaming a journaled column, place all migration DML on
that table **before** the DDL, while its old triggers still work. Do not write
that table again in the migration batch before final trigger installation. The
new files target the completed schema. A DML statement that reaches a broken old
trigger fails the migration and startup normally; do not disable the journal to
let it through. A migration with journaled DML carries a receipt naming that file.

Custom project triggers, their header declaration and their five safeguards
belong to phase 2, HIL-1413.

## Backup And Restore

A backup dump does not carry triggers (not in the code yet — HIL-1451).
The journal travels in the backup as a second database and rolls back with the
data; otherwise it would describe rows that are no longer there
(not in the code yet — HIL-1451).

Restore order is **import → migrations → anonymization → triggers → unfreeze**
(not in the code yet — HIL-1451).
Migrations already precede anonymization deliberately in
`framework/backend/Backup/BackupRestorer.php`: the anonymizer needs the schema
the code knows. Installing triggers only after that pass keeps its rewrites out
of the journal (not in the code yet — HIL-1451).
Whether installation gets its own `RestorePhase` or is expressed within the
existing phases is HIL-1451's decision.

## On A Cluster

Triggers are installed once under the schema rollout claim
(not in the code yet — HIL-1465).
On Galera journal rows are not duplicated (not in the code yet — HIL-1465).
With a primary and a replica, triggers do not fire a second time on the replica
(not in the code yet — HIL-1465).
A receipt created through any node is read identically through any other
(not in the code yet — HIL-1465).

## What Phase 1 Leaves Out

- Archive, retention, the Archive screen, custom triggers, their column mode and
  a test trigger in chat — HIL-1413.
- The MCP channel — HIL-356, when the MCP server exists.
- A field's history in the edit modal and “what this person changed” on the
  account card — later, separate work.
- The account access log — [access-log.md](access-log.md).
- Roles — HIL-306.
- An administrator's switch for journaling a table — never; the Entity owns the
  declaration.
- Recording “who invoked the action” once across the receipt and
  `hilos_analytics_user_action` — HIL-1402 decides that boundary.

## Anti-Patterns

- `SET @hilos_user_id` before a write and a person number on every journal row
  copy the April shape; use the receipt instead.
- Leaving a number in `@hilos_receipt` after a write gives a later write the wrong
  attribution; clear it after the write.
- A foreign key from the journal onto a data row endangers the history; keep a
  soft reference.
- Putting the journal in the main database loses its separate storage boundary;
  use the database named from the main one.
- Handwriting a trigger or editing it in the database bypasses the generated
  file; regenerate and commit the file.
- Spelling a database name in a trigger body ties it to one installation; use
  `{{change_log_database}}`.
- A personal value or a secret in the journal violates its column modes; keep
  only a personal change's fact and nothing of a secret.
- Giving an administrator a “journal this table” switch moves the declaration
  out of its owner; put it on the Entity.
- Querying the journal directly from a page, table, or agent duplicates the
  reader and its bounds; use `ChangeLogSectionReader`.
- Using the April stub as a worked example revives a rejected design;
  follow this page instead.
- Naming here what a leaf introduces — Entity constants, journal tables and
  columns, the agent or the command — preempts its interview. This page states
  the rule; the leaf states the name.

## Related

- [people-table.md](people-table.md) — the person and soft references.
- [backup-anonymization.md](backup-anonymization.md) — the personal-data verdict.
- [../orm/entity.md](../orm/entity.md) — the Entity's declarations.
- [../orm/migrations.md](../orm/migrations.md) — schema rollout and its claim.
- [../orm/inheritance.md](../orm/inheritance.md) — the project's mounted chain.
- [analytics.md](analytics.md) — analytics and the action record.
- [command-server.md](command-server.md) — the CLI channel without a person.
- [logs.md](logs.md) — the node's operational logs.
- [protected-mode.md](protected-mode.md) — the restore's freeze.
- [access-log.md](access-log.md) — the account access log.
- [../rule-authoring.md](../rule-authoring.md) — clearing markers when code lands.
- [../testing.md](../testing.md) — the database per integration piece.
