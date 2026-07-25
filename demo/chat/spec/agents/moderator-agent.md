# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Regular

Handles LLM-based user content moderation. Runs in a regular worker and communicates via agent-to-agent signals.

## Responsibilities

- Discover user outbound messages from runtime connection state and send `MODERATION_RESULT`.
- Discover user-initiated display-name changes from runtime connection state and send `RENAME_MODERATION_RESULT`.

Uploaded files are not moderated through a separate signal. They are attachment drafts included in a normal outbound message moderation request.

## LLM Client

Uses async `AsyncChatLLMInterface`. Provider selected at startup:

- External (OpenAI-compatible) if `chat_moderation_provider` is `external`.
- Local Ollama otherwise (URL + model from `Hilos::$setting`).

Polled in `onTick()` via `$this->chatClient->tick()`.

## In-flight state

Requests are not queued inside the agent. `MainPage::handleMessage()` writes
connection-local runtime state, and `ModeratorAgent::onTick()` starts the first
connection whose outbound moderation phase is `checking`.

Only one request is in flight at a time, tracked by accept key, request type,
request value, user id, and moderation timestamp. After a result signal is
sent, the marker is kept until ChatAgent applies the result back to runtime
state, which prevents duplicate moderation starts for the same connection.

## Signal Flow

```
MainPage runtime state -> ModeratorAgent
                         | LLM call
                         v
MainPage <--MODERATION_RESULT---- ModeratorAgent
ProfilePage <--RENAME_MODERATION_RESULT---- ModeratorAgent
```

## Settings

Model and URL are read from DB settings through `Hilos::$setting` on each new LLM client creation.
Moderator prompt pieces are read from `ChatDbContext::moderatorPromptPieces`; CRUD ownership belongs to `LibraryAgent`.
Settings change: restart moderator agent or reinitialize client.
