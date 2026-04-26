# ORM: DbCollection

`Hilos::$db` is the database context entry point for the current application.
Access table-backed data through its typed collections:

```php
Hilos::$db->users;
Hilos::$db->events;
Hilos::$db->settings;
```

The available names are defined in the project's `DbContext` subclass, such as
`DbChatContext`. A caller should use these existing access paths before adding a
new collection, helper, table method, or page-level query.

## Layer Model

`DbContext` owns the app-level DB context. It registers object collections in
`_objectCollections`, then maps each one to a view collection with
`setRepresent()`.

```php
final class DbChatContext extends HilosDbContext
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
   constant in `DbChatContext`.
2. Check `setRepresent()` to locate the View collection, collection actions, and
   item actions.
3. Inspect the View collection for existing array/magic access contracts and
   lookup methods such as `offsetGet()`, `findBySession()`, or `findByKey()`.
4. Inspect the Object collection for DB-loading/query helpers.
5. Inspect collection actions for create/register/add operations.
6. Inspect item actions for update/delete operations on one loaded item.
7. Only then add the smallest missing method to the owning layer.

## Reads

Use `Hilos::$db->{collection}` from agents, pages, and handlers:

```php
$user = Hilos::$db->users->findBySession($acceptKey);
$setting = Hilos::$db->settings->findByKey($key);
$user = Hilos::$db->users[$userId] ?? null;

foreach (Hilos::$db->events as $event) {
    // $event is a DbItem wrapper.
}
```

Put reusable lookups on the collection layer instead of rebuilding the same
filter in pages or tables. Example: `Users::findBySession()` delegates to the
Object collection and returns the matching `DbItem`.

For choosing between `[]`, magic/item properties, result accessors, and
`findBy*()` methods, read `docs/agents/orm/accessor-contracts.md`.

## Collection Actions

Collection actions are for writes that create or mutate collection-level state:
register a user, add an event, delete all rows, import a batch, or perform a
collection-owned business transition.

```php
$user = Hilos::$db->users->actions->register($sessionToken);
$event = Hilos::$db->events->actions->add($type, $userId, $payload);
```

A collection action receives the `DbCollection`, can use its `objectCollection`
shortcut, should call `ensureCanCreate()` or `ensureCanWrite()` when mutating,
and should keep the in-memory object collection synchronized after DB changes.

## Item Actions

Item actions are for writes on a single loaded `DbItem`:

```php
$setting = Hilos::$db->settings->findByKey($key);
$setting?->actions->update(['value' => $value]);
$setting?->actions->delete();
```

Use item actions when the operation naturally belongs to an existing item and
needs that item's object state. Do not put update/delete logic in a page handler
when an item action should own it.

## Accessors And Settings

The settings collection is available through `Hilos::$db->settings`.

```php
$setting = Hilos::$db->settings->findByKey($key);
$value = $setting?->getEffectiveValue($catalog);
```

If a collection explicitly supports array-style, magic, or result access, use
that collection API rather than duplicating lookup logic:

```php
$settings = Hilos::$db->settings;
$user = Hilos::$db->users[$userId] ?? null;
$setting = $settings[$key] ?? null; // If settings support key-based access.
```

Array-style access is collection-specific. Prefer `$settings[$key]` over
`$settings->findByKey($key)` only when the settings collection documents the
setting key as its offset. If the target collection does not support that key,
use the existing named accessor or add a typed collection method instead of
relying on an unstructured array convention.

## Choosing Where New Logic Belongs

| Need | Put it in |
|---|---|
| New DB table or column | Migration plus matching Entity update |
| Raw row mapping | Entity item/collection |
| Derived display data or enriched state | Object item |
| DB-backed lookup or query helper | Object collection plus View collection wrapper |
| Caller-facing read transformation | View item or View collection |
| Create/register/add operation | Collection actions |
| Update/delete one item | Item actions |
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
