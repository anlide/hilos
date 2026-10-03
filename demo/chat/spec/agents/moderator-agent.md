# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Regular

Handles LLM-based user content moderation. Runs in a regular worker and communicates via agent-to-agent signals.

## Responsibilities

- Discover user outbound messages from runtime connection state and send `MODERATION_RESULT`.
- Discover user-initiated display-name changes from runtime connection state and send `RENAME_MODERATION_RESULT`.
- Discover completed profile-photo checks from `hilosProfilePhotoChecks`, send the image to the vision profile, and return `HILOS_PROFILE_PHOTO_VERDICT` to the users library.

Attachments on a chat message are described by name, type and size only. A profile
photo is a separate request: the users library opens a check only for a completed
upload, and the moderator reads its temporary JPEG and sends the bytes to the
vision model. `READS_RT` declares both the upload and photo-check collections;
`READS_DB` declares users for the current name in the photo prompt.

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

The photo client uses `chat.photo_moderation`, a separate env-backed profile with
the `qwen2.5vl:3b` local default. It has no DB-settings override. The same
`AsyncChatLLMInterface` drives both clients; `onTick()` ticks each without
blocking. The test stand sends both roles to its model channel.

## In-flight state

Requests are not queued inside the agent. Each tick finds a pending outbound
message first, a pending rename second, or the oldest completed profile-photo
check third. Only one model request is in flight at a time.

Only one request is in flight at a time, tracked by accept key, request type,
request value, user id, and moderation timestamp. After a result signal is
sent, the marker is kept until ChatAgent applies the result back to runtime
state, which prevents duplicate moderation starts for the same connection. For a
photo, the snapshot includes its client upload id and `startedAt`; removing or
replacing the check cancels an obsolete model request.

## Signal Flow

```
MainPage runtime state -> ModeratorAgent
                         | LLM call
                         v
MainPage <--MODERATION_RESULT---- ModeratorAgent
ProfilePage <--RENAME_MODERATION_RESULT---- ModeratorAgent
UsersLibrary <--HILOS_PROFILE_PHOTO_VERDICT---- ModeratorAgent
```

## Settings

Model and URL are read from DB settings through `Hilos::$setting` on each new LLM client creation.
A URL setting that resolves empty — `chat_moderation_url` and its default `default_bot_url` both — is not an address: the role keeps the one env gave it, in the order under LLM Client above.
Moderator prompt pieces are read from `ChatDbContext::moderatorPromptPieces`; CRUD ownership belongs to `LibraryAgent`.
The `photo_rule` section supplies the vision prompt's rules. While it has no
pieces, the moderator allows ordinary photos, drawings and logos and blocks
nudity, graphic violence and hate symbols. The admin moderation table edits the
same section alongside message and name rules.
Settings change: restart moderator agent or reinitialize client.
