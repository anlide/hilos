# ORM: DbCollection

`Hilos::$db` is the database context entry point for the current application.
Access table-backed data through its typed collections:

```php
Hilos::$db->users;
Hilos::$db->events;
Hilos::$db->settings;
```

The available names are defined in the project's `DbContext` subclass, such as
`ChatDbContext`. A caller should use these existing access paths before adding a
new collection, helper, table method, or page-level query.

## Layer Model

`DbContext` owns the app-level DB context. It registers object collections in
`_objectCollections`, then maps each one to a view collection with
`setRepresent()`.

The Entity also determines which logical database the collection's rows belong
to; mounting a collection does not create a second public context for that
database. (not in the code yet — HIL-1614)

```php
final class ChatDbContext extends HilosDbContext
{
    public const string events = 'events';

    public function configure(): void
    {
        parent::configure();

        $this->mountObjectCollection(ObjectEvents::class, Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::events, Events::class, EventsActions::class);
    }
}
```

The mount method uses `ObjectEvents::COLLECTION_KEY` as the actual key and refuses
an empty or occupied key. `setRepresent()` remains a separate call under that
same key. Framework collections are registered under their framework keys by
`HilosDbContext::configure()`; projects extend their whole chain through
`frameworkExtensions()`.

`DbContext::getMountedEntities()` returns the mounted key to Entity class map in
registration order. It reads class constants without a database query or a
reader-interest check and refuses any collection whose Object, Entity, or table
cannot be resolved.

`Hilos::$db->events` calls the context magic getter and returns the registered
`DbCollection` wrapper.

The layers are:

| Layer | Role | Example |
|---|---|---|
| Entity item | Raw DB row fields and table mapping only | `Database/Entity/Item/User.php` |
| Object item | Enriched state, calculated values, sync/delete behavior | `Database/Object/Item/User.php` |
| Object collection | Loading, lazy strategy, DB-backed lookup/query helpers | `Database/Object/Collection/Users.php` |
| View item (`DbItem`) | Read-only caller-facing item wrapper | `Database/View/Item/User.php` |
| View collection (`DbCollection`) | Caller-facing collection API and lookup helpers | `Database/View/Collection/Users.php` |
| Actions | Write operations for collection or item | `Database/Actions/...` |

Do not skip these layers with page/table-specific SQL when the behavior belongs
to a collection, item, or action.

## Collections In A Named Database

