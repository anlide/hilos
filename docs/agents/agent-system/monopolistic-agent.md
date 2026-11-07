# Monopolistic Agent

A **monopolistic agent** runs in a dedicated worker process — one instance for the entire cluster.
It is never per-instance: a monopolistic agent started with an index is refused, because the node
raises one worker per monopolistic agent that finds none free and must not raise one per entity
(HIL-998, [../architecture/worker-lifecycle.md](../architecture/worker-lifecycle.md)).

## When to use

- Agent owns **shared mutable state** that must be consistent across workers
- Agent performs **long or blocking operations** (LLM inference, heavy queries) — the only shape allowed for them: [../antipatterns/child-process-for-long-work.md](../antipatterns/child-process-for-long-work.md)
- Agent is the **truth source** for DB or RT collections
- Only one logical instance should ever exist (e.g. `ChatAgent`, `ChatContextAnalyzerAgent`)

## How to set up

The agent's daemon proxy declares it: `requiresMonopolisticProcess()` returns `true`. The node
seats the agent in a monopolistic worker of its own and raises one when none is free
([../architecture/worker-lifecycle.md](../architecture/worker-lifecycle.md)); nothing is sized by
hand for it.

```php
final class MyAgentDaemon extends AbstractAgentDaemon
{
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
```

## Truth source pattern

A monopolistic agent is usually the **owner** of a DB or RT collection — the one
writer whose copy the collection is, with every other agent reading it or asking
the owner for a change by signal. For DB, each application-owned row has one
changing writer after creation: its row owner or an owner of a non-overlapping
set. A creator's `Add` alone is compatible; splitting `Update` and `Remove`
between writers is not. Non-overlapping owners need no full-table owner beside
them. RT keeps its one-full-owner rule and declared co-owner/receipt rules.
A collection the database writes by triggers has no application writer and no
agent claims it. How ownership is declared, and why a claim is also the
owner's reader interest, is [truth-source.md](../architecture/truth-source.md);
the trigger-written case is its
[A Collection Written Outside The Application](../architecture/truth-source.md#a-collection-written-outside-the-application).

## Regular agent

Regular agents run in regular workers, multiple instances can exist.
They handle per-connection or per-page logic.
They must not write to collections owned by a monopolistic truth source.

## Timing in a Dedicated Worker

A monopolistic worker follows the same tick and idle-wait rules as every other
worker: each `onTick()` must finish in under 0.1 seconds. A longer idle wait is
an explicit class allowance, granted only after the full obligation audit in
the [onTick rule](ontick-rule.md). A dedicated worker does not make blocking
tick work safe.
