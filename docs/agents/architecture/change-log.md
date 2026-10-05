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

The database writes the journal through generated service triggers —
`AFTER INSERT`, `AFTER UPDATE` and `AFTER DELETE` on each journaled table
(not in the code yet — HIL-1447).

The Entity declares which tables are journaled; nobody switches them on or off,
and the screens only show what is declared (not in the code yet — HIL-1446).

For attribution a journal row carries only the receipt number; the receipt says
who acted, on whose behalf and through what (not in the code yet — HIL-1449).

The journal lives in its own database on the same server
(not in the code yet — HIL-1445).

Triggers are files, and the database holds exactly what those files say
(not in the code yet — HIL-1448).

## What An Entity Declares

Each column of a journaled table has exactly one mode
(not in the code yet — HIL-1446):

| Mode | What is written | Where it comes from |
|---|---|---|
| Value | Old → new | Default (not in the code yet — HIL-1446) |
| Personal | Only the fact that it changed, no value | The Entity's `_pii`, with no second list (not in the code yet — HIL-1446) |
| Secret — hashes, keys, codes | Nothing, neither value nor fact | Named by the Entity (not in the code yet — HIL-1446) |
| Noisy — last activity, sign-in time, counters, the `updated_at` stamp | Nothing; an update touching only noisy columns is not written at all | Named by the Entity; mark them boldly (not in the code yet — HIL-1446) |
| Binary | Only the fact | The column's type (not in the code yet — HIL-1446) |
| Record key | The row's identity in the journal, not a field of it | The key (not in the code yet — HIL-1446) |

A column left without a mode keeps the node from starting, naming the table and
column, just as the personal-data coverage gate does
(not in the code yet — HIL-1446).

The framework tables under the journal are `hilos_user`, `hilos_identity`,
`hilos_second_factor` and `hilos_setting` (not in the code yet — HIL-1446).
HIL-1446 names the declaration constants. Whether secrets have their own constant
or follow an anonymization strategy, how a noisy column gives its reason, and how
tables outside the ORM declare journaling are questions for that leaf.

In a demo, journal what a person changes by a decision, not the stream: chat bots
are journaled, messages are not; polls are journaled, votes are not; quotes in
binance-btc-tracker are not (not in the code yet — HIL-1453).
The exact table list per demo and the feature's activation shape belong to
HIL-1453. The column mode for a custom trigger belongs to phase 2, HIL-1413.

## What A Trigger Writes

- An insert records the fact that the row was created; its values are in the row
  itself (not in the code yet — HIL-1447).
- An update records only columns that actually changed, old → new; a personal
  column contributes only the fact, a secret contributes nothing, and an update
  of noisy columns alone produces no journal row
  (not in the code yet — HIL-1447).
- A delete records a snapshot without personal or secret values, keeping the
  column modes above (not in the code yet — HIL-1447).
- Values follow the real column type in the live database — the ORM does not
  know lengths: numbers, short strings and dates fit in the journal row; long
  text and JSON go into a neighboring journal table
  (not in the code yet — HIL-1447).
- A bridge insert or delete records that a link was added or removed
  (not in the code yet — HIL-1447). The link appears in the history of both ends
  (not in the code yet — HIL-1452).
- A write without a receipt is allowed and produces a journal row with an empty
  receipt number (not in the code yet — HIL-1447). The feed shows
  “Unknown — a change made past the application”
  (not in the code yet — HIL-1452).

The journal row commits and rolls back with the write it describes: a trigger
runs inside its statement's transaction, and both databases are on the same
server.

## The Receipt

One action has one receipt: who is at the keyboard, on whose behalf, the session,
the channel, the action, the agent, the source and the time
(not in the code yet — HIL-1449).

Under impersonation the administrator is at the keyboard and the session's
person is the one on whose behalf the action runs. That is already the session's
answer: `Session::userAtKeyboard()` in
`framework/backend/Database/Object/Item/Session.php`.

