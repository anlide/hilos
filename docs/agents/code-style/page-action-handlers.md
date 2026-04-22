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

## Subscribe handlers and route params

`AbstractPage::onSubscribe()` and `AbstractPage::onUpdateSubscription()` receive
a typed `PageRouteParams` value object, not a raw `array<string, string>`. All
route-param parsing goes through its accessors, which keep "missing" and
"invalid" as distinct 400 errors:

- `getString()` / `requireString()` — raw strings, empty value treated as absent.
- `getInt()` / `requireInt()` — any integer, including `"0"` and negatives.
- `getPositiveInt()` / `requirePositiveInt()` — the default for DB ids (`> 0`).
- `getEnum()` / `requireEnum()` — `BackedEnum` cases.

`require*` throws `MissingPageRouteParamException` when the key is absent or an
empty string; any accessor (including `get*`) throws
`InvalidPageRouteParamException` when a non-empty value fails its typed
contract. Both exceptions extend `PageSubscriptionException` with
`httpCode=400`, so the router surfaces them as a `subscription_page_error`
signal without tearing down the connection.

Pages with real route params should not read `PageRouteParams` inline. Define
an `AbstractPageSubscribeParamsDTO` subclass alongside the abstract page, parse
everything inside `fromPageRouteParams()`, and expose a `final onSubscribe()` in
the abstract class that dispatches to a typed hook:

```php
final class HilosUserPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    public function __construct(public readonly int $userId) {}

    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new self(
            userId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_USER_USER_ID),
        );
    }
}

abstract class AbstractHilosUserPage extends AbstractHilosPage
{
    final public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->onHilosUserSubscribe(
            $acceptKey,
            HilosUserPageSubscribeParams::fromPageRouteParams($params),
        );
    }

    abstract protected function onHilosUserSubscribe(
        string $acceptKey,
        HilosUserPageSubscribeParams $params,
    ): void;
}
```

Domain checks (existence of the entity in the DB, permissions, etc.) stay in
the page handler and throw `PageResourceNotFoundException` / other
`PageSubscriptionException` subclasses after the DTO has validated the raw
route shape. `PageRouteParams` never performs DB lookups.
