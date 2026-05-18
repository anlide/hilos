# Signal Routing

Signals flow: **source → SignalRouter → daemon → worker → agent**.

## Routing principle

Route by **sender**, not by destination. The router is declarative — it maps source + type → agent type.

For project page subscription routing, keep the page-to-agent ownership in
each page class `SUBSCRIPTION_AGENT_TYPE` and let the project router import
the computed `Hilos::getPageRoutes()` registry. Do not maintain a duplicate
page routing list only inside the router; see
[app-topology.md](../app-topology.md).

## Config structure (in SignalRouter subclass)

```php
$this->config = [
    'pages'   => [ 'page_name' => ['agentType' => 'chat', 'agentIndex' => null, 'params' => []] ],
    'groups'  => [ 'group_name' => ['agentType' => 'chat', 'agentIndex' => null, 'params' => []] ],
    'signals' => [
        SignalSource::WS => [
            SignalTypeConstants::WS_HANDSHAKE => AgentType::CHAT,
            SignalTypeConstants::WS_CLOSE     => AgentType::CHAT,
        ],
        SignalSource::AGENT => [
            SignalTypeConstants::AGENT_SIGNAL => [
                'moderate_bot_request' => AgentType::MODERATOR,
                'moderation_result' => AgentType::CHAT,
            ],
        ],
    ],
    'actions' => [ 'message' => AgentType::CHAT ],
    'page_subscription_routing' => [
        'default' => AgentType::CHAT,
        'pages'   => [ PageConstants::BOT => AgentType::BOT ],
    ],
];
```

## Signal flow

1. Agent calls `Hilos::$sr->queueSignal(...)` — signal queued in router
2. At end of daemon loop: `dispatchSignals()` drains the queue
3. Router resolves destinations (agent type + optional index)
4. Daemon sends `DaemonAgentMessageDTO` to appropriate worker via `WorkerServer`
5. Worker delivers signal to agent → calls correct `onSignal*()` method

## WebSocket → agent

WS frame arrives → server parses → queues signal in `Hilos::$sr` with source `WS` → dispatched to agent.

## Agent → agent

```php
$this->sendToAgent('moderate_bot_request', $data);
// → AGENT_SIGNAL type → router config → target agent
```

## Sync signals (DB/RT)

DB/RT changes are broadcast to ALL workers simultaneously (not routed to a specific agent).
Each worker's `onSignalDbSync*` / `onSignalRtSync*` is called for all listening agents.
