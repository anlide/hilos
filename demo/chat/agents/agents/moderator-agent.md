# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Regular

Handles all LLM-based content moderation. Runs in regular worker, communicates via agent-to-agent signals.

## Responsibilities

- Moderate **text messages** from users → `MODERATE_REQUEST` → sends `MODERATION_RESULT` back to `ChatAgent`
- Moderate **bot messages** → `MODERATE_BOT_REQUEST` → sends `MODERATION_BOT_RESULT` back to `ChatAgent`
- Moderate **uploaded files** (by description) → `MODERATE_FILE_REQUEST` → sends `MODERATION_FILE_RESULT` back to `ChatAgent`

## LLM client

Uses async `AsyncChatLLMInterface`. Provider selected at startup:
- External (OpenAI-compatible) if `ChatSettingsHelper::getModerationProviderIsExternal()`
- Local Ollama otherwise (URL + model from Settings via `ChatSettingsHelper`)

Polled in `onTick()` via `$this->chatClient->tick()`.

## Queue

Requests are queued in `$pendingQueue`. Only one request is in flight at a time (`$currentPending`).
New requests append to queue; when current finishes, next is dequeued.

## Page

`ModeratorAgent` handles `PageConstants::MODERATOR` page subscription — provides moderation console data.

## Signal flow

```
ChatAgent ──MODERATE_REQUEST──▶ ModeratorAgent
                                    │ LLM call
                                    ▼
ChatAgent ◀──MODERATION_RESULT──── ModeratorAgent
```

## Settings

Model and URL are read from DB settings (via `ChatSettingsHelper`) on each new LLM client creation.
Settings change: restart moderator agent or reinitialize client.
