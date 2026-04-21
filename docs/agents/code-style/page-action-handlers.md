# Page Action Handlers

Read this when editing a backend page class that handles WebSocket actions,
especially `onAction()`.

## Rules

1. `onAction()` routes by action name. Always use `switch ($action)` with
   explicit `case SomeConstants::ACTION_NAME:` branches, even when the page
   currently has only one action.
2. Each `case` uses a positive DTO guard and delegates inside that block:
   `if ($dto instanceof SomeActionDTO) { $this->handleSomeAction(...); }`.
   The switch is routing; the private handler is behavior.
3. Do not route by `match (true)` or by a single top-level
   `if ($dto instanceof ...)`. Those shapes hide the action name and make the
   page look permanently one-action.
4. Keep business validation in the canonical action layer (`DbActions`,
   `TableActions`, or `TableItemActions`). Page handlers should not duplicate
   validation already performed by the action they call.
5. Page handlers may validate page context that the action layer cannot know,
   such as the current connection, requested page params, or whether the target
   item exists before an item-level action can be created.
6. Catch the declared action-layer exceptions at the call site and map them to
   the page's fail/error signal. Keep success acks after the mutation and
   required fan-out have completed.
7. Use action constants in `case` labels and signal constants in acks/errors.
   Do not duplicate raw action names as strings in handlers.

## Shape

```php
public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
{
    switch ($action) {
        case ChatSignalConstants::USER_UPDATE:
            if ($dto instanceof UserUpdateActionDTO) {
                $this->handleUserUpdate($acceptKey, $dto);
            }

            break;

        default:
            return;
    }
}
```
