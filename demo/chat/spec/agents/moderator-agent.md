# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Regular

Handles LLM-based user content moderation. Runs in a regular worker and communicates via agent-to-agent signals.

## Responsibilities

- Discover user outbound messages from runtime connection state and send `MODERATION_RESULT`.
- Discover user-initiated display-name changes from runtime connection state and send `RENAME_MODERATION_RESULT`.

Uploaded files are not moderated through a separate signal. They are attachment drafts included in a normal outbound message moderation request.

## LLM Client

Uses async `AsyncChatLLMInterface`, built once in the constructor from the
`chat.moderation` profile (`Hilos::$llm`). Provider selected at startup:

- External (OpenAI-compatible) if `chat_moderation_provider` is `external`.
- Local Ollama otherwise. Its address is the first of these that is not empty:
  1. the `chat_moderation_url` setting;
  2. `default_bot_url`, the setting `chat_moderation_url` defaults to inside the
     settings catalog (`backend/Database/Settings/SettingsCatalog.php`) — so a
     non-empty `default_bot_url` is the moderator's address too, and env is not
     consulted;
  3. the role's `CHAT_MODERATION_URL` from env;
  4. the global `LLM_LOCAL_URL` from env.

  Steps 1–2 are one read of `Hilos::$setting`; steps 3–4 are the address the
  env-resolved profile already carries. The exception is a role env resolved as
  `external` while the provider setting keeps it local: that profile's address
  is the external endpoint, so step 3 is skipped.

There is no test client. On the test stand `CHAT_MODERATION_URL` points at the
stand gateway's model channel, so the agent moderates through the same router and
the same client as in production, and a verdict is whatever a spec dictated
(`docs/agents/stand-services.md`; `tests/e2e/helpers/moderation.ts`). A call no
spec dictated a verdict for is refused by the model and ends as
`service_unavailable`.

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
A URL setting that resolves empty — `chat_moderation_url` and its default `default_bot_url` both — is not an address: the role keeps the one env gave it, in the order under LLM Client above.
Moderator prompt pieces are read from `ChatDbContext::moderatorPromptPieces`; CRUD ownership belongs to `LibraryAgent`.
Settings change: restart moderator agent or reinitialize client.
