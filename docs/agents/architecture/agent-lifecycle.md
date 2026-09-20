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
| `onStart()` | After agent created in worker, and after its claims are laid | Seed runtime state; the claims are declared on the class, not registered here |
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

## Idle stop: an agent that lives as long as it is spoken to

An instance agent — one per user, per document, per room — must not live
forever, or a model with an agent per instance leaks processes. Declaring an
idle window is what asks for that shorter life, and there is no second
"on-demand" flag beside it:

```php
public const array AGENTS = [
    DocumentAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => DocumentAgent::class,
        AgentRegistryKey::DAEMON => DocumentAgentDaemon::class,
        AgentRegistryKey::INDEXED => true,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
    ],
];
```

No key means today's behaviour: the agent lives until the worker does. The key
may carry the framework's own number or a project's own, and topology validation
refuses it on an agent that is not `INDEXED` (a node replica or a set-wide
library comes up from the bootstrap, and nothing would address it back into
existence) and on a value that is not a positive whole number of seconds.

**Starting is what addressing does.** The first frame to the agent starts it —
the master's delivery door calls `WorkerServer::ensureAgentUp()` for an agent
that has not reported `agent_started` — so a declaring agent needs no start-up
pass of its own. On a cluster the address is answered before the agent exists
anywhere, so the node that met the empty address asks for a placement first
(`ClusterPlacement::requirePlacement()`): the leader places it itself, any other
node asks the leader.

**The frame that starts an agent waits for it in the master** (HIL-629). It is
not written behind the start, where a start that failed inside the worker would
take it along: the master holds it until the worker reports `agent_started`, then
hands it to that agent ahead of anything sent after it. A frame that met an empty
address is held the same way, until the agent is up here or the placement view
names the node that runs it. A start the worker refuses before it holds the agent
— the state the agent reads did not arrive, the factory failed, a claim was
refused — comes back as `agent_start_failed`: the master forgets the record it
wrote, answers the held frames as a start refused on this node, and the next
frame starts the agent again. An agent whose `onStart()` throws is not such a
start: the worker keeps it, writes the throw down loudly, finishes the rest of
the start and reports `agent_started` — so the agent takes messages and the
frames held for it arrive. What a throwing `onStart()` ought to mean for the
framework at large is a question nobody has answered yet; what it must not mean
is an agent that is alive and unreachable.

**The hold for a start under way ends on a fact, not on a clock** (HIL-1040).
However long the start takes — waiting on the state it reads, waiting on a
monopolistic worker raised for it — the frame waits with it, because every way
that start can end reaches the master: `agent_started` delivers the frame,
`agent_start_failed` answers it as a start refused here, the death of the worker
hosting the agent answers it as a host that went down — never redelivers it,
because a start that killed one worker would kill the next — and the departure of
whoever asked drops it unanswered, whether that is a browser closing its socket
or an operator's command giving up on its own window. One hold keeps a deadline,
and it is the one with no report coming: a frame that met an empty address is
answered after `AgentConstants::START_DEADLINE_SECONDS` plus one second the way
a dropped frame always was — a page with its subscription error, an operator
with a refusal, a push with a log line — because nothing anywhere reports "this
agent could not be placed". That deadline comes off the moment the agent does
turn up starting here. The hold lives in the master's memory and dies with it:
delivery across a node that fell over is HIL-347.

The price of ending on a fact is that a start which ends without one strands its
frames: a worker wedged in `onStart()` forever, and an agent stopped in the
middle of a start that a live worker was still running. A page and a command are
taken off the hold when whoever asked goes away; a push has nobody to leave and
simply accumulates. What a stop should mean for a frame held for the agent being
stopped is not decided — nobody has been asked yet, and the freeze, which stops
every agent at once, is where the question really belongs.

**Three things must agree before an agent is stopped**, and the count is on the
worker side, where the agent lives:

1. *nothing addressed it* for longer than the window. Addressing is a frame
   meant for this agent — an action, a page subscribe, a handshake, an
   agent-to-agent signal, a cron tick. The `db_sync` / `rt_sync` broadcasts go
   to everybody and are not addressing;
2. *it holds no live subscription*. An open tab keeps its agent alive, because
   the owner of an instance is the only one who can carry somebody else's write
   to that screen — stopping it under a live subscriber would silently cancel
   the push guarantee. The clock restarts when the LAST subscription is dropped,
   not when the agent last spoke;
3. *the agent itself says it is free*. `hasWorkInFlight()` defaults to false;
   override it in an agent that runs a long job with nobody talking to it:

```php
public function hasWorkInFlight(): bool
{
    return $this->importInProgress;
}
```

The stop is the ordinary one — `onStop()`, truth sources handed back, the
daemon told — and never a path of its own. Under protected mode nothing is
stopped for idleness: the freeze has already refused starts, and stopping under
it would kill what the freeze undertook to preserve. On a cluster the node that
hosted the agent tells the leader it stopped, so the placement map stops naming
a host the agent has left; the next frame to that agent places and starts it
again.

**What being stopped costs the agent.** Its truth-source grants are gone while
it is down, and it re-reads its own state in `onStart()` when it comes back —
the same thing any instance agent does today.

## Registration

1. Add constant to `AgentType` class
2. Create `AgentDaemon` subclass (for daemon-side routing)
3. Register worker and daemon classes in `Hilos::AGENTS` via `AgentRegistryKey`
4. Delegate `AgentManager` / `AgentManagerDaemon` creation to `TopologyAgentFactory`
6. Add direct agent-to-agent signal names to `AGENT_SIGNALS`, or keep dynamic
   payload-dependent routes in `SignalRouter`
