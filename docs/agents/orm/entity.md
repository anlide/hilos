# ORM: Entity

Entity is the **database row** representation. It maps directly to a DB table.

## Location

`backend/Database/Entity/Item/MyEntity.php`

## Structure

```php
class MyEntity extends Entity {
    public const string id = 'id';
    public const string name = 'name';
    public const string createdAt = 'createdAt';
    public const string _table = 'my_table';

    // DB column fields (typed)
    public int $id = 0;
    public string $name = '';
    public int $createdAt = 0;

    // Table name
    public static function getTableName(): string { return self::_table; }

    // Primary key column
    public static function getPkColumn(): string { return self::id; }
}
```

## Rules

- One Entity class = one DB table
- Field names match DB column names exactly
- Types must be strict: `int`, `string`, `float`, `bool` (no mixed)
- Do not add business logic to Entity — it's a data container only
- Do not add methods that query the DB inside Entity

## Entity vs Object

| | Entity | Object |
|---|---|---|
| Source | Database | Aggregated/transformed |
| Contains | Raw DB columns | Enriched data for views |
| Created by | DB query results | Object layer |

## Schema consistency

Entity fields must match the DB schema. Migrations are the source of truth: edit the
Entity's `_columns` / `_types` / `_indexes` / `_foreign` metadata in the same commit as
the migration that changes the schema. A mismatch between the two is a bug to fix, not a
condition to diagnose at runtime.

For framework Entities this drift is caught by `EntitySchemaConsistencyTest` (framework
integration suite): it applies the migration stubs and audits every Entity against the
live schema through `Hilos\Database\Schema\EntitySchemaAudit`, comparing the declared
`_types` against the raw column type via `PhpType::forMysqlType()`. Add both migration
stub files (`create_<table>.sql` and its `_down`) when adding a framework Entity.

The audit also reads the **typed property** behind each mapped column, because that is
the shape hydration writes into, and its nullability is a claim about the column:

- a nullable property (`?int`, `mixed`, or untyped) over a NOT NULL column is a finding —
  `saveInsert()` may send the NULL the column refuses;
- a non-nullable property over a NULL-able column is a finding in the other direction —
  hydrating a stored NULL is a `TypeError`;
- `AUTO_INCREMENT` is the single exemption, which is why `?int $id = null` over an
  auto-increment primary key is the correct declaration: `saveInsert()` omits a null
  primary key from the statement instead of sending it. A `DEFAULT` does not exempt
  anything — see below. A `GENERATED` column needs no exemption: MariaDB refuses a
  `NULL` / `NOT NULL` attribute on one, so it is always NULL-able and the second rule
  above already asks for the nullable property;
- a column in `_columns` with no instance property to hydrate into is a finding of its own.

So a NOT NULL foreign key column is declared `public int $user_id;` — no `?`, no default.
The property then throws on save if nobody set it, instead of quietly inserting NULL.

Projects and demos wire their own Entities into the same auditor from their integration
suite (see `demo/*/tests/Integration/EntitySchemaConsistencyTest.php`). For a framework
table whose DDL the project carries in its own migrations, the INDEX axis is not
checked: a project may add an index of its own for its own queries. It may extend the
schema, not diverge from the metadata. The framework Entities a project audits are not
listed by hand: `EntitySchemaAudit::frameworkEntities()` discovers them all, and the one
whose table this project never creates is skipped against `EntitySchemaAudit::liveTables()`.

The audit runs in **both directions**, and the second one is what a hand-written list
cannot give. `EntitySchemaAudit::auditTableCoverage()` asks the opposite question: every
`BASE TABLE` of the live schema must be the table of an audited Entity, or a table
declared to have none. The framework declares its own in
`Hilos\Database\Schema\FrameworkTablesWithoutEntity` — `migration`, the analytics tables
and the change-log tables all live outside the ORM. A project table that does the same
is named in the `$allowedTablesWithoutEntity` argument; anything left over is a finding
on the `table_unmapped` axis. Without this direction a table nobody mapped passes the
suite in silence, which is exactly what it used to do (HIL-605).

### Who owns the value of a column with a DB-level DEFAULT

`TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and its kin have exactly two legal shapes,
and picking between them is one question: **does the application need to read the value?**

- **The application owns it.** The column is in `_columns`, its property is non-nullable
  and carries no initializer (`public string $created_at;`), and the writer sets it before
  `sync()`. Hydration then fills the property on every read, so the value is available to
  the object and view layers. This is the shape for `hilos_session.created_at` and the
  notification tables' `created_at` / `updated_at`.
- **The database owns it.** The column is left out of `_columns` entirely, so the ORM
  neither writes nor reads it and the DEFAULT is its only writer. `hilos_identity` is the
  example: its `created_at` / `updated_at` exist in the DDL and nowhere in the Entity.

The criterion is not taste: hydration walks `_columns` (`Entity::fromRow()`), so leaving a
column out of it makes the value invisible to the ORM. Need to read it — first shape; do
not — second.

The third shape, a **nullable** property mapped over `NOT NULL DEFAULT ...`, is what the
audit reports. It looks like "let the database decide" and is the opposite on the wire:
`saveInsert()` enumerates every mapped column, so the property's null goes out as an
explicit `NULL` and the DEFAULT never gets a chance to apply. MariaDB 11.4 tolerates that
for TIMESTAMP columns and substitutes `CURRENT_TIMESTAMP`; MySQL 8.4 rejects it with
`ERROR 1048` (measured 2026-08-03 on `mariadb:11.4.12` and `mysql:8.4.11` — whether Hilos
promises MySQL 8 is a v1 question, not one this rule settles). The shape is wrong on
either engine, because on the tolerant one it silently depends on the engine's mercy.

`ON UPDATE CURRENT_TIMESTAMP` creates no third shape. It is the database's backstop for
rows written around the ORM, not a second owner: `updated_at` is set by PHP on every path
that writes the row, exactly like `created_at`.

Do not answer this by teaching `saveInsert()` to skip db-defaulted columns. The only way
it could know which columns those are is a marker on the Entity — that is, the DDL
duplicated in PHP, which drifts from the migrations the moment one of them changes. The
rule is held by the audit instead.

One member of the same family the audit cannot see, because the property is non-nullable:
a **stub default impersonating DDL**, such as
`public string $timestamp = 'current_timestamp()';`.
Nothing expands that string — it reaches the column as a literal. Catching it by machine
would mean comparing the initializer against `COLUMN_DEFAULT`, pulling the DDL back into
PHP again, so it stays a rule for whoever writes the Entity: a property initializer is a
PHP value, never a piece of SQL.

## Settings Entity (special case)

`Entity/Item/Setting.php` — key/value store for app-level runtime settings.
Accessed via `Hilos::$db->settings`.
