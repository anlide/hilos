# Adding a New Agent — Step by Step

## 1. Add type constant

```php
// backend/Constants/AgentType.php (project-level)
final class AgentType {
    public const string MY_AGENT = 'my_agent';
}
```

## 2. Create the Agent class

```php
// backend/Agents/MyAgent.php
class MyAgent extends AbstractAgent {
    public const string AGENT_TYPE = AgentType::MY_AGENT;

    public function onStart(): void { /* initialize */ }
    public function onTick(): void  { /* < 0.1s work */ }
    public function onStop(): void  { /* cleanup */ }
}
```

## 3. Create AgentDaemon (daemon-side stub)

```php
// backend/Core/Agent/Daemon/MyAgentDaemon.php
class MyAgentDaemon extends AbstractAgentDaemon {
    public const string AGENT_TYPE = AgentType::MY_AGENT;
}
```

## 4. Register in Hilos topology

Declare worker and daemon classes in `Hilos::AGENTS`. Use `AgentRegistryKey::INDEXED`
when the agent requires `agentIndex` at creation (for example one bot per index):

```php
// backend/Hilos.php
use Hilos\Core\Agent\Config\AgentRegistryKey;

public const array AGENTS = [
    MyAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => MyAgent::class,
        AgentRegistryKey::DAEMON => MyAgentDaemon::class,
    ],
    BotAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => BotAgent::class,
        AgentRegistryKey::DAEMON => BotAgentDaemon::class,
        AgentRegistryKey::INDEXED => true,
    ],
];
```

`TopologyAgentFactory` creates worker and daemon instances from this registry.
Project `AgentManager` / `AgentManagerDaemon` subclasses should delegate to it:

```php
TopologyAgentFactory::createWorker(Hilos::class, $agentType, $agentIndex);
TopologyAgentFactory::createDaemon(Hilos::class, $agentType, $agentIndex);
```

Do not add a project-level worker/daemon factory that only mirrors `Hilos::AGENTS`.

## 5. Declare direct agent signal ownership

```php
// backend/Agents/MyAgent.php
class MyAgent extends AbstractAgent {
    public const array AGENT_SIGNALS = [
        MySignalConstants::MY_AGENT_SIGNAL,
    ];
}
```

For **indexed multi-instance agents** (one per entity, keyed by `agentIndex`),
declare the payload field that carries the index using
`AgentSignalConfigKey::INDEX_FIELD`:

```php
use Hilos\Core\Agent\Config\AgentSignalConfigKey;

class MyIndexedAgent extends AbstractAgent {
    public const array AGENT_SIGNALS = [
        MySignalConstants::SINGLETON_SIGNAL,                    // singleton
        MySignalConstants::INDEXED_SIGNAL => [                  // indexed
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
        ],
    ];
}
```

`SignalRouter` resolves the index from the inner payload's `toArray()` at
dispatch time. The inner DTO must expose the named field; see
[dto-convention.md](../signals/dto-convention.md).

Project routers override `hilosClass()` so framework routing can read
`Hilos::getAgentSignalRoutes()` and `Hilos::getAgentSignalIndexFields()` at
dispatch time. Reserve `SignalRouter::getDestinations()` overrides for routing
patterns that the topology registry cannot express at all.

## 6. Add static source/type routing when needed

```php
// backend/Core/Router/ChatSignalRouter.php
$signals[SignalSource::WEBSOCKET] = [
    SignalTypeConstants::HANDSHAKE => AgentType::MY_AGENT,
];
```

## 7. For page-based agents: register page ownership

```php
class MyPage extends AbstractPage {
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::MY_AGENT;
}
```

## Checklist

- [ ] `AGENT_TYPE` constant matches value in `AgentType`
- [ ] Registered in `Hilos::AGENTS` with `WORKER`, `DAEMON`, and `INDEXED` when needed
- [ ] Direct agent-to-agent signals declared in `AGENT_SIGNALS`; indexed multi-instance signals use `AgentSignalConfigKey::INDEX_FIELD` instead of `SignalRouter::getDestinations()`
- [ ] Static source/type routes declared in `SignalRouter` when needed (not covered by topology)
- [ ] `onStop()` cleans owned state; `WorkerManager` unregisters truth sources after the hook
- [ ] `onTick()` completes in < 0.1s
