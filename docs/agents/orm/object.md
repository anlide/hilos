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
- A failed `sync()` of a row the table already holds puts the object back to what was
  last saved, and the failure goes on to the caller: memory follows the database, so no
  catch restores fields by hand. A failed insert keeps the caller's values
- `revert()` puts the saved values back into the same entity and keeps its tie to the
  row, so the next `sync()` writes an `UPDATE` of the difference; a clone of an entity
  is a new row, which is why neither door clones
- Under a transaction the object goes back with it: an edit to the saved values, an
  insert to a new object, a delete to the row
  ([transactions.md](transactions.md#what-a-rollback-restores))

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

Concrete `Objects` collections own loading and lookup; `DbCollection` exposes
their caller-facing View API. Use the shared collection reads instead of
putting direct SQL into each concrete lookup helper. See
[db-collection.md](db-collection.md#reads) for the View API.

### Choosing Memory Or SQL

`Objects::loadByColumn(string $column, int|string ...$values)` yields matching
keys, each once. `Objects::countByColumn()` takes the same arguments and counts
matching rows without hydrating them on its SQL branch. Both resolve an Entity
column or its Object field name through `columnForField()`, using the concrete
collection's class links. An unknown column raises
`Hilos\Core\Exception\InvalidArgumentException`, even with no values
(`loadByColumn()` is a generator, so validation happens when it is consumed).
With a valid column and no values, they yield no keys or return zero without
loading anything or issuing SQL.

For a nonempty read, the shared methods first honor `loadWholeIfPromised()`:

| Collection state | Source of the answer |
|---|---|
| `initDB(LAZY_STRATEGY_NONE)` promised the whole table | Load it on the first read, preserving already held instances; subsequent reads use memory |
| Lazy loading is disabled, or `isAllLoaded()` is true | Match the current local Object set, with no SQL |
| A lazy collection is still partial (`KEY`, `BATCH` or `FULL_ON_ACCESS`) | Use Entity equality for one value or `IN` for several values; `loadByColumn()` quietly hydrates only missing keys, while `countByColumn()` uses SQL `COUNT` |

An incomplete `BATCH` or `FULL_ON_ACCESS` collection takes that SQL branch;
these helpers do not iterate its partial cache or turn the lookup into a full
load. Once explicitly loaded whole, it reads memory too. Do not call `filter()`,
`keys()`, `foreach` or `preloadAll()` on a partial collection to implement a
column lookup.

The strategy value `NONE` alone is not a promise to load a table. A concrete
`initEmpty()` collection holds only its own set and reads that set without SQL,
even while `_allLoaded` is false. A typed manual selection must not grow into
the whole table. `FilteredCollection` holds a separate `filteredObjects` map
and declares no `OBJECT_CLASS`: these column helpers retain their unknown-column
refusal there, rather than querying its source table. A `TruthSourceKeys::all()`
claim or an owner's private set does not by itself prove collection completeness.

### Scalar Values And Object Identity

`Object_::valueForColumn(string $column): mixed` reads the current scalar value
or null from the held Entity. It validates the actual column against the
concrete `ENTITY_CLASS::_columns` and raises
`Hilos\Core\Exception\InvalidArgumentException` naming the column and Entity
class when unknown. It accepts no Object field alias and does not load, write,
serialize, announce a change or expose the Entity. It reads current state,
rather than `entitySync`'s saved state or a View's masked values. `toArray()` and
`__get()` are not substitutes: an Object may serialize `country_id` as
`countryId` without exposing the original column spelling through either.

Memory matching follows the Entity's declared `_types` / `PhpType`. An integer
id and its canonical decimal string match the same row; an invalid or
overflowing representation does not become an accidental zero id. String keys
and codes are compared without lowercasing, uppercasing or trimming. Repeated
values do not duplicate a key or increase the count.

This is a lookup of canonical domain scalars, not an emulator for arbitrary SQL
coercion or collation. The partial branch retains database equality / `IN`
semantics. The settings catalog checks exact keys itself, and i18n codes use
`ascii_bin`; the shared helper does not introduce case-insensitive search or
change the schema.

Both loading paths preserve Objects already held, including their identity
under existing View wrappers. Loads retain the existing `rememberWhole()` /
`rememberKey()` rollback behavior. A memory lookup creates no result cache,
changes no Object, publishes no `SourceChange` and invalidates no View cache;
SQL hydration uses the silent road described above.

### Concrete Helpers Use The Shared Read

For example, this lookup inside `Countries::findByCode()` forces SQL even when
the collection already holds the table:

```php
// Wrong: bypasses the shared choice of memory or SQL.
$rows = static::entityClass()::get([EntityCountry::code => $code]);
```

Use the common loader and keep the collection's own item and domain behavior:

```php
public function findByCode(string $code): ?ObjectCountry
{
    if ($code === '') {
        return null;
    }

    foreach ($this->loadByColumn(EntityCountry::code, $code) as $id) {
        return $this->objects[$id];
    }

    return null;
}
```

Counts delegate to `countByColumn()` rather than loading every matching row
just to count it. A more specific predicate may narrow the loaded candidates
inside the owning collection; it does not need its own SQL or result cache.

### Direct SQL And The Guard

A read that needs fresh SQL may declare why the local mirror is insufficient
with `// fresh-sql: <nonempty reason>` on the same physical line where the
`static`, `self` or `parent` `entityClass()` chain starts. For a multiline
chain that is its first line, not the line where `get()` or `count()` finally
appears:

```php
$rows = static::entityClass()::get( // fresh-sql: this transaction needs a value not held by the local mirror.
    [EntityCountry::code => $code],
);
```

Use a substantive reason, not the helper's name; review judges why memory is
insufficient. An empty marker, a marker on a preceding or distant line, a block
comment or a marker inside a string does not cover the call. There is no
method, class or file allowlist.

Checked automatically: `COLLECTION-SQL-READ`, see
[automated-checks.md](../code-style/automated-checks.md). The lexical guard reads
only `framework/backend` and `demo/<name>/backend`, within a whole
`Database/Object/Collection/` path segment, including a nested project namespace.
View collections, `CollectionExtra`, Entity and Actions layers, the base
`Database/Object/Objects.php`, tests and scripts are outside its scope.

It detects direct `static`, `self` or `parent` `entityClass()::get()` / `count()`
calls, allowing PHP name case and whitespace differences, and reports one hit
per call on the chain's first line. Ordinary comments and strings are not code;
`getById()`, `getEntities()`, other method names and `::class` are not these
calls. The exception requires a real `T_COMMENT` in the `// fresh-sql:` form.
The guard neither loads production classes nor infers inheritance.

It does not detect `$entityClass::get()`, `EntityAlias::get()`, `Database::sql`,
PDO, reflection or calls through helpers in other files. A green run proves
only this direct form is covered; spelling the same bypass differently still
violates the rule and must be caught in review.

HIL-1533 authorized a one-time freeze of the remaining detector report after
the six full collections were corrected. Baseline records carry `# HIL-1533`
as the freeze's source, not a promise to rewrite all lazy collections in that
leaf. New paths and growing counts are forbidden, and a leaf touching a
recorded file pays off its debt and lowers or removes its record. Updates are
shrink-only; replacing a hit with another at the same file/count is invisible
to the baseline and remains a review obligation.
