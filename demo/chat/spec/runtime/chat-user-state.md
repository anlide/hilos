# ChatUserState

**Collection:** `ChatRtContext::userStates` | **Key:** `(string) userId`

Per-user runtime state. Tracks only shared user-level chat state, currently the common outbound submit rate limit for text-only, attachment-only, and mixed messages.

## Fields

| Field | Type | Meaning |
|---|---|---|
| `userId` | `int` | DB user ID (also numeric value of collection key) |
| `lastOutboundSubmittedAt` | `float` | Microtime of the last accepted outbound submit |

## Lifecycle

- **Created**: `UserStatesActions::ensure(userId)` on WS handshake or first submit.
- **Updated**: `Hilos::$rt->userStates[$userId]->actions->recordOutboundSubmission()` when a submit is accepted for moderation.
- **Rate limited**: `lastOutboundSubmittedAt` is checked by `MainPage` before starting per-connection moderation.
- **Deleted**: cleared on chat agent stop.

## Truth source

`ChatAgent` owns this collection (`RtTruthSourceRegistry::register(ChatRtContext::userStates, ...)`).
Only `ChatAgent` should write to it.

## Related State

- Outbound moderation state lives on `Connection` (per connection).
- Active binary upload state lives on `Connection` (per connection).
- Completed uploaded files waiting to be sent live in `ChatRtContext::attachmentDrafts` (per connection).
