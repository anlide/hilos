---
name: hilos-agent-system
description: Add, modify, or review Hilos agents, AgentDaemon classes, AgentType constants, AgentManager factories, onStart/onTick/onStop hooks, signal handlers, truth sources, monopolistic agents, and long-running agent workflows. Use when creating a new agent type or changing agent lifecycle behavior, and when declaring an agent that answers for a whole set of an entity rather than for one instance, where how many of it run and which node runs it are two separate questions.
---

# Hilos Agent System

Use this skill for agent business logic and registration work. Start by reading `agents.md`, then load only the docs relevant to the agent task.

## Read First

- Adding a new agent type: `docs/agents/agent-system/adding-agent.md`
- Writing or reviewing `onTick()`: `docs/agents/agent-system/ontick-rule.md`
- Truth sources, shared state, long operations: `docs/agents/agent-system/monopolistic-agent.md`
- Agent lifecycle and signal methods: `docs/agents/architecture/agent-lifecycle.md`
- An agent that holds a whole entity's set — one entity one library, and which
  of the two placement axes is yours: `docs/agents/architecture/entity-libraries.md`
- The agent writes a fact somebody has on screen right now — when the server
  owes that screen a move, and which frame moves it:
  `docs/agents/signals/screen-invalidation.md`
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
   the registry's `AgentRegistryKey::SCOPE` against what the agent registers: an
   RT truth source is unique for the whole cluster, not per node, so an agent
   that registers one keeps the default `AgentScope::CLUSTER` and exists once —
   hosted by the leader, or placed by the policy where `AgentPlacement::POLICY`
   is declared. An agent declared `AgentScope::NODE` runs on every node and must
   therefore own no RT collection — a second owner splits it, and all the daemon
   can do about that is refuse the other node's writes and log
   `RT collection <key> has truth sources on two nodes`.
7. When the agent answers for a *set* — a list, a search, a create with nothing
   to address yet, a command with no addressee — read
   `docs/agents/architecture/entity-libraries.md` before writing its registry
   entry. One entity gets one such agent, and how many instances run is a
   separate question from which node runs them.
8. When a handler or a tick writes a fact instead of answering the request that
   asked for it, ask who is on an open screen that fact devalues. A screen that
   declared what you changed as its own source is served by the browser fan-out;
   one that did not, and whose next action now fails, is moved by the server —
   see `docs/agents/signals/screen-invalidation.md`.
9. Add focused tests through composer scripts when behavior changes.
10. After registering the agent or its `AGENT_SIGNALS` in the topology, update the
   demo's `*TopologyRegistryTest` snapshot and run its `test:unit` — a shared
   cross-ticket guard. See `docs/agents/app-topology.md` step 12.

## Hard Rules

- Never run `git commit` or `git push`.
- Never add routing logic directly inside agents; use topology declarations or `SignalRouter`.
- Routing for indexed multi-instance agents must stay declarative in `AGENT_SIGNALS` with `AgentSignalConfigKey::INDEX_FIELD`; do not add `switch` by signal name inside agents or routers for this purpose.
- Never let non-truth-source agents write to a DB/RT collection they do not own.
- Never register an RT truth source in an agent declared `AgentScope::NODE`; a
  per-node replica may read a shared collection, and changes what it does not own
  by signalling the owner.
- Never add Repository or Service layers above `DbCollection`.
