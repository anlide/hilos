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
7. Do not create local aliases for properties of an already addressed DB/RT
   item when the alias is only a shorter path to the same value. For example,
   avoid `$userId = Hilos::$rt->connections[$acceptKey]->userId` when the
   connection item remains the source of truth. Inline the property access, or
   add a typed collection/item accessor if the repeated expression is genuinely
   too complex. A local alias is allowed when it intentionally snapshots a
   value before mutation, crosses a boundary, or carries a distinct domain name
   not present in the source expression.

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
