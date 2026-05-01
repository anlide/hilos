# BotAgent

**Type:** `AgentType::BOT` (`'bot'`) | **Worker:** Regular | **Multi-instance:** yes (one per bot, indexed by botId)

Each active bot gets its own `BotAgent` instance, started on boot or by `LibraryAgent` after bot create/reactivation.

## Responsibilities

- Periodically generate bot messages using LLM
- Send messages through `ModeratorAgent` before posting
- Announce bot join/leave to frontend via runtime status sync

## Lifecycle

1. `LibraryAgent` sends `BOT_AGENT_START` after creating or reactivating an active bot
2. Signal routing targets `BotAgent:botId` → framework starts if not running
3. `BotAgent::onStart()` — registers runtime status truth source, reads bot config from DB
4. `BotAgent::onTick()` — polls LLM client, checks timing for next message
5. When message ready: `sendToAgent(MODERATE_BOT_REQUEST, data)` → `ModeratorAgent`
6. `ModeratorAgent` responds with `MODERATION_BOT_RESULT` → `ChatAgent` broadcasts if approved
7. `selfStop()` when bot is disabled or deleted

## Agent index

Bot agents use `botId` (string) as `agentIndex`:
```
Agent ID: "bot:42"
```

## LLM integration

Uses async LLM client (Ollama or OpenAI depending on settings).
Client polled in `onTick()` — no blocking.

## Page

`BotAgent` handles `PageConstants::BOT` page subscription — provides bot detail data.
