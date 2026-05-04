# Local Variables

Read this when introducing temporary/local variables, private helper methods, or
reviewing noisy code.

## Rules

1. Do not create a local variable that is immediately used exactly once as a
   pass-through value. Inline the expression instead.
2. When nullable access is used only for one immediate member call, inline the
   access with the nullsafe operator instead of assigning a temporary variable
   with a nullable fallback.
3. A one-use variable is allowed when it adds real value: type narrowing,
   nullable checks, a meaningful domain name, preserving a side-effect result
   for readability, avoiding repeated expensive work, or keeping a complex
   constructor readable.
4. Do not create a one-use private helper for a simple guard, ternary, property
   access, or short computed expression. Inline it unless the helper is reused,
   hides genuinely complex logic, names a meaningful domain concept, or isolates
   side effects.
5. Avoid generic names such as `$result`, `$data`, or `$item` when the variable
   survives more than a couple of lines. Prefer the domain name (`$userTable`,
   `$mutationSignal`, `$dbUser`).
6. Do not inline expressions if doing so hides failure handling or makes a
   nested call hard to read. The goal is less noise, not denser code.
7. Do not create local aliases for already addressed DB/RT items when the
   alias only shortens the accessor chain. Multi-use does not automatically
   justify a local variable. If a DB/RT item is addressed by a known key, keep
   the source collection and key visible at the call site:

   ```php
   Hilos::$rt->connections[$acceptKey]->userState->actions->startOutboundModeration(...);
   ```

   Do not hide that source behind a pass-through alias:

   ```php
   $connection = Hilos::$rt->connections[$acceptKey];

   $connection->userState->actions->startOutboundModeration(...);
   ```

   The same applies to aliases for properties of an already addressed DB/RT
   item, such as `$userId = Hilos::$rt->connections[$acceptKey]->userId`, when
   the item remains the source of truth. Inline the item or property access, or
   add a typed collection/item accessor if the repeated expression is genuinely
   too complex.

   Context item aliases such as `Hilos::$rt->selfConnection` count as addressed
   RT items. Do not assign `$connection = Hilos::$rt->selfConnection` merely to
   shorten repeated access. Guard the contextual item explicitly, then keep
   `Hilos::$rt->selfConnection` visible at read and action call sites. If the
   repeated access becomes too noisy, move the behavior to an approved
   collection/item API or projection boundary instead of hiding it behind a
   local alias.

   A local variable is allowed when it is not just an alias: it comes from
   iteration or filtering, snapshots a value before mutation or a side-effect
   boundary, carries a distinct domain meaning not present in the source
   expression, stores expensive computed work, or is required for type
   narrowing that cannot be expressed through the accessor.

## Example

Prefer this:

```php
$this->sendToUser(
    ChatSignalConstants::SUBSCRIPTION_PAGE_USERS,
    $acceptKey,
    new ChatEventSignalDTO(
        new EntitiesChangesDTO(),
        [TableChatContext::users => Hilos::$table->users->getFullSnapshot()],
    ),
);
```

Over this:

```php
$snapshot = Hilos::$table->users->getFullSnapshot();

$this->sendToUser(
    ChatSignalConstants::SUBSCRIPTION_PAGE_USERS,
    $acceptKey,
    new ChatEventSignalDTO(
        new EntitiesChangesDTO(),
        [TableChatContext::users => $snapshot],
    ),
);
```
