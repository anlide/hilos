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

Projects and demos wire their own Entities into the same auditor from their integration
suite (see `demo/*/tests/Integration/EntitySchemaConsistencyTest.php`). For a framework
table whose DDL the project carries in its own migrations, the INDEX axis is not
checked: a project may add an index of its own for its own queries. It may extend the
schema, not diverge from the metadata.

## Settings Entity (special case)

`Entity/Item/Setting.php` — key/value store for app-level runtime settings.
Accessed via `Hilos::$db->settings`.
