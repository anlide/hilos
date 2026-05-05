# Signal DTO Convention

Every signal carries a payload object implementing `SignalDataInterface`.

## Base types

| Class | Use |
|---|---|
| `WebSocketSignalData` | Wraps WS-bound data with `targetAcceptKey` or `targetGroup` |
| `AgentSignalData` | Wraps agent-to-agent data with inner `$data` |
| `EmitDbChangeSignalData` | DB emit: carry entity key + id for mapper fan-out |
| `EmitRtChangeSignalData` | RT emit: carry collection key + state id |

## Creating a signal DTO

```php
// Implement SignalDataInterface (or extend BaseDTO for toArray/fromArray)
final class ModerationRequestSignalData extends BaseDTO implements SignalDataInterface {
    public function __construct(
        public readonly string $message,
        public readonly int $userId,
        public readonly string $acceptKey,
    ) {}

    public function toArray(): array {
        return ['message' => $this->message, 'userId' => $this->userId, 'acceptKey' => $this->acceptKey];
    }
}
```

## Naming convention

- `*SignalData` — signal payload DTO (not persisted, not DB-related)
- `*SignalDTO` — framework-internal signal envelope (rarely created by app code)
- `*ActionDTO` — action payload from client → server (parsed in `onAction`)

## Receiving agent-to-agent signal

Named signal handlers must route with `switch ($name)`; see
`docs/agents/code-style/signal-handlers.md` for the full handler shape.

```php
public function onSignalAgent(AgentSignalData $data, string $source, string $name): void {
    switch ($name) {
        case ChatSignalConstants::MODERATE_REQUEST:
            $moderationRequest = $data->data;
            if (!$moderationRequest instanceof ModerationRequestSignalData) {
                throw new InvalidAgentSignalPayloadException(
                    $name,
                    ModerationRequestSignalData::class,
                    $moderationRequest,
                );
            }

            $this->handleModerationRequest($moderationRequest);
            return;

        default:
            throw new AgentUnknownSignalException($name);
    }
}
```

Known signal names must validate their exact payload class. A mismatched inner
payload is a contract error: throw `InvalidAgentSignalPayloadException`, do not
log and return. Unknown agent signal names throw `AgentUnknownSignalException`.
`WorkerManager` catches `AgentException` around agent and page signal dispatch
and logs the failure once under the owning agent.

When a page-routed agent signal is an async continuation of a user action and
its business validation failure should reuse the page action error contract,
make the inner payload implement `ActionErrorSignalDataInterface`. Then a
`ValidationException` from the page `onSignalAgent()` handler is converted by
`PageSignalRouter` into that page's `onActionException()` hook with the
payload-provided accept key, action name, and action DTO data. Keep contract or
stale-signal failures under `AgentException`; those are logged, not sent to the
client.

## declare(strict_types=1) required

All DTO files must have `declare(strict_types=1)` at the top.
