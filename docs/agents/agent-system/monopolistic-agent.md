# Monopolistic Agent

A **monopolistic agent** runs in a dedicated worker process — one instance for the entire cluster.

## When to use

- Agent owns **shared mutable state** that must be consistent across workers
- Agent performs **long or blocking operations** (LLM inference, heavy queries) — the only shape allowed for them: [../antipatterns/child-process-for-long-work.md](../antipatterns/child-process-for-long-work.md)
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

A monopolistic agent is usually the **owner** of a DB or RT collection — the one
writer whose copy the collection is, with every other agent reading it or asking
the owner for a change by signal. A collection has exactly one full owner. How
ownership is declared, and why a claim is also the owner's reader interest, is
[truth-source.md](../architecture/truth-source.md).

## Regular agent

Regular agents run in regular workers, multiple instances can exist.
They handle per-connection or per-page logic.
They must not write to collections owned by a monopolistic truth source.
