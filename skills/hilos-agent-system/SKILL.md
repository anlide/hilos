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
- Blocking anti-patterns: `docs/agents/antipatterns/blocking-in-ontick.md`
- Signal routing changes: use `$hilos-signals`

## Workflow

1. Decide whether the work is a new agent, an existing agent behavior change, or lifecycle cleanup.
2. For a new agent, add the `AgentType` constant, Agent class, AgentDaemon class, worker factory registration, daemon factory registration, and `SignalRouter` rules.
3. Keep `onStart()` for registration/initialization, `onTick()` for tiny incremental work, and `onStop()` for cleanup.
4. Move long or blocking work out of `onTick()` and signal handlers.
5. In named signal handlers, omit empty `default` branches for intentionally
   ignored shared-broadcast names and document the ignore contract in PHPDoc.
6. Register and unregister truth sources in matching lifecycle hooks.
7. Add focused tests through composer scripts when behavior changes.

## Hard Rules

- Never run `git commit` or `git push`.
- Never add routing logic directly inside agents; use `SignalRouter`.
- Never let non-truth-source agents write to a DB/RT collection they do not own.
- Never add Repository or Service layers above `DbCollection`.