| Channel value | Who | Agent | Source | Implementation |
|---|---|---|---|---|
| `web` | The person; both people under impersonation | The page's agent | The session | (not in the code yet — HIL-1449) |
| `migration` | Empty | None — the migration runner | The migration file | (not in the code yet — HIL-1449) |
| `agent` | Empty, shown as “System” | That agent | The agent itself | (not in the code yet — HIL-1450) |
| `cli` | Empty | The executing agent | The command, without the values it was given | (not in the code yet — HIL-1455) |
| `cron` | Empty | The job's agent | The job and its time | (not in the code yet — HIL-1456) |
| `mcp` | The person | — | The tool | Outside phase 1; when the MCP server arrives, HIL-356 |

The CLI command channel has no person by design; see
[Who may call a command — nobody is asked](command-server.md#who-may-call-a-command--nobody-is-asked).

The action is the name the server knows — `bot.update`, `settings.set` — not the
button's label (not in the code yet — HIL-1449).

Set `@hilos_receipt` before the write and clear it afterward
(not in the code yet — HIL-1449):

```sql
SET @hilos_receipt = <number>;
-- The write runs here.
SET @hilos_receipt = NULL;
```

A worker shares its connection among agents and reconnects silently. Leaving a
receipt number on it would attribute the next write to the wrong person. An
empty variable means an empty receipt number in the journal
(not in the code yet — HIL-1449).

A receipt under which nothing journaled was written does not remain
(not in the code yet — HIL-1449). HIL-1449 decides whether the application or
the first trigger writes the receipt and how an empty one is avoided.

A page action whose write is handed to another agent keeps one receipt with the
person and the writing agent, including when the recipient is on another node:
the journal database is shared and the number means the same thing there
(not in the code yet — HIL-1454).

An agent that writes later on its own, on a timer, uses its own receipt with the
`agent` channel (not in the code yet — HIL-1454).

When a person has been erased, the soft reference keeps their number and the
screen shows “deleted user #N”, as analytics does
(not in the code yet — HIL-1460).

## Where The Journal Lives

The journal has its own database on the same MariaDB server — a trigger cannot
write to another server (not in the code yet — HIL-1445).

Its name is the main database's name plus `-change-log`: `hilos-demo-chat` becomes
`hilos-demo-chat-change-log`, and `hilos-framework-test-1` becomes
`hilos-framework-test-1-change-log` (not in the code yet — HIL-1445).

The name cannot be one constant shared by all installations: integration tests
have [a database per piece](../testing.md), named by
`scripts/framework-pieces.php`. The separator is a hyphen because an underscore
in a database name in `GRANT` is a wildcard for one character.

The journal has no foreign keys onto data rows: otherwise deleting a row would
either be refused or erase its history (not in the code yet — HIL-1445).
The large append-only tables have monthly partitions
(not in the code yet — HIL-1445).

A receipt refers to a person softly, without a foreign key
(not in the code yet — HIL-1445). MariaDB 11.4 does not allow foreign keys on
partitioned tables, and a partition brought back from an archive could name a
person who has been erased. See
[Foreign Keys Onto The Person](people-table.md#foreign-keys-onto-the-person).

Phase 1 keeps the journal in the database forever. Archiving and retention
belong to HIL-1413. Who creates the database and grants access on an existing
volume, who adds next month's partition, and how its schema is rolled out are
HIL-1445's decisions.

Only triggers write journal rows; the section reads them through its own agent
(not in the code yet — HIL-1452). Whether that reader uses Entities or SQL, and
how many instances of the agent run, belong to HIL-1452.

**The April placeholder is not the model.** It is still in:

- `framework/backend/Database/Migration/Stub/create_hilos_change_log.sql` and
  `framework/backend/Database/Migration/Stub/create_hilos_change_log_down.sql`;
- `demo/chat/backend/Database/Migration/Schema/016_create_hilos_change_log.sql`;
- the `hilos_change_log*` verdicts in
  `framework/backend/Database/Schema/FrameworkTablesWithoutEntity.php`;
- the `SET @hilos_user_id` TODO in
  `framework/backend/Core/Page/AbstractHilosPage.php`;
- the TODOs in `framework/backend/Pages/ChangeLog/`:
  `AbstractHilosChangeLogDashboardPage.php`, `AbstractHilosChangeLogTablesPage.php`
  and `AbstractHilosChangeLogTablePage.php`.

That shape describes a person number on every row, retention cleanup,
`track_values`, foreign keys, `hilos_change_log` / `hilos_change_log_value` and an
administrator's dry run. It is superseded, not a worked example to copy.
HIL-1445 replaces the schema and verdicts, HIL-1449 the receipt TODO, and
HIL-1452 the page placeholders.

## Triggers Are Files

As with procedures in hleb's `main/App/Routines`, each trigger has its own file
with the latest body; its history is the file's `git blame`
(not in the code yet — HIL-1447).

The trigger names are `hilos_cl_<table>_after_insert`,
`hilos_cl_<table>_after_update` and `hilos_cl_<table>_after_delete`; the file is
`<trigger name>.sql`, for example `hilos_cl_hilos_user_after_update.sql`
(not in the code yet — HIL-1447). MariaDB's 64-character identifier limit leaves
42 characters for the table name. The longest table name with an Entity today is
`hilos_second_factor_backup_code`, at 31 characters.

The first line is `-- valid from migration #<N>`: N is the migration from which
this body is valid (not in the code yet — HIL-1447).
When a table leaves the journal, keep a tombstone — the same file, the same
header and `DROP TRIGGER IF EXISTS` (not in the code yet — HIL-1447).

In the body, use `{{change_log_database}}` for the journal database name; the
node substitutes the name at installation, so do not spell it out in the SQL
(not in the code yet — HIL-1447).

The files live in the project's tree, including triggers on framework tables:
the generator reads the Entity chain mounted by the project and its database's
real column types, and the migration number belongs to the project
(not in the code yet — HIL-1447). Framework migration stubs are copied into
numbered project migrations. HIL-1447 chooses the directory and the developer
command's name.

Only the generator writes service triggers; the developer invokes it through a
command (not in the code yet — HIL-1447).

After migrations, the node installs all triggers from their files once, under
the schema rollout claim, `hilos_migration_claim`
(not in the code yet — HIL-1448). The existing claim is described in
[Nodes that start together](../orm/migrations.md#nodes-that-start-together-hil-1228).

The node refuses to start and names the trigger when the database has one
without a file, when a file disagrees with what the generator would write today,
or when a trigger is not generated — phase 1 has no custom triggers
(not in the code yet — HIL-1448).

A migration changing a journaled table regenerates that table's trigger files
in the same commit (not in the code yet — HIL-1447); otherwise the node refuses
to start (not in the code yet — HIL-1448).
How to remove or rename a journaled column while old triggers still stand is
left to HIL-1448.

The existing SQL-files seam is `Migration::applyRoutines()` in
`framework/backend/Database/Migration.php`; it has no caller today. Making it
the installation point or replacing it belongs to HIL-1448
(not in the code yet — HIL-1448).
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
  copy the April shape; use the receipt instead
  (not in the code yet — HIL-1449).
- Leaving a number in `@hilos_receipt` after a write gives a later write the wrong
  attribution; clear it after the write (not in the code yet — HIL-1449).
- A foreign key from the journal onto a data row endangers the history; keep a
  soft reference (not in the code yet — HIL-1445).
- Putting the journal in the main database loses its separate storage boundary;
  use the database named from the main one (not in the code yet — HIL-1445).
- Handwriting a trigger or editing it in the database bypasses the generated
  file; regenerate and commit the file (not in the code yet — HIL-1447).
- Spelling a database name in a trigger body ties it to one installation; use
  `{{change_log_database}}` (not in the code yet — HIL-1447).
- A personal value or a secret in the journal violates its column modes; keep
  only a personal change's fact and nothing of a secret
  (not in the code yet — HIL-1447).
- Giving an administrator a “journal this table” switch moves the declaration
  out of its owner; put it on the Entity (not in the code yet — HIL-1446).
- Using the April stub or TODOs as a worked example revives a rejected design;
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
