---
name: hilos-agent-system
description: Add, modify, or review Hilos agents, AgentDaemon classes, AgentType constants, AgentManager factories, onStart/onTick/onStop hooks, signal handlers, truth sources, monopolistic agents, and long-running agent workflows. Use when creating a new agent type or changing agent lifecycle behavior.
---

# Hilos Agent System

Use this skill for agent business logic and registration work. Start by reading `agents.md`, then load only the docs relevant to the agent task.

## Read First

- Adding a new agent type: `docs/agents/agent-system/adding-agent.md`
- Writing or reviewing `onTick()`: `docs/agents/agent-system/ontick-rule.md`
- Truth sources, shared state, long operations: `docs/agents/agent-system/monopolistic-agent.md`
- Agent lifecycle and signal methods: `docs/agents/architecture/agent-lifecycle.md`
- Asking for a node freeze before a destructive operation: `docs/agents/architecture/protected-mode.md`
- Blocking anti-patterns: `docs/agents/antipatterns/blocking-in-ontick.md`
- Signal routing changes: use `$hilos-signals`

## Workflow

1. Decide whether the work is a new agent, an existing agent behavior change, or lifecycle cleanup.
2. For a new agent, add the `AgentType` constant, Agent class, AgentDaemon class,
   `Hilos::AGENTS` entry (`WORKER`, `DAEMON`, optional `INDEXED`),
   `TopologyAgentFactory` delegation in managers, and `AGENT_SIGNALS` or
   `SignalRouter` routes.
   For indexed multi-instance agents, declare
   `AGENT_SIGNALS[$signal][AgentSignalConfigKey::INDEX_FIELD]` to route by
   payload field declaratively — do not override `SignalRouter::getDestinations()`
   for this case.
3. Keep `onStart()` for registration/initialization, `onTick()` for tiny incremental work, and `onStop()` for cleanup.
4. Move long or blocking work out of `onTick()` and signal handlers.
5. In named signal handlers, omit empty `default` branches for intentionally
   ignored shared-broadcast names and document the ignore contract in PHPDoc.
6. Register and unregister truth sources in matching lifecycle hooks, and check
   `requiresClusterLeadership()` against what the agent registers: an RT truth
   source is unique for the whole cluster, not per node, so an agent that
   registers one keeps the default `true` and runs on the leader alone. An agent
   that returns `false` runs on every node and must therefore own no RT
   collection — a second owner splits it, and all the daemon can do about that is
   refuse the other node's writes and log
   `RT collection <key> has truth sources on two nodes`.
7. Add focused tests through composer scripts when behavior changes.
8. After registering the agent or its `AGENT_SIGNALS` in the topology, update the
   demo's `*TopologyRegistryTest` snapshot and run its `test:unit` — a shared
   cross-ticket guard. See `docs/agents/app-topology.md` step 12.

## Hard Rules

- Never run `git commit` or `git push`.
- Never add routing logic directly inside agents; use topology declarations or `SignalRouter`.
- Routing for indexed multi-instance agents must stay declarative in `AGENT_SIGNALS` with `AgentSignalConfigKey::INDEX_FIELD`; do not add `switch` by signal name inside agents or routers for this purpose.
- Never let non-truth-source agents write to a DB/RT collection they do not own.
- Never register an RT truth source in an agent with
  `requiresClusterLeadership()` returning `false`; a per-node agent may read a
  shared collection, and changes what it does not own by signalling the owner.
- Never add Repository or Service layers above `DbCollection`.
