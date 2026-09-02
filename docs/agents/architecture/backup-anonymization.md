# Backup Anonymization (The PII Registry)

Read this before activating backup in a project, before adding a table or a
column to a project that already has it, or when a restore refuses with a
complaint about a PII registry.

Anonymization is the pass a restore runs over freshly imported data when a
production archive lands in a lesser environment: declared columns are rewritten
and declared tables emptied, so a staging copy keeps the shape of the system it
came from without carrying its personal data. The machinery is
`framework/backend/Backup/Anonymization/`, reached through the
`RestoreAnonymizer` seam that `BackupRestorer` calls; the implementation an
installation that declares its personal data gets is `CatalogRestoreAnonymizer`.

This document holds what the code does not: how to choose a strategy, what to do
after adding a table, and the one hazard the framework does not check for you.
What each strategy means, which tables the framework classifies and why, and the
SQL each one becomes are documented where they live — read `AnonymizationStrategy`,
the `_pii` constants of the framework's own Entities, `FrameworkTablesWithoutEntity`
and `AnonymizationSqlBuilder` for those. Nothing here restates them, because a copy
of a list goes stale without saying so.

## Core Rule

Every table an archive carries must be classified. A table with no row in the
registry stops the restore before the first import — that is the whole point of
the feature, because an unclassified table is the shape a leak takes: a migration
adds one, nobody writes a row for it, and its rows ride into staging untouched.

An empty column map is a classification, not an omission: it says the table was
looked at and holds nothing personal — and `_piiNotPersonal` beside it names the
columns that judgement covers, so a column added later is missing from both halves
rather than hiding inside the first. There is no bypass flag and none is to be
added. The cost was accepted knowingly — a new table breaks anonymized restores
until somebody classifies it.

## Anonymization Belongs To The Restore, Not To The Archive

A production archive is written raw and stays raw. That is deliberate: the
archive's first duty is disaster recovery into production, where anything
rewritten would be data lost. What a restore does with it depends on where it is
going, and the environment matrix (`RestoreEnvGuard`) decides:

| Archive | Target | Verdict |
|---|---|---|
| prod | prod | allowed as-is — disaster recovery |
| prod | non-prod | `RestoreEnvDecision::REQUIRE_ANONYMIZATION` |
| non-prod | prod | always refused |
| non-prod | non-prod | allowed as-is; the registry is never read |
| unrecorded environment | prod | needs `--force` |
| unrecorded environment | non-prod | allowed as-is |

**"Production" means exactly `AppEnv::PROD` on both sides, and staging is not it.**
A staging target is a non-production target, so a production archive still has to
be anonymized to land there; a staging archive is a non-production source, so it
never overwrites production. That is the row most projects meet first, and reading
staging as production-like is how a project ends up without the verdicts it needs.

Only the second row reaches anything else in this document. A restore under
`REQUIRE_ANONYMIZATION` whose scope is `schema-only` is exempt and says so in the
log: that archive carries no rows, so there is nothing to anonymize and nothing an
undeclared registry could fail to anonymize.

## Where A Verdict Is Declared

On the table itself. An Entity carries its verdict in two constants beside
`_columns`, and the registry a restore runs under is collected by walking the
collections a `DbContext` mounted:

```php
final class User extends Entity
{
    // ... _table, _columns, _types, _indexes

    public const array _pii = [self::name => AnonymizationStrategy::FAKE_NAME];

    public const array _piiNotPersonal = [
        self::id,
        self::admin,
        self::last_activity,
    ];
}
```

- `_pii` is either a column-to-strategy map or `AnonymizationStrategy::PURGE` for
  the table as a whole. `PURGE` is the only table-level strategy; declaring any
  other one on a table, or `PURGE` on a column, is refused as a malformed verdict.
- `_piiNotPersonal` names the columns looked at and found to hold nothing
  personal. A purged table declares none: no row of it survives. A column named by
  both constants is refused — the two say opposite things about the same data.
