# Page Action Handlers

Read this when editing a backend page class that handles WebSocket actions,
especially `onAction()`.

## Rules

If a page handles a WebSocket action, declare that action and its payload DTO
class in the page's `public const array ACTIONS`. The project facade uses those
declarations to compute action routing, action payload parsing, and WebSocket
validation.

1. `onAction()` routes by action name. Always use `switch ($action)` with
   explicit `case SomeConstants::ACTION_NAME:` branches, even when the page
   currently has only one action.
2. Each `case` validates the expected DTO type before delegating. Prefer an
   inverted guard that throws `InvalidActionPayloadException` when the DTO does
   not match the action name, then call the private handler with the narrowed
   DTO. Never silently ignore an action because its DTO is unexpected.
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
6. Do not wrap `onAction()` routing in a local `try/catch`. Handlers should
   throw validation, logic, SQL, and action-layer failures; the framework wraps
   action dispatch and calls `AbstractPage::onActionException()`. Override
   `onActionException()` only when the page has a more specific user-facing
   error contract (for example table errors or modal acks).
7. Use action constants in `case` labels and signal constants in acks/errors.
   Do not duplicate raw action names as strings in handlers.
8. The `default` branch must throw `AgentUnknownActionException`; do not log and
   return on unknown actions.
9. Do not call `logAgentError()` for invalid action DTOs. Throw and let
   `PageSignalRouter` call `onActionException()`, which either sends the page's
   specific fail contract or the default framework `action_error` signal.
10. Do not invent hidden fail signals. If an action has a user-facing pending
   state or fail UI, add a real signal contract or override
   `onActionException()` before trying to surface it in the frontend. Otherwise
   rely on the framework `action_error` signal.

## Shape

```php
public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
{
    switch ($action) {
        case ChatSignalConstants::USER_UPDATE:
            if (!$dto instanceof UserUpdateActionDTO) {
                throw new InvalidActionPayloadException(
                    $action,
                    UserUpdateActionDTO::class,
                    $dto,
                );
            }

            $this->handleUserUpdate($acceptKey, $dto);

            break;

        default:
            throw new AgentUnknownActionException("Unknown action: {$action}");
    }
}

public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
{
    switch ($action) {
        case ChatSignalConstants::USER_UPDATE:
            $this->sendToUser(
                ChatSignalConstants::USER_UPDATE_FAIL,
                $acceptKey,
                new ActionFailSignalData($e->getMessage()),
            );

            break;

        default:
            parent::onActionException($acceptKey, $action, $dto, $e);
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
