---
name: hilos-signals
description: Work with Hilos signal routing, SignalRouter declarations, Hilos::PAGE_ROUTES topology, signal payload DTOs, page subscriptions, group subscriptions, sendToUser, sendToGroup, sendToAgent, client-server actions, server-client signals, and signal delivery debugging. Use when adding, changing, or tracing Hilos signal flow.
---

# Hilos Signals

Use this skill for every change that affects signal shape, route, subscription, or delivery. Start with `agents.md`, then read the matching signal document.

## Read First

- Routing rules and tracing signal paths: `docs/agents/signals/routing.md`
- App topology for page subscription routing:
  `docs/agents/app-topology.md`
- Page/group subscriptions and send helpers: `docs/agents/signals/subscriptions.md`
- Payload DTOs and agent-to-agent signals: `docs/agents/signals/dto-convention.md`
- Frontend action/signal contract: `docs/agents/frontend-sdk/backend-contract.md`
- Page action handler style: `docs/agents/code-style/page-action-handlers.md`
- Named signal handler style: `docs/agents/code-style/signal-handlers.md`

## Workflow

1. Identify the signal source and destination: WS, agent, DB sync, RT sync, cron, or system.
2. For project page subscription routing, update `Hilos::PAGE_ROUTES` through
   `docs/agents/app-topology.md`; otherwise add or update declarative routing
   in `SignalRouter`.
3. Route named signal handlers with `switch ($name)` and explicit cases.
4. Omit empty `default` branches in partial shared-broadcast handlers; document
   the ignore contract in PHPDoc instead.
5. Define or update payload DTOs for new wire contracts.
6. Keep serialization roundtrips explicit with `toArray()` and `fromArray()` where applicable.
7. If the signal crosses worker-to-daemon IPC, add backend roundtrip coverage.
8. If the signal reaches frontend code, add or update the TypeScript parser tests.

## Hard Rules

- Never run `git commit` or `git push`.
- Keep routing declarative in `SignalRouter`.
- Keep project page subscription ownership in `Hilos::PAGE_ROUTES`.
- Do not hide subscription or delivery decisions inside unrelated business logic.
- Preserve envelope metadata when DTOs cross worker and daemon boundaries.