- The column set to judge is the *live* one, not `_columns`. A table may carry
  columns the ORM does not map (`Identity::secret` is the framework's own case),
  and those are personal data like any other.
- No connection index is written: a verdict on an Entity lands on the primary
  connection, which is the only one an Entity knows.

**A table with no Entity declares both facts in a `TablesWithoutEntityProvider`** —
which live tables are unmapped on purpose, and what of them is personal. The
framework's own are `FrameworkTablesWithoutEntity`; a project with such tables
implements the interface and names its class under
`BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY` in its backup catalog. A project
whose tables all have an Entity, like the chat demo, names nothing there.

The worked examples are the framework's own Entities and
`demo/chat/backend/Database/Entity/Item/`; the collection is documented on
`PiiRegistry`.

An installation that classifies nothing at all is not an installation without
personal data, and both halves of the restore say so. The CLI preflight refuses
with `this restore requires anonymization, but no table declares what of it is
personal data` and exits `ExitCode::CONFIG_ERROR`; the engine refuses on its own
before the first destructive step, so a restore driven past the CLI cannot slip
through.

## The Seven Strategies

The values are the strings a verdict is written in; the case names are how they
are referred to in code.

| Value | Case | Writes | Take it when |
|---|---|---|---|
| `hash` | `HASH` | salted SHA-256 of the value, truncated to the column | the value must stay joinable inside the copy, or the column is UNIQUE |
| `null` | `NULLIFY` | SQL NULL | the column is nullable and nothing downstream needs a value |
| `mask` | `MASK` | the fixed stub `[redacted]` (`BackupConstants::ANONYMIZATION_MASK`) | free text nobody joins on, and no UNIQUE index over it |
| `fake-email` | `FAKE_EMAIL` | `user<pk>@example.invalid` | an address that must stay unique and undeliverable |
| `fake-name` | `FAKE_NAME` | `User <pk>` | a display name whose readability keeps the copy usable |
| `fake-phone` | `FAKE_PHONE` | a fictional-range number derived from the primary key | a phone column that must stay unique |
| `purge` | `PURGE` | deletes every row of the table | token-shaped tables — sessions, credentials, push subscriptions — where NOT NULL and UNIQUE leave nothing to rewrite into |

`fake` is deliberately not one case: guessing a column's meaning from its name is
not something the framework may do, so the project names the formatter it wants.

## Choosing A Strategy

**`purge` only where nothing points at the table with `ON DELETE RESTRICT`.** The
pass runs a connection's statements in the registry's declaration order inside a
single transaction, and no foreign-key ordering is computed, so a purge of a
RESTRICT parent would fail *during the pass* — that is, after the import, over a
database that already holds production data. The compatibility gate stands in front
of that: it reads the incoming keys of every table declared `purge` and refuses the
declaration, naming the child table, its columns and the key. The refusal comes
earliest of all in the project's own coverage test, which calls the same gate over
the schema the project's migrations create — so a wrong declaration is red before
it is committed, and no restore is needed to find it. A table whose children are
`ON DELETE CASCADE` or `SET NULL` is safe, one with a RESTRICT or NO ACTION child is
not, and that one is classified per column instead.

The remaining rules are enforced by the compatibility gate, which refuses rather
than adjusts — knowing them saves a restore, not a leak:

- **`null` needs a nullable column.** A NOT NULL column is refused.
- **The `fake-*` family needs a single-column primary key**, because that is what
  it derives from. A composite or absent primary key is refused; use `hash` or
  `mask` there.
- **Everything except `null` and `purge` writes characters.** A `json`, `enum`,
  `set` or numeric column is refused — `enum` and `set` hold characters but only
  the ones they list.
- **A UNIQUE index must survive the pass.** `mask` writes one constant over every
  row, so it is refused on any column of any UNIQUE index, the primary key
  included — one column of the index left non-injective is all a `1062` needs, and
  it arrives mid-pass over an already restored database. `hash` is refused for the
  same reach whenever the column cuts it below 32 characters, not only when that
  column makes up the index on its own.
- **What is written has to fit.** The widest value a strategy can produce is
  measured against the column, and for the `fake-*` family against the largest
  primary key the table actually holds.

Two more properties are worth knowing while writing rows, because no gate can
report them:

- **Two `fake-name` columns in one table produce the same value in both.** Both
  derive from the primary key. Where a table records a change of a name, fake the
  new one and mask the old, or every rename reads as `User 42 -> User 42`.
- **NULL stays NULL.** A column that held nothing keeps holding nothing; no
  strategy invents a value for an empty column.

## The Three Gates, And Why They Are Not One

| | Startup coverage | Archive coverage | Compatibility |
|---|---|---|---|
| Class | `AnonymizationCoverageValidator::validateLiveSchema()`, through `AnonymizationStartupGuard` | `AnonymizationCoverageValidator::validateArchiveTables()` | `AnonymizationCompatibilityValidator` |
| Runs | at the startup of a daemon, before anything composes | after the archive is unpacked, before the first import | after the forward migrations, before the first row is rewritten |
| Reads | the live schema of every configured connection | the tables the dump declares | the live schema of the target |
| Asks | is every table, and every column of it, classified? | is every table of the archive classified? | can every classified column carry what its strategy produces, and can a table declared `purge` be emptied at all? |
| A refusal costs | the node does not come up; fixed by a verdict in code | a rerun; the target is untouched | a person; the target already holds production data |

Startup coverage refuses with `The live schema is not classified for anonymization:
connection <index>: tables carry no PII verdict: <tables>; connection <index>:
columns carry no PII verdict: <table>.<column>`, which the daemon log carries under
`Daemon failed:`. It is the only gate whose reader is the author of the migration
rather than an operator — the verdict is written in code, so the refusal names
everything it found at once and expects one edit to answer all of it. Archive
coverage refuses with `The PII registry does not match the archive: connection
<index>: tables carry no PII declaration: <tables>`, and every gate here collects
its findings before refusing — an operator who meets one complaint per restore
learns to dread the gate. Compatibility opens with `The PII registry does not fit
the restored schema:` and is late by necessity rather than by oversight: between the
dump and the pass sit the import and every migration the code has gained since, and
either may have narrowed a column or widened a key. A registry row naming a table
the live schema does not carry is skipped there, not refused — coverage already
judged the archive.

Only the daemon asks the startup gate. The worker inherits the answer, the docker
supervisor runs the migrations that open the gap, and the CLI is where the gap gets
closed — a refusal there would be a dead end with no way out of it. A project that
declares no `HilosFeature::BACKUP` is not asked at all.

## Adding A Table Or A Column

1. **Write the verdict where the table is declared.** A new table needs `_pii` on
   its Entity, even if the map is empty. A new column needs naming too — in `_pii`
   when it holds personal data, in `_piiNotPersonal` when it does not. Both, or
   neither, is the answer; leaving a column out of both is the gap the per-column
   verdict exists to close.
2. **Choose the strategy** by *Choosing A Strategy* above; if you reach for `purge`,
   the compatibility gate checks the incoming foreign keys and names the one holding
   the table back.
3. **Run the project's coverage test.** It answers in seconds and does not need a
   restore, an archive or a production database — and if you skip it, the daemon of
   a project carrying backup will not start until the verdict is written.

The same obligation follows a migration: see [../orm/migrations.md](../orm/migrations.md).

## A Project With Backup Keeps A Coverage Test

Every project that activates backup must hold an integration test that runs the
real gates against the schema its own migrations create. The worked example is
`demo/chat/tests/Integration/PiiRegistryCoverageTest.php`, which asks the coverage
gate the same question a restore asks and then builds the whole pass to see that
the database accepts it.

Without such a test the gate first speaks during a live restore — months after the
migration that broke it, with an operator waiting and a target database already
holding the archive. That is the most expensive possible moment to learn that a row
is missing, and the cheapest is a unit run on the commit that added the table.

The startup gate does not replace that test: the gate answers when a node is
started, the test answers on the commit, and the earlier of the two is the cheaper
one.

## Boundaries

- **There is no anonymization on create.** A production archive is raw by design;
  see the environment matrix above.
- **The salt is minted per run and never stored.** Equal values stay equal inside
  one restored copy, so joins by a hashed value survive; two copies of the same
  archive produce hashes nobody can line up against each other.
- **`--scope=schema-only` skips the registry, the restore's own gates and the
  pass**, because such an archive carries no rows.
- **Purge order against foreign keys is not computed.** Nothing sorts the pass so
  that a purged child is emptied before its purged parent; instead the compatibility
  gate refuses a purge any incoming key holds back, whatever the order. Sorting is
  the task nobody has asked for — no installation purges a parent and its children
  together — and this document explains the refusal rather than preventing it.
