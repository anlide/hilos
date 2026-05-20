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
`SignalRouter` reads `Hilos::getActionAgentRoutes()` through the project
facade hook at dispatch time. Worker page routers should import
`Hilos::getPageActionRoutes()` for `action -> page` dispatch. WebSocket client
allowlists should read the same page-action registry.

For page-dispatched non-action signals, keep ownership in each page class
`SIGNALS`. `SignalRouter` resolves the owning agent through
`Hilos::getPageSignalAgentRoutes()` and its project facade hook, while worker
page routers import `Hilos::getPageSignalRoutes()` for page dispatch.

For direct agent-to-agent signals, keep ownership in each agent class
`AGENT_SIGNALS` and register the agent class in `Hilos::AGENTS`. `SignalRouter`
reads `Hilos::getAgentSignalRoutes()` at dispatch time.

For WebSocket group subscriptions, keep per-group ownership in each group class
`SUBSCRIPTION_AGENT_TYPE` and register the group in `Hilos::GROUPS`.
`SignalRouter` reads `Hilos::getGroupRoutes()` through the project facade hook;
project routers should not maintain a duplicate group routing list in config. See
[app-topology.md](../app-topology.md).

## Service-signal defaults (in SignalRouter subclass)

Project routers declare daemon/WebSocket service-signal ownership through
protected default hooks. Override `hilosClass()` so framework routing can read
project topology.

```php
protected function hilosClass(): string
{
    return Hilos::class;
}

protected function getDefaultPageSubscriptionAgentType(): ?string
{
    return AgentType::CHAT;
}

protected function getDefaultWebSocketLifecycleAgentType(): ?string
{
    return AgentType::CHAT;
}

protected function getDefaultDaemonCronAgentType(): ?string
{
    return AgentType::CHAT;
}

protected function getDefaultSystemBootstrapAgentTypes(): array
{
    return [
        AgentType::CHAT,
        AgentType::LIBRARY,
        // ...agents started on INITIAL_AGENTS_START
    ];
}
```

`getDefaultSystemBootstrapAgentTypes()` defines which agents are started when
daemon delivers `DAEMON/SYSTEM` bootstrap signals such as `INITIAL_AGENTS_START`.
It is not a blanket "all agents listen to all system signals" rule. Indexed agents
such as bots should be started by project worker-server hooks, not included here
unless the project explicitly wants that.

Do not duplicate page subscription ownership, group subscription ownership,
page actions, page-owned signals, or agent-owned agent signals in project router
code. Those routes come from page, group, and agent classes through the active
project Hilos facade.

## Signal flow

1. Agent calls `Hilos::$sr->queueSignal(...)` — signal queued in router
2. At end of daemon loop: `dispatchSignals()` drains the queue
3. Router resolves destinations (agent type + optional index)
4. Daemon sends `DaemonAgentMessageDTO` to appropriate worker via `WorkerServer`
5. Worker delivers signal to agent → calls correct `onSignal*()` method

## WebSocket → agent

WS frame arrives → server parses → queues signal in `Hilos::$sr` with source `WS` → dispatched to agent.
For `ACTION` frames, the framework router reads the page-owned action registry
at dispatch time. Projects should not keep a generic `WEBSOCKET/ACTION =>
AgentType::*` fallback when action ownership can be derived from pages.

For page-owned non-action frames such as `FRAME_BINARY`, the framework router
reads the page signal registry at dispatch time before service-signal default hooks.

## Agent → agent

```php
$this->sendToAgent('moderate_bot_request', $data);
// → AGENT_SIGNAL type → topology registry → target agent
```

## Sync signals (DB/RT)

DB/RT changes are broadcast to ALL workers simultaneously (not routed to a specific agent).
Each worker's `onSignalDbSync*` / `onSignalRtSync*` is called for all listening agents.
