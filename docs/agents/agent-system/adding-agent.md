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
    public function getAgentType(): string { return AgentType::MY_AGENT; }
}
```

## 4. Register in AgentManager (worker factory)

```php
// backend/Core/Agent/ChatAgentWorkerFactory.php (or equivalent)
protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface {
    return match($agentType) {
        AgentType::MY_AGENT => new MyAgent(),
        // ...
    };
}
```

## 5. Register in AgentManagerDaemon (daemon factory)

```php
// backend/Core/Agent/ChatAgentDaemonFactory.php (or equivalent)
protected function createAgentDaemon(string $agentType): AbstractAgentDaemon {
    return match($agentType) {
        AgentType::MY_AGENT => new MyAgentDaemon(),
        // ...
    };
}
```

## 6. Register in Hilos topology

```php
// backend/Hilos.php
public const array AGENTS = [
    MyAgent::AGENT_TYPE => MyAgent::class,
];
```

## 7. Declare direct agent signal ownership

```php
// backend/Agents/MyAgent.php
class MyAgent extends AbstractAgent {
    public const array AGENT_SIGNALS = [
        MySignalConstants::MY_AGENT_SIGNAL,
    ];
}
```

Project routers import `Hilos::getAgentSignalRoutes()` for static
agent-to-agent routes. Keep payload-dependent routes, such as indexed agent
starts, in `SignalRouter::getDestinations()`.

## 8. Add static source/type routing when needed

```php
// backend/Core/Router/ChatSignalRouter.php
$signals[SignalSource::WEBSOCKET] = [
    SignalTypeConstants::HANDSHAKE => AgentType::MY_AGENT,
];
```

## 9. For page-based agents: register page ownership

```php
class MyPage extends AbstractPage {
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::MY_AGENT;
}
```

## Checklist

- [ ] `AGENT_TYPE` constant matches value in `AgentType`
- [ ] Added to both worker factory AND daemon factory
- [ ] Registered in `Hilos::AGENTS`
- [ ] Direct agent-to-agent signals declared in `AGENT_SIGNALS`
- [ ] Static source/type or dynamic indexed routes declared in `SignalRouter`
- [ ] `onStop()` cleans owned state; `WorkerManager` unregisters truth sources after the hook
- [ ] `onTick()` completes in < 0.1s
