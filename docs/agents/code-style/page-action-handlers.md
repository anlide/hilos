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
6. Wrap the whole `switch ($action)` in `try/catch`. Handlers should throw
   validation, logic, SQL, and action-layer failures; `onAction()` maps them to
   the page's fail/error signal with `sendToUser()`. Prefer `catch (Throwable
   $e)` when the page has a user-visible failure signal, so unexpected DB/runtime
   failures also clear frontend loading state.
7. Use action constants in `case` labels and signal constants in acks/errors.
   Do not duplicate raw action names as strings in handlers.
8. Do not invent hidden fail signals. If an action has no user-facing pending
   state or fail UI, log the caught error in a small helper; add a real signal
   contract before trying to surface it in the frontend.

## Shape

```php
public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
{
    try {
        switch ($action) {
            case ChatSignalConstants::USER_UPDATE:
                if ($dto instanceof UserUpdateActionDTO) {
                    $this->handleUserUpdate($acceptKey, $dto);
                }

                break;

            default:
                return;
        }
    } catch (Throwable $e) {
        $this->sendToUser(
            ChatSignalConstants::USER_UPDATE_FAIL,
            $acceptKey,
            new ActionFailSignalData($e->getMessage()),
        );
    }
}
```
