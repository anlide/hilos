# ChatContextAnalyzerAgent

**Type:** `AgentType::CHAT_CONTEXT_ANALYZER` (`'chat_context_analyzer'`) | **Worker:** Monopolistic

Maintains a shared summary of the chat context for all bots to consume.

## Responsibilities

- Listen to `DB_SYNC_CREATED` / `DB_SYNC_UPDATED` on events table → accumulate context
- Periodically send LLM request to summarize the current chat state
- Write result to `ChatRtContext::chatContexts` collection (`ChatContext` item with ID `'main'`)
- `BotAgent` reads `ChatContext` to include in its LLM prompt

## Truth source

Owns `ChatRtContext::chatContexts`. Only this agent writes to it.

## LLM client

Configured via env vars:
- `CHAT_CONTEXT_ANALYZER_PROVIDER` — `external` or local
- `CHAT_CONTEXT_ANALYZER_URL` — Ollama URL (falls back to `LLM_LOCAL_URL`)
- `CHAT_CONTEXT_ANALYZER_MODEL` — model name (falls back to `ChatLLMConstants::MODEL_CONTEXT_ANALYZER`)

## Pending summarize flag

`$pendingSummarize = true` is set when new events arrive.
`onTick()` checks flag — starts LLM call when conditions met (throttling, no active request).

## ChatContext RT item

```
ChatContext::ID_MAIN = 'main'  (single instance)
Fields: summary, updatedAt, eventCount
```

## Initialization

`onStart()`: if `chatContexts` collection is empty → calls `chatContexts->actions->init()` to create empty context.
