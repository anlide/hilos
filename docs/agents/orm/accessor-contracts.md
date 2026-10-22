# ORM: Accessor Contracts

Use existing Hilos collection, item, magic, array, and result accessors before
adding new `findBy*()` methods or caller-local helper methods.

Before choosing an accessor, choose the reference itself with
[entity references](entity-references.md): a captured internal target carries its
declared primary key. A collection accepting codes does not make codes internal
mutation targets.

## Rule

Accessor shape is part of the owning collection or item contract. Prefer the
existing contract that exactly matches the value the caller has, and add new
lookup API only on the owning layer when no contract exists.

## Workflow

1. Identify the value the caller has: primary id, collection key, business key,
   composite criteria, or a value already present on a result/item.
2. Inspect the View collection and item first: PHPDoc, `offsetGet()`,
   `__get()`, item properties, `toArray()`, and existing `findBy*()` methods.
3. Inspect the Object collection or Runtime State collection when the View layer
   delegates there, including that layer's existing `get()` contract.
4. Choose the smallest existing accessor that matches the exact key semantics.
5. Use array access only when the collection documents the offset semantics.
   For DB View reads, distinguish a required row from permitted absence below.
6. Use a named finder when the lookup is not the collection key, has complex
   query semantics, or needs a clear business name.
7. If no reusable accessor exists, add it to the owning collection/item layer
   rather than to a page, table, agent, or signal handler.

## Choosing The Accessor

| Caller has | Prefer |
|---|---|
| Documented DB View collection key, required row | `Hilos::$db->collection[$id]` |
| Documented DB View collection key, optional row | `isset(Hilos::$db->collection[$id])` before reading, or `Hilos::$db->collection[$id] ?? null` for a nullable item value |
| Documented RT View collection key | `Hilos::$rt->collection[$id]` |
| Documented key-based setting offset | `Hilos::$db->settings[$key]` |
| Business key without array-offset contract | Existing named method such as `findByToken($token)` |
| Runtime rows for one DB item | Existing RT collection method such as `forUser($userId)` |
| Direct relation from a loaded DB item | Existing bridge property such as `$event->eventMessage` |
| Item-level derived value | Existing item property or result accessor |
| Complex query or multiple criteria | Named collection method on the owning collection |

## Settings Example

Prefer key-based array access only when the settings collection explicitly
documents the setting key as its offset. This caller permits an absent setting:

```php
if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]; // Setting item by documented key-based offset
```

If caller code has a settings key, use `Hilos::$db->settings[$key]`. If that
offset contract is missing or not documented yet, add and document the
key-based offset on the settings collection first. Do not call
`Hilos::$db->settings->findByKey($key)` from pages, tables, agents, accessors,
or tests as a shortcut; a `findByKey()` helper on the settings collection is an
internal implementation detail of that collection.

If `[]` is documented as primary-key access for another collection, or the
business-key offset contract is absent, use the named accessor or add a typed
contract before changing callers:

```php
Hilos::$db->sessions->findByToken($token);
```

This rule is about using the existing model clearly. It does not require magic
access blindly.

## DB View: Required And Optional Reads

DB View `[$id]` returns the collection's typed item or throws
`Hilos\Database\Exception\View\Collection\ItemNotFoundException`, a direct
subclass of `DatabaseException`, when the row is absent.
Direct DB View `[null]` also throws `ItemNotFoundException`.

When the row is required, read it directly:

```php
Hilos::$db->users[$id]->name;
```

When absence is allowed and the statement can return early, check existence,
then read directly:

```php
if (!isset(Hilos::$db->users[$id])) {
    return;
}

Hilos::$db->users[$id]->name;
```

For a bare nullable item value, use `?? null`. This is also the shape inside a
nullable DB bridge or an existing method returning `?User`:

```php
return Hilos::$db->users[$id] ?? null;
```

For a chain without a convenient early return, use an existence ternary:

```php
isset(Hilos::$db->users[$id]) ? Hilos::$db->users[$id]->name : null;
```

