# ORM: DbCollection

`DbCollection` is the query interface for a DB table. Access via `Hilos::$db->{collectionName}`.

## Accessing collections

```php
// In agent or page:
Hilos::$db->events          // events collection
Hilos::$db->users           // users collection
Hilos::$db->settings        // settings collection
```

The available collections are defined in the project's `DbContext` subclass (e.g. `DbChatContext`).

## Common methods

```php
// Find by primary key
$entity = Hilos::$db->users->findByKey($id);

// Find by session/connection key
$entity = Hilos::$db->users->findBySession($acceptKey);

// Get all as collection
$all = Hilos::$db->events;  // collection is iterable

// Actions (mutations) — via actions sub-object
Hilos::$db->events->actions->add(ChatEventType::MESSAGE->value, $userId, $text);
Hilos::$db->users->actions->ban($userId);
```

## Actions pattern

Write operations live in `actions` sub-object on the collection.
Never call raw SQL or DB methods from agents directly.

```
Hilos::$db->events->actions->add(...)    ✅
Database::query("INSERT INTO ...")        ❌
```

## Anti-pattern: Repository/Service

Do NOT introduce a Repository or Service class that wraps DbCollection:

```php
// ❌ Wrong
class EventRepository {
    public function add(string $type): void {
        Hilos::$db->events->actions->add($type);
    }
}

// ✅ Correct — call DbCollection directly
Hilos::$db->events->actions->add($type);
```

## DbContext

The context class exposes collection names as constants and typed properties:
```php
class DbChatContext {
    public const string events = 'events';
    public Events $events;
    // ...
}
```
Access: `Hilos::$db->events` (typed property on the singleton).
