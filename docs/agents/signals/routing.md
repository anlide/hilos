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
`Hilos::getPageActionRoutes()` for `action -> page` dispatch and use
`HilosPageFactory` with the project facade class to parse action payloads from
`Hilos::getActionDtoRoutes()`. WebSocket client allowlists should read the same
page-action registry.

For page-dispatched non-action signals, keep ownership in each page class
`SIGNALS`. `SignalRouter` resolves the owning agent through
`Hilos::getPageSignalAgentRoutes()` and its project facade hook, while worker
page routers import `Hilos::getPageSignalRoutes()` for page dispatch.
Named agent-signal routes may declare inner payload DTO classes; worker dispatch
parses them through `HilosPageFactory::createPageSignalPayloadDTO()` inside
`PageSignalRouter::dispatchAgentSignal()` before `onSignalAgent()`.

For direct agent-to-agent signals, keep ownership in each agent class
`AGENT_SIGNALS` and register the agent class in `Hilos::AGENTS`. `SignalRouter`
reads `Hilos::getAgentSignalRoutes()` at dispatch time. When topology declares
an inner payload DTO, `WorkerManager` calls
`SignalRouter::createAgentSignalPayloadDTO()` before the agent handler runs.

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
5. Worker parses topology-declared inner payload DTOs when present, then
   delivers the signal to the agent or page router and calls the correct
   `onSignal*()` method

Topology parsing happens on the worker before handlers:

- `ACTION` → `HilosPageFactory::createActionPayloadDTO()` → `onAction()`
- page-routed `AGENT_SIGNAL` → `HilosPageFactory::createPageSignalPayloadDTO()`
  → `onSignalAgent()`
- agent-owned `AGENT_SIGNAL` → `SignalRouter::createAgentSignalPayloadDTO()`
  → agent `onSignalAgent()`

Routing-only entries skip parsing and pass the incoming payload through.

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

## Indexed agent signals

Multi-instance agents (one process per entity, keyed by `agentIndex`) can be
targeted declaratively without overriding `getDestinations()`.

Declare `AgentSignalConfigKey::INDEX_FIELD` on the receiving agent class:

```php
use Hilos\Core\Agent\Config\AgentSignalConfigKey;

final class BotAgent extends AbstractAgent
{
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE,               // singleton route, routing-only
        ChatSignalConstants::BOT_AGENT_START => [       // indexed route with DTO
            AgentSignalConfigKey::INDEX_FIELD => 'botId',
            AgentSignalConfigKey::DTO => BotAgentSignalData::class,
        ],
    ];
}
```

At dispatch time `SignalRouter::getAgentDestinations()` reads the field name
from `Hilos::getAgentSignalIndexFields()` and extracts the value from the inner
payload's `toArray()`. Accepted types: positive `int` (converted to string) or
non-empty `string`. Any other value — absent field, `0`, empty string, `null` —
produces no destination and logs a warning.

The inner DTO's `toArray()` must expose the declared field name. See
[dto-convention.md](dto-convention.md) for the requirement.

Do **not** override `SignalRouter::getDestinations()` for indexed routing when
`INDEX_FIELD` covers the case. Reserve overrides for routing patterns that the
topology registry cannot express at all.

## Per-instance page subscriptions

A page is normally served by the agent of a TYPE. A page that is the surface of ONE
entity — a chat room, a person's profile — can declare that its subscription belongs to
the agent of that one instance instead:

```php
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;

final class ChatRoomPage extends AbstractPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT_ROOM;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'chatId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::CHAT,
    ];
}
```

Two sources, because an instance is named in exactly two ways. `PARAM` — the address of
the page names it, and the index travels as a subscription param. `SESSION_USER` — the
page is "mine", and the instance is the person behind the connection, whom the master
reads through the same identity seam the access guards are judged with
(`BrowserContext::connectionIdentity()`). It reads one row of one connection and never
the database.

**The address is resolved ONCE, on `page_subscribe`, and remembered on the subscription
record.** Everything that follows — `page_update_subscription`, `table_viewport`,
`action`, `page_unsubscribe` — is addressed off that record. This is not an optimization:
an unsubscribe carries nothing but the accept key, so a per-signal recomputation would
have nothing to recompute from, and a recomputation mid-subscription would hand a live
client to a different instance and leave a phantom subscription on the old one.

The value form matches an indexed agent signal's: positive `int` or non-empty `string`.
Anything else — no such param, an empty value, a guest on a "my" page — is not an error:
the subscription goes to the `FALLBACK_AGENT_TYPE` the page declared, and that agent
answers the client the ordinary way, 401 included. **The master never answers a client
itself and forms no application errors.** Whether the row behind an index exists is not
checked either — the ordinary `DB_EXISTS` guards ask that inside the agent.

Consequences worth knowing before declaring one:

- **Replacement.** Navigation is a single `page_subscribe` that atomically replaces the
  previous subscription. When the address moves, the master delivers `page_unsubscribe`
  straight to the previous agent — not through the queue, which would deliver it after
  the record already named the new one.
- **Update.** `page_update_subscription` is a param change INSIDE the same page. An
  update that would change the index value is refused with a log line and leaves the
  subscription untouched: another instance is another page, and a page change arrives as
  a subscribe.
- **Action.** An action on a per-instance page is addressed by the caller's live
  subscription to the page that owns it. No subscription, no destination — acting on a
  page one is not subscribed to never meant anything.
- **Disconnect.** `connection_close` additionally reaches the instance that held the
  connection's subscription, and the records are dropped only afterwards. Without that,
  an instance in another worker keeps a subscription with no socket behind it.
- **Move on sign-in.** A guest who signs in stays on the same page, and the page is
  re-judged rather than re-subscribed: `page_access_reassess` carries a copy of the
  subscribe, its address is recomputed, and the client gets a full page answer without
  the frontend knowing anything happened. `HilosSessionHost::authenticateSession()`
  announces it after binding the connection.
- **Waiting for an identity.** A `SESSION_USER` page whose connection identity has not
  crossed the RT sync yet is held for up to 500ms and then routed on what is known. Only
  such a page ever waits; every other subscription is routed the moment it arrives.

`TopologyValidator` refuses a declaration that cannot work: a `PARAM` source without a
param, a missing or unregistered `FALLBACK_AGENT_TYPE`, and a `SESSION_USER` source on a
`PageAccessLevel::PUBLIC` page — a guest handed to the fallback agent would be served the
"my" page for real.

Groups (`GROUPS`) stay on the agent type: there is no per-instance group surface yet.

## Out of scope for topology DTO routing

Do not add topology inner-payload DTO declarations for:

- Type-wide page signal routes such as `FRAME_BINARY => []`
- Framework WebSocket lifecycle signals
- DB/RT sync broadcasts
- Outbound server→client WebSocket signals

See [app-topology.md](../app-topology.md) for the full out-of-scope list.

## Sync signals (DB/RT)

DB/RT changes are broadcast to ALL workers simultaneously (not routed to a specific agent).
Each worker's `onSignalDbSync*` / `onSignalRtSync*` is called for all listening agents.
