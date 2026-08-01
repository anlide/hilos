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

## Settings Entity (special case)

`Entity/Item/Setting.php` — key/value store for app-level runtime settings.
Accessed via `Hilos::$db->settings`.
