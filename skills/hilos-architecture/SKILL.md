---
name: hilos-architecture
description: Work with Hilos daemon, worker, agent lifecycle, event loop, cron, startup, shutdown, server registration, sockets, I/O, and blocking behavior. Use when modifying process boundaries, lifecycle hooks, event-loop code, worker forking, daemon registration, cron handling, or cross-process behavior in a Hilos project.
---

# Hilos Architecture

Use this skill only inside a Hilos repository. Start by reading `agents.md`, then load the narrow architecture document that matches the task.

## Read First

- Daemon startup, cron, shutdown, server registration: `docs/agents/architecture/daemon-lifecycle.md`
- Worker processes, forking, worker message handling: `docs/agents/architecture/worker-lifecycle.md`
- Agent creation, lifecycle hooks, signal sending: `docs/agents/architecture/agent-lifecycle.md`
- Sockets, event loop, I/O, blocking operations: `docs/agents/architecture/event-loop.md`
- Freezing a node for a destructive operation: `docs/agents/architecture/protected-mode.md`
- Blocking risks in handlers or ticks: `docs/agents/antipatterns/blocking-in-ontick.md`

## Workflow

1. Identify the boundary being changed: daemon, worker, agent, frontend connection, or DB/RT sync.
2. Read the matching architecture document before editing.
3. Keep lifecycle setup and teardown symmetric.
4. Keep event-loop, `onTick()`, and signal-handler work short and non-blocking.
5. Keep signal routing declarative in `SignalRouter`; do not hide routing decisions inside agents.
6. If the change affects tests or CLI commands, use `$hilos-testing-cli`.

## Hard Rules

- Never run `git commit` or `git push`.
- Never block in `onTick()`; it must complete in less than 0.1s.
- Only the truth source agent writes to its DB/RT collection.
- All PHP files must use `declare(strict_types=1)`.
