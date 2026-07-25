# demo/chat — Agent Index

Chat demo navigation for AI agents. Read framework index first: [/agents.md](../../agents.md).

This demo's own documentation lives under [spec/](spec/) — how *this* demo works.
Framework rules stay in `/docs/agents/**`; nothing here overrides them.

---

## Agents

| File | Read when... |
|---|---|
| [agents/chat-agent.md](spec/agents/chat-agent.md) | working with ChatAgent: handshake, messages, files, user admin, truth source |
| [agents/library-agent.md](spec/agents/library-agent.md) | working with LibraryAgent: admin CRUD for bots and moderator prompt pieces |
| [agents/bot-agent.md](spec/agents/bot-agent.md) | working with BotAgent: bot lifecycle, LLM messages, per-bot indexing |
| [agents/moderator-agent.md](spec/agents/moderator-agent.md) | working with ModeratorAgent: LLM moderation, queue, provider config |
| [agents/context-analyzer-agent.md](spec/agents/context-analyzer-agent.md) | working with ChatContextAnalyzerAgent: chat summary, context for bots |
| [agents/demo-hilos-agents.md](spec/agents/demo-hilos-agents.md) | working with /hilos/* pages: dashboard, settings, guardian, analytics, logs |

## Pages

| File | Read when... |
|---|---|
| [pages/main-page.md](spec/pages/main-page.md) | subscription flow, initial state, message/file actions |
| [pages/moderator-page.md](spec/pages/moderator-page.md) | moderator console, profile, user, admin, hilos system pages |

## Data Flow

| File | Read when... |
|---|---|
| [data-flow/ws-handshake.md](spec/data-flow/ws-handshake.md) | tracing a new connection from TCP to initial state delivery |
| [data-flow/message-flow.md](spec/data-flow/message-flow.md) | tracing a text message from user input to broadcast |
| [data-flow/file-upload-flow.md](spec/data-flow/file-upload-flow.md) | binary upload: init, binary frames, progress, quarantine |
| [data-flow/file-moderation-flow.md](spec/data-flow/file-moderation-flow.md) | file moderation after upload, approve/reject, cleanup |
| [data-flow/attachment-serving.md](spec/data-flow/attachment-serving.md) | serving an attachment back to the browser — cookie auth, same-origin, X-Accel-Redirect, render-by-mimeType |

## Runtime State

| File | Read when... |
|---|---|
| [runtime/connection.md](spec/runtime/connection.md) | per-connection state: acceptKey, userId, upload session, mod UI |
| [runtime/chat-user-state.md](spec/runtime/chat-user-state.md) | per-user state: pending text moderation |
| [runtime/chat-context.md](spec/runtime/chat-context.md) | shared chat summary for bots (ChatContextAnalyzerAgent output) |

## AI / Moderation

| File | Read when... |
|---|---|
| [ai/ollama-config.md](spec/ai/ollama-config.md) | Ollama setup, env vars, GPU, model selection |
| [ai/moderation-settings.md](spec/ai/moderation-settings.md) | DB settings for moderation, prompt pieces, runtime config |

## Issues

| File | Read when... |
|---|---|
| [known-issues.md](spec/known-issues.md) | before fixing bugs or implementing features — check known gaps first |

---

## Key rules for this project

1. `ChatAgent` is the **only** truth source for: `events`, `eventAttachments`, `users`, `connections`, `userStates`, `attachmentDrafts`
2. `LibraryAgent` is the **only** truth source for admin library DB collections: `bots`, `moderatorPromptPieces`
3. `ChatContextAnalyzerAgent` is the only truth source for `chatContexts`
4. File upload state lives on `Connection` (per-connection), text moderation on `ChatUserState` (per-user)
5. All LLM calls are **async** — never block in `onTick()`
6. Moderation always goes through `ModeratorAgent` — `ChatAgent` never calls LLM directly

## Signal flow summary

```
WS client → daemon → SignalRouter → worker → agent → onSignal*()
agent → Hilos::$sr->queueSignal() → daemon dispatch → WS client or other agent
```

## Tech stack

- Backend: PHP 8.4, Hilos framework, MySQL
- Frontend: Vue 3 + TypeScript, Vite/vite-ssg, Pinia
- LLM: Ollama (local) or OpenAI-compatible API
- Docker: all services containerized
