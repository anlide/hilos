---
name: hilos-backup-anonymization
description: Classify a Hilos project's personal data for restore-time anonymization — the PII registry of its backup catalog. Use when declaring that registry, classifying a table or column a migration just added, choosing an anonymization strategy for a column, or reading a restore refused by the coverage or the compatibility gate.
---

# Hilos Backup Anonymization

Use this skill inside a Hilos repository when the task is classifying data rather
than building a surface. Start by reading `agents.md`, then the guide below before
writing a registry row.

## Read First

- The guide — core rule, registry shape, the seven strategies, the selection
  rules, the two gates: `docs/agents/architecture/backup-anonymization.md`
- Where the registry sits among the other backup catalog keys, and the rest of
  backup activation: `docs/agents/architecture/admin-feature-scaffold.md`
- What a migration obliges: `docs/agents/orm/migrations.md`
- The worked registry: `demo/chat/backend/Backup/BackupCatalog.php`
- The test every project with backup keeps:
  `demo/chat/tests/Integration/PiiRegistryCoverageTest.php`
- Strategy semantics and the SQL each one becomes: the PHPDoc of
  `framework/backend/Backup/Anonymization/AnonymizationStrategy.php` and
  `framework/backend/Backup/Anonymization/AnonymizationSqlBuilder.php`
- DB work around the tables being classified: `$hilos-orm`
- Test commands: `$hilos-testing-cli`

## Workflow

1. Read the guide, then the project's existing registry under
   `BackupConstants::CATALOG_PII` in its backup catalog.
2. Write a row for every table the project creates and does not yet classify —
   keyed by its Entity or Object collection class, an empty column map when the
   table holds nothing personal.
3. Choose a strategy per column by the guide's selection rules, and check the
   incoming foreign keys yourself before declaring a whole-table purge.
4. Replace a framework row only whole: naming a framework table in the project
   registry drops the framework's columns for it, so restate them.
5. Run the project's PII coverage test through composer, not a restore.

## Hard Rules

- Never run `git commit` or `git push`.
- Never leave a table of the project unclassified, and never add a bypass flag,
  an "anonymize everything" default, or a second entry that skips the gates.
- Never treat an empty column map as a gap to fill later; it is the declaration
  that the table holds nothing personal.
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
