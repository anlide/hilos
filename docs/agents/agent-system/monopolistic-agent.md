# Monopolistic Agent

A **monopolistic agent** runs in a dedicated worker process — one instance for the entire cluster.

## When to use

- Agent owns **shared mutable state** that must be consistent across workers
- Agent performs **long or blocking operations** (LLM inference, heavy queries)
- Agent is the **truth source** for DB or RT collections
- Only one logical instance should ever exist (e.g. `ChatAgent`, `ChatContextAnalyzerAgent`)

## How to set up

In `WorkerManager` subclass, set `$isMonopolistic = true` for the worker that hosts the agent.
The `WorkerServer` will ensure only one monopolistic worker is spawned.

```php
class MyMonopolisticWorkerManager extends WorkerManager {
    protected bool $isMonopolistic = true;
}
```

## Truth source pattern

Monopolistic agents typically register as **truth source** — the authoritative writer for a DB or RT collection:

```php
public function onStart(): void {
    TruthSourceRegistry::register(ChatDbContext::events, true, $this->getId());
    RtTruthSourceRegistry::register(ChatRtContext::connections, true, $this->getId());
}

public function onStop(): void {
    // Cleanup owned state here. WorkerManager unregisters truth sources after this hook.
}
```

Only the truth source should **write** to its collections. Other agents read.

## Regular agent

Regular agents run in regular workers, multiple instances can exist.
They handle per-connection or per-page logic.
They must not write to collections owned by a monopolistic truth source.
