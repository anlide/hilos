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

```php
final class ChatDbContext extends HilosDbContext
{
    public const string users = 'users';

    public function configure(): void
    {
        parent::configure();

        $this->_objectCollections[self::users] = ObjectUsers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::users, Users::class, UsersActions::class, UserActions::class);
    }
}
```

`Hilos::$db->users` calls the context magic getter and returns the registered
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

## Finding Existing Logic

Before writing DB-backed code:

1. Find the app context: search for `extends HilosDbContext` or the collection
   constant in `ChatDbContext`.
2. Check `setRepresent()` to locate the View collection, collection actions, and
   item actions.
3. Inspect the View collection for existing array/magic access contracts and
   lookup methods such as `offsetGet()` or `findBySession()`.
4. Inspect the Object collection for DB-loading/query helpers.
5. Inspect collection actions for create/register/add operations.
6. Inspect item actions for update/delete operations on one loaded item.
7. Only then add the smallest missing method to the owning layer.

## Reads

Use `Hilos::$db->{collection}` from agents, pages, and handlers:

```php
Hilos::$db->users->findBySession($sessionToken);

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
filter in pages or tables. Example: `Users::findBySession()` delegates to the
Object collection and returns the matching `DbItem`.

Do not put read-only helpers under `->actions`. Actions are write APIs; using
them for reads hides ownership boundaries and makes call sites look like they
mutate state.

For direct relation properties on View items, read
`docs/agents/orm/db-item-bridges.md`. For choosing between `[]`, magic/item
properties, result accessors, and `findBy*()` methods, read
`docs/agents/orm/accessor-contracts.md`.

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
