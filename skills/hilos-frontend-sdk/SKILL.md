---
name: hilos-frontend-sdk
description: Work with the Hilos frontend SDK, Vue pages, WebSocket connection lifecycle, acceptKey handling, reconnect behavior, client actions, server signals, page subscriptions, frontend signal parsers, and entity editing UI. Use when modifying frontend-server contracts or Hilos Vue page behavior.
---

# Hilos Frontend SDK

Use this skill for frontend SDK and Vue page work in Hilos. Start with `agents.md`, then read the relevant frontend document.

## Read First

- WebSocket lifecycle, `acceptKey`, reconnect: `docs/agents/frontend-sdk/websocket-connection.md`
- Client actions, server signals, page subscription: `docs/agents/frontend-sdk/backend-contract.md`
- Editing entities from Vue pages: `docs/agents/frontend-sdk/edit-in-modal.md`
- Page action handler routing/acks/errors: `docs/agents/code-style/page-action-handlers.md`
- Signal DTO conventions: use `$hilos-signals`

## Workflow

1. Identify whether the change is connection behavior, action sending, signal parsing, page subscription, or entity editing.
2. Keep the backend and frontend wire contract synchronized.
3. Add or update frontend parser tests for new or changed signals.
4. Add or update backend DTO roundtrip tests when signal payloads change.
5. For entity edits, use `Modal`; do not create inline edit forms on pages.
6. Run frontend tests through composer scripts from `$hilos-testing-cli`.

## Hard Rules

- Never run `git commit` or `git push`.
- Frontend edits go through `Modal` only.
- Do not bypass signal parsers for server-client payloads.
- Keep `acceptKey` and reconnect behavior consistent with the SDK contract.
