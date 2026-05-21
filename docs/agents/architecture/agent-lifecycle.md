# Agent Lifecycle

Agents are the units of business logic. Each runs inside a worker process.

## Identity

```php
class MyAgent extends AbstractAgent {
    public const string AGENT_TYPE = 'my_agent'; // must match AgentType constant
    protected ?string $agentIndex = null;         // null = singleton, 'string' = multi-instance
}
```

Agent ID: `"type"` or `"type:index"` for multi-instance agents.

## Lifecycle methods

| Method | When called | Notes |
|---|---|---|
| `onStart()` | After agent created in worker | Register truth sources, seed runtime state |
| `onTick()` | Every worker loop iteration | **Must complete in < 0.1s** |
| `onStop()` | Before removal | Cleanup while truth-source rights are still active |

After `onStop()` returns or throws, `WorkerManager` unregisters the agent from
both DB and RT truth-source registries in a `finally` block.

## Signal handler methods

All have default no-op implementation. Override only what you need:

```
onSignalHandshake()         ← new WS connection
onSignalConnectionClose()   ← WS connection closed
onSignalPageSubscribe()     ← client subscribed to a page
onSignalPageUnsubscribe()   ← client left a page
onSignalAction()            ← client sent an action
onSignalFrameBinary()       ← binary WS frame (file upload)
onSignalAgent()             ← agent-to-agent signal
onSignalDbSyncCreated/Updated/Deleted()  ← DB change broadcast
onSignalRtSyncCreated/Updated/Deleted()  ← RT change broadcast
onSignalCron()              ← cron tick
onSignalSystem()            ← system signal
```

Named signal handlers such as `onSignalAgent()` and `onSignalCron()` route by
`switch ($name)` with explicit cases; see
`docs/agents/code-style/signal-handlers.md`. Do not add an empty `default`
branch just to return from ignored shared-broadcast names; document the ignore
contract and let the method fall through.

## Sending signals from agent

```php
$this->sendToUser($signalName, $acceptKey, $data);        // to one WS connection
$this->sendToAllUsers($signalName, $data, $exclude?);     // broadcast
$this->sendToGroup($signalName, $group, $data, $exclude?); // to group
$this->sendToAgent($signalName, $data);                   // agent-to-agent
$this->emitChangeDb($eventKey, $data);                    // DB change → mapper → WS
$this->emitChangeRt($eventKey, $data);                    // RT change → mapper → WS
```

## Self-stop

Agent can request its own removal: `$this->selfStop()`.
`onStop()` will be called at the start of the next tick.

## Registration

1. Add constant to `AgentType` class
2. Create `AgentDaemon` subclass (for daemon-side routing)
3. Register worker and daemon classes in `Hilos::AGENTS` via `AgentRegistryKey`
4. Delegate `AgentManager` / `AgentManagerDaemon` creation to `TopologyAgentFactory`
6. Add direct agent-to-agent signal names to `AGENT_SIGNALS`, or keep dynamic
   payload-dependent routes in `SignalRouter`
