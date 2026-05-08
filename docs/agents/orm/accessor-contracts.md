# ORM: Accessor Contracts

Use existing Hilos collection, item, magic, array, and result accessors before
adding new `findBy*()` methods or caller-local helper methods.

## Rule

Accessor shape is part of the owning collection or item contract. Prefer the
existing contract that exactly matches the value the caller has, and add new
lookup API only on the owning layer when no contract exists.

## Workflow

1. Identify the value the caller has: primary id, collection key, business key,
   composite criteria, or a value already present on a result/item.
2. Inspect the View collection and item first: PHPDoc, `offsetGet()`, `get()`,
   `__get()`, item properties, `toArray()`, and existing `findBy*()` methods.
3. Inspect the Object collection or Runtime State collection when the View layer
   delegates there.
4. Choose the smallest existing accessor that matches the exact key semantics.
5. Use array access only when the collection documents the offset semantics.
6. Use a named finder when the lookup is not the collection key, has complex
   query semantics, or needs a clear business name.
7. If no reusable accessor exists, add it to the owning collection/item layer
   rather than to a page, table, agent, or signal handler.

## Choosing The Accessor

| Caller has | Prefer |
|---|---|
| Documented DB collection key | `Hilos::$db->collection[$id]` with an `isset()` guard when optional |
| Documented RT collection key | `Hilos::$rt->collection[$id]` with an `isset()` guard when optional |
| Documented key-based setting offset | `Hilos::$db->settings[$key]` with an `isset()` guard when optional |
| Business key without array-offset contract | Existing named method such as `findBySession($sessionToken)` |
| Runtime rows for one DB item | Existing RT collection method such as `forUser($userId)` |
| Item-level derived value | Existing item property or result accessor |
| Complex query or multiple criteria | Named collection method on the owning collection |

## Settings Example

Prefer key-based array access only when the settings collection explicitly
documents the setting key as its offset:

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
Hilos::$db->users->findBySession($sessionToken);
```

This rule is about using the existing model clearly. It does not require magic
access blindly.

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

## Exceptions

Use or add a named method instead of magic/array access when:

- the collection offset is a different key than the value the caller has;
- the lookup combines multiple criteria, sorting, filtering, or permissions;
- null/missing semantics differ from the collection default;
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
