# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Regular

Handles LLM-based content moderation. Runs in a regular worker and communicates via agent-to-agent signals.

## Responsibilities

- Moderate user outbound messages (`content` plus attachment metadata) -> `MODERATE_REQUEST` -> sends `MODERATION_RESULT`.
- Moderate bot messages -> `MODERATE_BOT_REQUEST` -> sends `MODERATION_BOT_RESULT`.

Uploaded files are not moderated through a separate signal. They are attachment drafts included in a normal outbound message moderation request.

## LLM Client

Uses async `AsyncChatLLMInterface`. Provider selected at startup:

- External (OpenAI-compatible) if `ChatSettingsHelper::getModerationProviderIsExternal()`.
- Local Ollama otherwise (URL + model from Settings via `ChatSettingsHelper`).

Polled in `onTick()` via `$this->chatClient->tick()`.

## Queue

Requests are queued in `$pendingQueue`. Only one request is in flight at a time (`$currentPending`).
New requests append to queue; when current finishes, next is dequeued.

If the LLM client cannot start a request, `ModeratorAgent` returns a `service_unavailable` result for that request and advances the queue.

## Signal Flow

```
ChatAgent --MODERATE_REQUEST--> ModeratorAgent
                                    | LLM call
                                    v
ChatAgent <--MODERATION_RESULT---- ModeratorAgent
```

## Settings

Model and URL are read from DB settings (via `ChatSettingsHelper`) on each new LLM client creation.
Moderator prompt pieces are read from `DbChatContext::moderatorPromptPieces`; CRUD ownership belongs to `LibraryAgent`.
Settings change: restart moderator agent or reinitialize client.
