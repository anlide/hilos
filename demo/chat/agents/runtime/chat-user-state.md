# ChatUserState

**Collection:** `RtChatContext::userStates` | **Key:** `(string) userId`

Per-user runtime state. Tracks the current outbound moderation request and the common send rate limit for text-only, attachment-only, and mixed messages.

## Fields

| Field | Type | Meaning |
|---|---|---|
| `userId` | `int` | DB user ID (also numeric value of collection key) |
| `outboundModerationAcceptKey` | `string` | Target connection accept key for current/last moderation UI delivery |
| `outboundModerationRequestId` | `string` | Current moderation request id, empty when idle |
| `outboundModerationPhase` | `string` | `checking`, `rejected`, `unavailable`, or empty when idle |
| `outboundModerationMessage` | `string` | Submitted message text |
| `outboundModerationAttachmentDraftIdsJson` | `string` | JSON list of attachment draft ids included in the submit |
| `outboundModerationReason` | `string` | Rejection/unavailable reason, empty when none |
| `outboundModerationUpdatedAt` | `int` | Unix time of last outbound moderation field change |
| `lastOutboundSubmittedAt` | `float` | Microtime of the last accepted outbound submit |

## Lifecycle

- **Created**: `UserStatesActions::ensure(userId)` on WS handshake or first submit.
- **Updated**: item actions on `Hilos::$rt->userStates[$userId]` start, fail, or clear outbound moderation.
- **Rate limited**: `startOutboundModeration()` records `lastOutboundSubmittedAt` when a submit is accepted for moderation, before the LLM result.
- **Deleted**: cleared on chat agent stop.

## Truth source

`ChatAgent` owns this collection (`RtTruthSourceRegistry::register(RtChatContext::userStates, ...)`).
Only `ChatAgent` should write to it.

## Related State

- Active binary upload state lives on `Connection` (per connection).
- Completed uploaded files waiting to be sent live in `RtChatContext::attachmentDrafts` (per connection).
