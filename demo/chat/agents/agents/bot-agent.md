# BotAgent

**Type:** `AgentType::BOT` (`'bot'`) | **Worker:** Regular | **Multi-instance:** yes (one per bot, indexed by botId)

Each active bot gets its own `BotAgent` instance, started by `ChatAgent`.

## Responsibilities

- Periodically generate bot messages using LLM
- Send messages through `ModeratorAgent` before posting
- Announce bot join/leave to frontend via `ChatAgent`

## Lifecycle

1. `ChatAgent` receives `BOT_AGENT_START` signal
2. Sends agent signal to `BotAgent:botId` → framework starts if not running
3. `BotAgent::onStart()` — registers truth source for bot's page, reads bot config from DB
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
