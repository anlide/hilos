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

## The two roads into an object store

An object store — a concrete subclass of `Objects` — keeps its rows in the inherited
`$this->objects` array, and writing that array by hand is the one mistake this layer
punishes silently. The store ends up correct and the view ends up wrong: nothing is
published on the source-change bus, so `ViewCacheSubscriber` never drops the wrapper,
and `DbCollection` answers that key out of its own cache without asking the store at
all. A deleted row goes on reading as alive.

So a subclass never touches the array. It takes one of three roads, and which one
depends on what actually changed:

| What happened | Road | What it does |
|---|---|---|
| A row was born, or a key now holds a different row | `$this[$id] = $object` | Announces the new membership |
| A row went | `unset($this[$id])` | Announces the loss |
| A row was read back out of the table | `$this->hydrate($id, $object)` | Announces nothing |

```php
// A row is born: the insert already happened, and the door announces the membership.
$identity = ObjectIdentity::create();
$identity->sync();
$id = $identity->id;
if ($id === null) {
    throw new DatabaseException('Identity insert did not assign an id');
}
$this[$id] = $identity;

// A row goes.
$object->delete();
unset($this[$id]);

// A row is read back: silent on purpose.
$this->hydrate($entity->id, ObjectIdentity::fromEntity($entity));
```

The id is checked before the door and not after, and that is not ceremony: an
`ArrayAccess` write under a null offset **appends** the row under the next integer key,
so a store that skips the check ends up holding the row twice over — once under the
key it was never given, and never under its own. `Objects::offsetSet()` also drops a
value that is not an instance of the collection's `OBJECT_CLASS`, without a word, so
the object handed to the door is the collection's own class and nothing else.

`hydrate()` is silent because a load changes what this process **holds**, not what the
table **contains**. Sent through the door instead, every read-back would tell dependent
views that rows they already show have just appeared.

Reading the array is untouched — `isset($this->objects[$id])`, `?? null`,
`array_filter($this->objects, ...)`, `return $this->objects[$id]`. A read
desynchronizes nobody, and a narrowed lookup inside a concrete collection is written
exactly that way. Writing a field of a row that is already there is not a membership
change either.

A mass reset inside an overridden load goes through the public `clearInMemory()`,
which is what `$this->objects = []` was doing by hand.

Checked automatically: `DB-OBJECT-MUTATE`, see
[automated-checks.md](../code-style/automated-checks.md). Two files may write the
array: `Objects` itself, which owns the door and the seam, and `ObjectCollection`,
which is no subclass of it and keeps a private array of its own under the same name.

## Object vs Entity

Objects live in `Database/Object/`, Entities in `Database/Entity/`.
`Objects.php` in `Database/Object/` registers all object collections.

## Collection

Objects are grouped into collections via `DbCollection` which exposes query methods.
See `orm/db-collection.md` for how to query.
