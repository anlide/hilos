# ORM: Object

Object is an **enriched view** of entity data, prepared for consumption by agents or frontend.

## Location

`backend/Database/Object/Item/MyObject.php`

## Purpose

Objects combine or transform Entity data:
- Computed fields (e.g. `fullName` from `firstName` + `lastName`)
- Joined data from multiple entities
- Formatted fields (e.g. ISO date from unix timestamp)
- Data filtered or shaped for a specific view

## Structure

```php
class MyObject extends Object_ {
    public int $id = 0;
    public string $displayName = '';
    public bool $isOnline = false;

    public static function fromEntity(MyEntity $entity, bool $isOnline): static {
        $obj = new static();
        $obj->id = $entity->id;
        $obj->displayName = $entity->name;
        $obj->isOnline = $isOnline;
        return $obj;
    }
}
```

## Rules

- Object is **read-only** — never write back to DB from Object
- Object is created from Entity, not fetched from DB directly
- Business logic for creating objects belongs in Object layer or Db collection methods, not in agents
- Do not use Repository or Service pattern on top of Object — use `DbCollection` methods directly

## Object vs Entity

Objects live in `Database/Object/`, Entities in `Database/Entity/`.
`Objects.php` in `Database/Object/` registers all object collections.

## Collection

Objects are grouped into collections via `DbCollection` which exposes query methods.
See `orm/db-collection.md` for how to query.
