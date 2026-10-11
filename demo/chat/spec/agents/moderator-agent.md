# ModeratorAgent

**Type:** `AgentType::MODERATOR` (`'moderator'`) | **Worker:** Monopolistic

Handles LLM-based user content moderation. The demo runs it in a monopolistic worker
(`ModeratorAgentDaemon::requiresMonopolisticProcess()` is true) and it communicates
via agent-to-agent signals.

## Responsibilities

- Discover user outbound messages from runtime connection state and send `MODERATION_RESULT`.
- After that text is allowed, draw a 512-pixel JPEG copy of each attached picture and send it to the vision client. A refusal names that picture. The worker raises `memory_limit` to the images budget for the draw and restores it on stop.
- Discover user-initiated display-name changes from runtime connection state and send `RENAME_MODERATION_RESULT`.
- Discover completed profile-photo checks from `hilosProfilePhotoChecks`, send the image to the vision profile, and return `HILOS_PROFILE_PHOTO_VERDICT` to the users library.

Attachments on a chat message are described to the text model by name, type and size only.
Once that text is allowed, each picture — an upload whose type starts with `image/` — is
drawn as a 512-pixel JPEG and sent to the vision client, one at a time, in attach order.
The first refusal names the picture and the rest are not checked. A profile
photo is a separate request: the users library opens a check only for a completed
upload, and the moderator reads its temporary JPEG and sends the bytes to the
vision model. `READS_RT` declares both the upload and photo-check collections;
`READS_DB` declares users for the current name in the photo prompt.

## LLM Client

Uses async `AsyncChatLLMInterface`, initially built in the constructor from the
`chat.moderation` profile (`Hilos::$llm`). Before each message or rename request,
the agent resolves that profile again. It replaces the client only when the
effective profile changes; the in-flight request keeps its original client.
The provider is selected from the current settings:

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

A message that carries pictures is one job until the verdict. The text is asked
first. When it is allowed and a picture remains, the verdict waits: the agent
draws the next picture and asks the vision client. A refusal, an unreadable
copy, or a failure sends the verdict at once and leaves the later pictures
unchecked.

Only one request is in flight at a time, tracked by accept key, request type,
request value, user id, and moderation timestamp. A picture check also remembers
the client upload id it is looking at. After a result signal is
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

Model, URL, provider, and timeout are read from DB settings through `Hilos::$setting`
before each new message or rename request. The typed accessor reads persisted
values from DB even while this worker holds an older setting object.
A URL setting that resolves empty — `chat_moderation_url` and its default `default_bot_url` both — is not an address: the role keeps the one env gave it, in the order under LLM Client above.
Moderator prompt pieces are read from `ChatDbContext::moderatorPromptPieces`; CRUD ownership belongs to `LibraryAgent`.
The `photo_rule` section supplies the vision prompt's rules. While it has no
pieces, the moderator allows ordinary photos, drawings and logos and blocks
nudity, graphic violence and hate symbols. The pieces currently come from the
seed; no admin editor is mounted.
After a saved setting changes, the next request uses its new profile without a restart.
An invalid profile returns `service_unavailable`; after correction, a later request
can start. Photo moderation remains on its separate env-only client.
