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

```php
public function onSignalAgent(AgentSignalData $data, string $source, string $name): void {
    // $data->data is the inner payload
    if ($data->data instanceof ModerationRequestSignalData) {
        $this->handleModerationRequest($data->data);
    }
}
```

## declare(strict_types=1) required

All DTO files must have `declare(strict_types=1)` at the top.
