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

## 6. Add routing rules in SignalRouter

```php
// backend/Core/Router/ChatSignalRouter.php
$signals = [
    SignalSource::WS => [
        SignalTypeConstants::WS_HANDSHAKE => AgentType::MY_AGENT,
    ],
    // ...
];
```

## 7. For page-based agents: register page mapping

```php
$pages = [
    PageConstants::MY_PAGE => [
        'agentType' => AgentType::MY_AGENT,
        'agentIndex' => null,
        'params' => [],
    ],
];
```

## Checklist

- [ ] `AGENT_TYPE` constant matches value in `AgentType`
- [ ] Added to both worker factory AND daemon factory
- [ ] Routing rules declared in `SignalRouter`
- [ ] `onStop()` unregisters truth sources if any were registered in `onStart()`
- [ ] `onTick()` completes in < 0.1s
