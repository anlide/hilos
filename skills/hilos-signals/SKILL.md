---
name: hilos-signals
description: Work with Hilos signal routing, SignalRouter declarations, page subscription topology, signal payload DTOs, page subscriptions, group subscriptions, sendToUser, sendToGroup, sendToAgent, client-server actions, server-client signals, and signal delivery debugging. Use when adding, changing, or tracing Hilos signal flow, and when a page needs one more piece of data to render and you are deciding which frame carries it.
---

# Hilos Signals

Use this skill for every change that affects signal shape, route, subscription, or delivery. Start with `agents.md`, then read the matching signal document.

## Read First

- Routing rules and tracing signal paths: `docs/agents/signals/routing.md`
- App topology for page subscription routing:
  `docs/agents/app-topology.md`
- Page/group subscriptions, send helpers, and the rule that one page
  subscription answers with everything the page renders:
  `docs/agents/signals/subscriptions.md`
- Payload DTOs and agent-to-agent signals: `docs/agents/signals/dto-convention.md`
- You just wrote a fact and someone is looking at a screen it devalues right
  now — whether the server owes that screen a move, and which frame moves it:
  `docs/agents/signals/screen-invalidation.md`
- Page action handler style: `docs/agents/code-style/page-action-handlers.md`
- Named signal handler style: `docs/agents/code-style/signal-handlers.md`

## Workflow

1. Identify the signal source and destination: WS, agent, DB sync, RT sync, cron, or system.
2. For project page subscription, page signal, action, or direct agent-signal
   ownership, update `SUBSCRIPTION_AGENT_TYPE`, `SIGNALS`, `ACTIONS`,
   `AGENT_SIGNALS`, and `Hilos::AGENTS` through `docs/agents/app-topology.md`;
   keep payload-dependent routes and the project facade hook in `SignalRouter`.
   For indexed multi-instance agents, set
   `AGENT_SIGNALS[$signal][AgentSignalConfigKey::INDEX_FIELD]` instead of
   overriding `SignalRouter::getDestinations()`. See
   `docs/agents/signals/routing.md` "Indexed agent signals".
3. Route named signal handlers with `switch ($name)` and explicit cases.
4. Omit empty `default` branches in partial shared-broadcast handlers; document
   the ignore contract in PHPDoc instead.
5. Define or update payload DTOs for new wire contracts.
6. Keep serialization roundtrips explicit with `toArray()` and `fromArray()` where applicable.
7. If the signal crosses worker-to-daemon IPC, add backend roundtrip coverage.
8. If the signal reaches frontend code, add or update the TypeScript parser tests.
9. When the change writes a fact rather than answering a request, ask who is on
   an open screen the fact devalues. If that screen declared what you changed as
   its own source, the browser fan-out already carries it and you send nothing;
   if it did not, and the next action there now fails, read
   `docs/agents/signals/screen-invalidation.md` before choosing a frame.
10. After changing a topology registry (`ACTIONS`, `SIGNALS`, `AGENT_SIGNALS`,
   `Hilos::AGENTS`, and peers), update the demo's `*TopologyRegistryTest`
   snapshot and run its `test:unit` — the snapshot is a shared cross-ticket
   guard. See `docs/agents/app-topology.md` step 12.

## Hard Rules

- Never run `git commit` or `git push`.
- Keep routing declarative in `SignalRouter`.
- Keep project page subscription ownership on page `SUBSCRIPTION_AGENT_TYPE`,
  page signal ownership on page `SIGNALS`, and direct agent signal ownership on
  agent `AGENT_SIGNALS`.
- Do not duplicate page subscription ownership in project SignalRouter code;
  `SignalRouter` reads page owners from `Hilos::getPageRoutes()`.
- Do not hide subscription or delivery decisions inside unrelated business logic.
- Preserve envelope metadata when DTOs cross worker and daemon boundaries.
- Do not add a signal or an action a page waits for in order to draw its first
  render. One page subscription answers in one `page_response`; data the page
  is missing is read in its own `buildPagePayload()` / `onSubscribe()`.
