---
name: hilos-backup-anonymization
description: Classify a Hilos installation's personal data for restore-time anonymization — the per-column verdict each table carries. Use when declaring that verdict, classifying a table or column a migration just added, choosing an anonymization strategy for a column, or reading a restore refused by the coverage or the compatibility gate.
---

# Hilos Backup Anonymization

Use this skill inside a Hilos repository when the task is classifying data rather
than building a surface. Start by reading `agents.md`, then the guide below before
writing a verdict.

## Read First

- The guide — core rule, where a verdict is declared, the seven strategies, the
  selection rules, the three gates: `docs/agents/architecture/backup-anonymization.md`
- Where the tables-without-entity class sits among the other backup catalog keys,
  and the rest of backup activation:
  `docs/agents/architecture/admin-feature-scaffold.md`
- What a migration obliges: `docs/agents/orm/migrations.md`
- The worked verdicts: `demo/chat/backend/Database/Entity/Item/User.php` and the
  framework's own Entities; for tables with no Entity,
  `framework/backend/Database/Schema/FrameworkTablesWithoutEntity.php`
- The test every project with backup keeps:
  `demo/chat/tests/Integration/PiiRegistryCoverageTest.php`
- Strategy semantics and the SQL each one becomes: the PHPDoc of
  `framework/backend/Backup/Anonymization/AnonymizationStrategy.php` and
  `framework/backend/Backup/Anonymization/AnonymizationSqlBuilder.php`
- DB work around the tables being classified: `$hilos-orm`
- Test commands: `$hilos-testing-cli`

## Workflow

1. Read the guide, then the `_pii` / `_piiNotPersonal` constants already on the
   Entities of the tables you are about to touch.
2. Write a verdict on every table that does not yet carry one — `_pii` on its
   Entity, an empty column map when the table holds nothing personal, and
   `_piiNotPersonal` naming the columns that judgement covers.
3. Judge every column of the live table, not only the ones the ORM maps: a column
   named by neither constant is the gap the per-column verdict exists to close.
4. Choose a strategy per column by the guide's selection rules, and check the
   incoming foreign keys yourself before declaring a whole-table purge.
5. Declare a table with no Entity in a `TablesWithoutEntityProvider` instead, and
   name that class under `BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY`.
6. Run the project's PII coverage test through composer, not a restore. A column left
   out of both halves now also keeps the daemon from starting, so an unrun test is a
   node that will not come up rather than a restore that will refuse later.

## Hard Rules

- Never run `git commit` or `git push`.
- Never leave a table of the project unclassified, and never add a bypass flag,
  an "anonymize everything" default, or a second entry that skips the gates.
- Never leave a live column named by neither half in a project that carries backup:
  its daemon refuses to start until one of them names it.
- Never treat an empty column map as a gap to fill later; it is the declaration
  that the table holds nothing personal.
- Never name one column in both `_pii` and `_piiNotPersonal`, and never declare
  `_piiNotPersonal` on a table purged whole: neither survives the collection.
- Never declare `AnonymizationStrategy::PURGE` on a table with an incoming
  `ON DELETE RESTRICT` foreign key: nothing checks the order, and the pass fails
  after the import, over a database already holding production data.
- Never declare `AnonymizationStrategy::NULLIFY` on a NOT NULL column, a
  `fake-*` strategy on a table without a single-column primary key, or
  `AnonymizationStrategy::MASK` on a column covering a UNIQUE index.
- Do not restate strategy semantics, the framework's own classification, or the
  generated SQL here or in a project doc; they live in their PHPDoc.
- Stop and ask before changing what the framework classifies or how a gate
  refuses; this skill declares data, it does not modify the engine.