Do not use `Hilos::$db->users[$id]?->name` or
`(Hilos::$db->users[$id] ?? null)?->name` for that chain. A nullable bridge
after the DB item still uses `?->`:

```php
if (!isset(Hilos::$db->events[$eventId])) {
    return;
}

Hilos::$db->events[$eventId]->eventMessage?->message;
```

The same rules apply to a collection held in a variable: `$users[$id]->name`
for a required row, `isset($users[$id])` before an optional read, and
`$users[$id] ?? null` for a nullable item value.

In PHPUnit, assert existence through the collection's array contract:

```php
$this->assertArrayNotHasKey($id, Hilos::$db->users);
$this->assertArrayHasKey($id, Hilos::$db->users);
```

Use these assertions with `$users` as well. Do not use
`assertNull(Hilos::$db->users[$id])` or
`assertFalse(isset(Hilos::$db->users[$id]))` to assert absence.

Do not add a DB View `get()`, a `require*()` wrapper, or an `instanceof` check
to narrow a standard read by its documented key. Required and optional reads
already have their forms above; named finders for other criteria remain valid.

## Nullable Keys

For DB View collections, `isset($collection[$id])` calls `offsetExists()`;
`$collection[$id] ?? null` calls it too, then calls `offsetGet()` only if the
key exists. When the key is absent, including a `null` key, `isset` is false
and `?? null` returns `null` without a missing-row refusal. With an existing
key, `??` reads the item through `offsetGet()`. Neither form suppresses DB or
configuration errors. These optional forms work already; see PHP's
[ArrayAccess behavior](https://wiki.php.net/rfc/container-offset-behaviour#userland_classes_that_implement_arrayaccess).

DB Object/Entity collections keep their nullable read contracts, including
direct `[null]` returning `null`. Object-layer `get()` remains valid.
RT View collections also keep nullable `[]`: a missing or `null` key returns
`null`. The DB View required-read rule does not extend to those layers.

RT backing `RtStates::get(?string $id): ?RtState` remains the optional state
lookup. Concrete state collections may declare required `[]`; follow that
collection's contract rather than assuming all `RtStates` reads are strict.

## Result And Item Accessors

Before adding a finder or table/page helper, check whether the value is already
available from the loaded result or item:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

count(Hilos::$db->users[$userId]->connections);

if (!isset(Hilos::$db->settings[$dto->key])) {
    return;
}

Hilos::$db->settings[$dto->key]->value;
```

If the value describes one model item, prefer an item property, item method,
typed DTO field, or signal payload field over a caller-local array.

For direct DB item relation properties, read `db-item-bridges.md`. Bridge
properties are the preferred API for simple FK, one-to-one detail, and
one-to-many child relations from a loaded View item.

## Exceptions

Use or add a named method instead of magic/array access when:

- the collection offset is a different key than the value the caller has;
- the lookup combines multiple criteria, sorting, filtering, or permissions;
- a specialized lookup has null/missing semantics beyond the required/optional
  forms above; ordinary optional DB View reads do not justify a new `get()`;
- the lookup triggers behavior that should be visible in the method name;
- the API would otherwise hide DB/RT aggregation that belongs in a typed
  collection, item bridge, DTO, or signal payload.

## Anti-Patterns

Do not add a redundant primary-key finder:

```php
// Wrong when users[$id] is the documented collection contract.
$user = Hilos::$db->users->findById($userId);
```

Use the collection contract:

```php
if (!isset(Hilos::$db->users[$userId])) {
    return;
}

Hilos::$db->users[$userId]; // User item by documented collection key
```

Do not replace a finder with array access unless the offset contract matches:

```php
// Wrong if settings[] is primary-key access.
Hilos::$db->settings[$dto->key];
```

Do not bypass the documented settings offset contract:

```php
// Wrong in caller code; use Hilos::$db->settings[$dto->key].
Hilos::$db->settings->findByKey($dto->key);
```

Do not hide reusable lookup logic inside a page or table:

```php
// Wrong: caller-local lookup duplicates collection behavior.
$this->loadSettingInsidePage($dto->key);
```

Put the missing reusable lookup on the owning collection or item instead.