`Hilos::$db` remains the public door. The database follows the
[Entity's declaration](entity.md#the-database-an-entity-lives-in), not a connection
chosen by a page, table, or agent. (not in the code yet — HIL-1614)
Lookup by key, lazy loading, `queryPage()` / `loadByColumn()`, counts and bulk
writes all read or write that Entity's database.
(not in the code yet — HIL-1614)
Do not add a repository, service, or context per database. HIL-1614 supplies the
missing database routing; an agreed future API is not a reason to put SQL into
its callers meanwhile.

A transaction, its memory rollback and `afterCommit()` queue, and the SQL session
variable `@hilos_receipt` belong to a connection. A logical database name does
not move them to another connection or promise atomicity across connections.
The existing [transaction rule](transactions.md) still applies, including the
handler boundary and the release of announcements at commit. HIL-1614 must
define and test the supported behavior of a write to a second database while
the primary connection has an open transaction, and align these docs with that
implementation. This rule does not choose between switching connections and
qualified SQL. Today the ORM uses the active connection, and receipt SQL uses
qualified journal tables on primary.

A collection written by triggers has no application writer; its readers declare
ordinary interest and ORM writes refuse, as described in
[A Collection Written Outside The Application](../architecture/truth-source.md#a-collection-written-outside-the-application).
(not in the code yet — HIL-1615)
Read journal collections through bounded queries, without loading the whole
journal or trusting a row cache to contain inserts the ORM never announced.
(not in the code yet — HIL-1615)
Trigger inserts produce no ORM sync frames; reader interest alone does not make
such a cache current.

## Finding Existing Logic

Before writing DB-backed code:

1. Find the app context: search for `extends HilosDbContext` or the collection
   constant in `ChatDbContext`.
2. Check `setRepresent()` to locate the View collection, collection actions, and
   item actions.
3. Inspect the View collection for existing array/magic access contracts and
   lookup methods such as `offsetGet()` or `findByToken()`.
4. Inspect the Object collection for DB-loading/query helpers.
5. Inspect collection actions for create/register/add operations.
6. Inspect item actions for update/delete operations on one loaded item.
7. Only then add the smallest missing method to the owning layer.

## Reads

Use `Hilos::$db->{collection}` from agents, pages, and handlers:

```php
Hilos::$db->sessions->findByToken($token);

if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key];

if (!isset(Hilos::$db->users[$userId])) {
    return;
}

Hilos::$db->users[$userId]; // DB item by documented collection key

foreach (Hilos::$db->events as $event) {
    // $event is a DbItem wrapper.
}
```

DB collection read access treats a `null` key as absent. `isset($collection[null])`
is false, `$collection[null]` returns `null`, and unset with `null` is a no-op.
Use this for nullable relation keys in View item bridges instead of adding
caller-side guards around every collection access.

Put reusable lookups on the collection layer instead of rebuilding the same
filter in pages or tables. Example: `Sessions::findByToken()` delegates to the
Object collection and returns the matching `DbItem`.

Do not put read-only helpers under `->actions`. Actions are write APIs; using
them for reads hides ownership boundaries and makes call sites look like they
mutate state.

For direct relation properties on View items, read
`docs/agents/orm/db-item-bridges.md`. For choosing between `[]`, magic/item
properties, result accessors, and `findBy*()` methods, read
`docs/agents/orm/accessor-contracts.md`.

## A Concrete Collection Names Its Item

A concrete View or Object collection names its own item in the readers it
inherits. PhpStorm does not substitute `T` from
`@extends DbCollection<Item, ObjectCollection>` or
`@extends Objects<ObjectItem>` into those readers; it falls back on the native
`?DbItem` / `?Object_`, and a property read by key —
`Hilos::$db->users[$userId]->name` — is flagged "Potentially polymorphic call".
The collection names the item once, in its own docblock, and every caller reads
it typed.

The readers to name, by layer:

- a View collection: `getObjectCollection()` to the class in
  `OBJECT_COLLECTION_CLASS`; `first()`, `last()`, and `offsetGet()` to the class
  in `DB_ITEM_CLASS`;
- an Object collection: `first()`, `last()`, `get()`, and `offsetGet()` to the
  class in `OBJECT_CLASS`.

The shape is the class-level `@method` block of
`framework/backend/Database/View/Collection/Users.php` and
`framework/backend/Database/Object/Collection/Users.php`:

```php
/**
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 */
class Users extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;
```

A real method with the narrow native return type counts the same —
`offsetGet(mixed $offset): ?Language` in
`framework/backend/Database/View/Collection/Languages.php` — and is the shape
when the reader also changes behavior. The type is exactly the class the
constant names, nullable: a base class, a foreign class, or an extra union
branch does not narrow. `current()` is not required. An abstract base that does
not declare the constant, or leaves it empty, names no item and is not judged;
the concrete collection that sets the constant is. A runtime state collection
narrows with real methods instead — see [rt-state.md](../runtime/rt-state.md).
Checked automatically: `COLLECTION-ITEM-NARROWING`, see
[automated-checks.md](../code-style/automated-checks.md).

## Collection Actions

Collection actions are for writes that create rows or affect the collection as
a whole: register a user, add an event, delete all rows, import a batch, or
perform a real bulk transition.

```php
Hilos::$db->users->actions->register($sessionToken);
Hilos::$db->events->actions->add($type, $userId, $payload);
```

A collection action receives the `DbCollection`, can use its `objectCollection`
shortcut, should call `ensureCanCreate()` or `ensureCanWrite()` when mutating,
and should keep the in-memory object collection synchronized after DB changes.
`ensureCanWrite()` takes the operation the action performs
(`TruthSourceOperation::Add` for an insert in bulk through the whole table,
`::Update` or `::Remove`) and has no default.
A bulk write cut by one set — the rows of one person — calls
`ensureCanWriteSet($setKey, $operation)` instead, which asks for that set and
not for the whole table, with the operation named the same way. Creating one
row of a table cut by a set calls `ensureCanCreateInSet($setKey)` with the
value the row will carry in its `_setVia` column, and `ensureCanCreate()` stays
for a table cut by none.

Do not put update/delete operations for one known collection item behind a
collection action that accepts the item's key:

```php
// Wrong: key identifies one settings item.
Hilos::$db->settings->actions->update($key, $value);

// Correct: load the item by key, then mutate that item.
if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]->actions->updateValue($value);
```

## Item Actions

Item actions are for writes on a single loaded `DbItem`, including update and
delete operations when the caller already has the collection key:

```php
if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]->actions->updateValue($value);
// or:
Hilos::$db->settings[$key]->actions->delete();
```

Use item actions when the operation naturally belongs to an existing item and
needs that item's object state. Do not put update/delete logic in a page handler
when an item action should own it.

## Accessors And Settings

The settings collection is available through `Hilos::$db->settings`.

```php
if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]->value;
```

If a collection explicitly supports array-style, magic, or result access, use
that collection API rather than duplicating lookup logic:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

Hilos::$db->users[$userId]; // DB item by documented collection key

if (!isset(Hilos::$db->settings[$key])) {
    return;
}

Hilos::$db->settings[$key]; // If settings support key-based access
```

Array-style access is collection-specific. Prefer `Hilos::$db->settings[$key]`
when the settings collection documents the setting key as its offset. If the
target collection does not support that key, use the existing named accessor or
add a typed collection method instead of relying on an unstructured array
convention.

## Choosing Where New Logic Belongs

| Need | Put it in |
|---|---|
| New DB table or column | Migration plus matching Entity update |
| Raw row mapping | Entity item/collection |
| Derived display data or enriched state | Object item |
| DB-backed lookup or query helper | Object collection plus View collection wrapper |
| Direct relation from one loaded View item | View item bridge property |
| Caller-facing read transformation | View item or View collection |
| Create/register/add operation | Collection actions |
| Delete all rows, import a batch, or mutate a batch | Collection actions |
| Update/delete one item when its key is known | Item actions |
| Page response assembly | Page handler, using existing DB APIs only |
| Table query/result shaping | Table layer, delegating DB behavior to collections/actions |

## Anti-Patterns

Do not introduce Repository or Service classes on top of `DbCollection`:

```php
// Wrong.
final class EventRepository
{
    public function add(string $type): void
    {
        Hilos::$db->events->actions->add($type);
    }
}
```

Call the collection or action directly:

```php
Hilos::$db->events->actions->add($type);
```

Do not add raw SQL, entity mutation, or duplicate filtering logic to page/table
layers just because it is local to one screen. Page/table code should orchestrate
existing typed DB APIs; the DB layer should own persistent data behavior.

Do not add read-only methods to actions. Put reads on `DbCollection`, `DbItem`,
Object collection/item helpers, or typed read payload APIs.

Do not change which rows an object store holds by writing `$this->objects` inside a
concrete collection. A `DbCollection` answers a key out of its cache of wrappers and
only the store's announcement empties that cache, so a row dropped by hand goes on
reading as alive here. The two announcing roads, and the silent one a load takes, are
in [object.md](object.md).
