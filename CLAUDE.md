# Hilos Claude Instructions

Read `agents.md` before making Hilos code changes. It is the canonical agent index for this repository.

## Critical Rules

- Never run `git commit`.
- Never run `git push`.
- Read the task-specific document listed in `agents.md` before editing code.
- Use composer scripts for tests; do not run host `phpunit` directly.

## Task Routing

- Daemon, worker, lifecycle, event loop: `docs/agents/architecture/*`
- Agents, `onStart()`, `onTick()`, `onStop()`, truth sources: `docs/agents/agent-system/*`
- Signals, DTOs, routing, subscriptions: `docs/agents/signals/*`
- Entities, objects, DbCollection, migrations: `docs/agents/orm/*`
- Runtime state and `Hilos::$rt`: `docs/agents/runtime/*`
- Frontend SDK, Vue pages, actions/signals, modal edits: `docs/agents/frontend-sdk/*`
- Testing and CLI commands: `docs/agents/testing.md` and `docs/agents/cli/commands.md`
- Code style: `docs/agents/code-style/*`

The Codex skill wrappers in `skills/hilos-*` are secondary navigation aids. The shared source of truth is `agents.md` plus `docs/agents/*`.
