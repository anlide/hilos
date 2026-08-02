# Signal DTO Convention

Every signal carries a payload object implementing `SignalDataInterface`.

## Base types

| Class | Use |
|---|---|
| `WebSocketSignalData` | Wraps WS-bound data with `targetAcceptKey` or `targetGroup` |
| `AgentSignalData` | Wraps agent-to-agent data with inner `$data` |

## Creating a signal DTO

```php
// SignalDataInterface requires toArray() and static fromArray(); BaseDTO adds toJson()/fromJson()
final class ModerationBotRequestSignalData extends BaseDTO implements SignalDataInterface {
    public function __construct(
        public readonly string $message,
        public readonly int $botId,
    ) {}

    public function toArray(): array {
        return ['message' => $this->message, 'botId' => $this->botId];
    }
}
```

## Naming convention

- `*SignalData` — signal payload DTO (not persisted, not DB-related)
- `*SignalDTO` — framework-internal signal envelope (rarely created by app code)
- `*ActionDTO` — action payload from client → server (parsed in `onAction`)

## Topology-driven inbound signal DTOs

Declare inner payload DTO classes in page `ACTIONS`, page `SIGNALS`, or agent
`AGENT_SIGNALS`. `TopologyValidator` checks class existence,
`SignalDataInterface` implementation, and duplicate ownership at startup.
Dispatch layers parse raw or loosely typed payloads before handlers run:

| Inbound path | Topology constant | Computed registry | Parser |
|---|---|---|---|
| Client action | `Page::ACTIONS` | `Hilos::getActionDtoRoutes()` | `HilosPageFactory::createActionPayloadDTO()` |
| Page-routed agent signal | `Page::SIGNALS[AGENT_SIGNAL]` | `Hilos::getPageSignalDtoRoutes()` | `HilosPageFactory::createPageSignalPayloadDTO()` |
| Agent-owned signal | `Agent::AGENT_SIGNALS` | `Hilos::getAgentSignalDtoRoutes()` | `SignalRouter::createAgentSignalPayloadDTO()` |

When topology declares a DTO class, handlers may pass `$data->data` directly to
private methods typed with that DTO. Do not add redundant `instanceof` checks
for topology-covered signals.

Routing-only entries — list-style signal name strings, type-wide page routes such
as `FRAME_BINARY => []`, or agent signals that intentionally passthrough — keep
generic inner payloads. Handlers that need typed data must either add the DTO to
topology or validate manually until the contract is declared.

Topology mismatch after declaration is a contract error:
`InvalidAgentSignalPayloadException` for agent and page-routed agent signals,
`InvalidActionPayloadException` for actions.

## Outbound server→client WebSocket signals (decision)

**Decision: defer.** Do not add a backend topology registry for outbound WS
signals in the current framework cycle.

Inbound topology solves a different problem: untrusted wire JSON must be parsed
and validated before handlers run, and duplicate ownership must fail at startup.
Outbound signals are constructed in trusted server code
(`sendToUser()`, `sendToGroup()`, `sendToAllUsers()`) with typed
`*SignalData` objects and serialized through `toArray()` in
`DaemonManager::sendSignalToWebSocketClient()`. There is no raw inbound payload
to parse at send time.

| Possible benefit | Current coverage | Registry value |
|---|---|---|
| Contract documentation | PHP `*SignalData` classes; frontend `SignalDefinition` parsers | Low — adds a third registry to maintain |
| Allowlist validation | PHP types on `$data`; tests and code review | Low — mismatched `signalName`/DTO is a dev bug, not user input |
| Frontend codegen | Manual `SignalDefinition` modules per project | Medium only with a separate codegen tooling spike |

Keep outbound contracts on:

- backend `*SignalData` classes (authoritative payload shape),
- frontend `SignalDefinition` modules (runtime parse/narrow on receive),
- existing out-of-scope notes in `app-topology.md` and `routing.md`.

If backend/frontend drift becomes painful, prefer a **separate** dev-time spike
for one of:

- static analysis or unit tests that pair wire `type` with PHP DTO class at
  send sites,
- codegen from PHP `*SignalData` into TypeScript `SignalDefinition` factories.

Do not mirror inbound `Page::ACTIONS` / `Page::SIGNALS` topology for outbound
WS delivery — it would expand the contract gate without solving untrusted-input
validation.

## Receiving agent-to-agent signal

Named signal handlers must route with `switch ($name)`; see
`docs/agents/code-style/signal-handlers.md` for the full handler shape.

```php
// Agent::AGENT_SIGNALS declares BotMessageSignalData::class
public function onSignalAgent(AgentSignalData $data, string $source, string $name): void {
    switch ($name) {
        case ChatSignalConstants::BOT_MESSAGE:
            $this->handleBotMessage($data->data);
            return;

        default:
            throw new AgentUnknownSignalException($name);
    }
}

private function handleBotMessage(BotMessageSignalData $message): void
{
    // topology already parsed and validated the inner payload
}
```

For routing-only agent signals without a topology DTO, validate manually:

```php
public function onSignalAgent(AgentSignalData $data, string $source, string $name): void {
    switch ($name) {
        case ChatSignalConstants::MODERATE_BOT_REQUEST:
            $moderationRequest = $data->data;
            if (!$moderationRequest instanceof ModerationBotRequestSignalData) {
                throw new InvalidAgentSignalPayloadException(
                    $name,
                    ModerationBotRequestSignalData::class,
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

For page-routed agent signals, declare the inner DTO in `Page::SIGNALS` and
type private handlers the same way. `PageSignalRouter::dispatchAgentSignal()`
parses through `HilosPageFactory::createPageSignalPayloadDTO()` before
`onSignalAgent()`.

Known signal names with a topology DTO rely on dispatch-time parsing. For
routing-only signals, validate the exact payload class manually. A mismatched inner
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

## Indexed agent signal DTOs

When a signal DTO is used as the inner payload for an indexed agent signal,
declare both `AgentSignalConfigKey::INDEX_FIELD` and
`AgentSignalConfigKey::DTO` in `Agent::AGENT_SIGNALS`. Its `toArray()` must
include the field named in `INDEX_FIELD`. The framework extracts the agent
index from `toArray()` at dispatch time — a missing or zero/empty-string field
produces no destination.

```php
// INDEX_FIELD => 'botId'  →  toArray() must expose 'botId'
final class BotAgentSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(public readonly int $botId) {}

    public function toArray(): array
    {
        return ['botId' => $this->botId];
    }
}
```

## declare(strict_types=1) required

All DTO files must have `declare(strict_types=1)` at the top.
