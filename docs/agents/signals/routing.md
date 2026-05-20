# Signal Routing

Signals flow: **source → SignalRouter → daemon → worker → agent**.

## Routing principle

Route by **sender**, not by destination. The router is declarative — it maps source + type → agent type.

For project page subscription routing, keep the page-to-agent ownership in
each page class `SUBSCRIPTION_AGENT_TYPE`. `SignalRouter` reads the computed
`Hilos::getPageRoutes()` registry through the project facade hook; project
routers should not maintain a duplicate page routing list in config. See
[app-topology.md](../app-topology.md).

For WebSocket actions, keep action ownership in each page class `ACTIONS`.
Project routers should import `Hilos::getActionAgentRoutes()` for
`action -> agent` routing, and worker page routers should import
`Hilos::getPageActionRoutes()` for `action -> page` dispatch. WebSocket client
allowlists should read the same page-action registry.

For page-dispatched non-action signals, keep ownership in each page class
`SIGNALS`. `SignalRouter` resolves the owning agent through
`Hilos::getPageSignalAgentRoutes()` and its project facade hook, while worker
page routers import `Hilos::getPageSignalRoutes()` for page dispatch.

For direct agent-to-agent signals, keep ownership in each agent class
`AGENT_SIGNALS` and register the agent class in `Hilos::AGENTS`; project routers
should import `Hilos::getAgentSignalRoutes()`.

## Config structure (in SignalRouter subclass)

```php
protected function hilosClass(): string
{
    return Hilos::class;
}

protected function getDefaultPageSubscriptionAgentType(): ?string
{
    return AgentType::CHAT;
}

$this->config = [
    'groups'  => [ 'group_name' => ['agentType' => 'chat', 'agentIndex' => null, 'params' => []] ],
    'signals' => [
        SignalSource::WEBSOCKET => [
            SignalTypeConstants::HANDSHAKE => AgentType::CHAT,
            SignalTypeConstants::CONNECTION_CLOSE => AgentType::CHAT,
        ],
        SignalSource::AGENT => [
            SignalTypeConstants::AGENT_SIGNAL => Hilos::getAgentSignalRoutes(),
        ],
    ],
    'actions' => Hilos::getActionAgentRoutes(),
];
```

Do not duplicate page subscription ownership in project router config.
Registered page subscription routes come from page classes through
`Hilos::getPageRoutes()`.

Do not duplicate page-owned non-action signal ownership in project router
config. Registered page signal routes come from page classes through
`Hilos::getPageSignalAgentRoutes()` inside the framework router.

## Signal flow

1. Agent calls `Hilos::$sr->queueSignal(...)` — signal queued in router
2. At end of daemon loop: `dispatchSignals()` drains the queue
3. Router resolves destinations (agent type + optional index)
4. Daemon sends `DaemonAgentMessageDTO` to appropriate worker via `WorkerServer`
5. Worker delivers signal to agent → calls correct `onSignal*()` method

## WebSocket → agent

WS frame arrives → server parses → queues signal in `Hilos::$sr` with source `WS` → dispatched to agent.
For `ACTION` frames, routing first checks the page-owned action registry.
Projects should not keep a generic `WEBSOCKET/ACTION => AgentType::*`
fallback when action ownership can be derived from pages.

For page-owned non-action frames such as `FRAME_BINARY`, the framework router
checks the page signal registry before project-specific static config.

## Agent → agent

```php
$this->sendToAgent('moderate_bot_request', $data);
// → AGENT_SIGNAL type → router config → target agent
```

## Sync signals (DB/RT)

DB/RT changes are broadcast to ALL workers simultaneously (not routed to a specific agent).
Each worker's `onSignalDbSync*` / `onSignalRtSync*` is called for all listening agents.
