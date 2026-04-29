# ChatUserState

**Collection:** `RtChatContext::userStates` | **Key:** `(string) userId`

Per-user runtime state. Tracks text message moderation state and send rate limits for each registered user.

## Fields

| Field | Type | Meaning |
|---|---|---|
| `userId` | `int` | DB user ID (also numeric value of collection key) |
| `moderationMessage` | `string` | Message text currently under LLM moderation (empty = none pending) |
| `moderationUpdatedAt` | `int` | Unix time of last moderation field change |
| `lastMessageSentAt` | `float` | Microtime of the last approved published text message |

## Lifecycle

- **Created**: `UserStatesActions::ensure(userId)` on WS handshake
- **Updated**: when message submitted for moderation (`moderationMessage` set), cleared on result, or approved message is recorded for rate limiting
- **Never deleted** during a session — persists as long as user exists in DB

## Truth source

`ChatAgent` owns this collection (`RtTruthSourceRegistry::register(RtChatContext::userStates, ...)`).
Only `ChatAgent` should write to it.

## Initialization

`ChatUserState` rows are created lazily during `ChatAgent::onSignalHandshake()`.

## Note

File upload state is **not** here — it lives on `Connection` (per-connection, not per-user).
This was intentional: one user can have multiple connections, each with its own upload session.
