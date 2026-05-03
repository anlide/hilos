# Connection

**Collection:** `RtChatContext::connections` | **Key:** `acceptKey`

Runtime row for one active WebSocket connection. Holds transport metadata plus connection-local outbound moderation, active binary upload session, and progress UI for this socket.

## Fields

### Transport

| Field | Type | Meaning |
|---|---|---|
| `acceptKey` | `string` | WS accept key (immutable, collection ID) |
| `userId` | `int` | DB user ID |
| `connectedAt` | `int` | Unix timestamp of connection |

### Outbound Moderation

| Field | Meaning |
|---|---|
| `outboundModerationRequestId` | Current moderation request id, empty when no visible moderation state exists |
| `outboundModerationPhase` | `checking`, `rejected`, `unavailable`, or empty when clear |
| `outboundModerationMessage` | Submitted message text |
| `outboundModerationAttachmentDraftIds` | Submitted attachment draft ids |
| `outboundModerationReason` | Rejection/unavailable reason, empty when none |
| `outboundModerationUpdatedAt` | Unix time of last moderation field change |

### Upload Session

| Field | Meaning |
|---|---|
| `fileSessionUploadId` | Active upload UUID (null = no upload) |
| `fileSessionDeclaredSize` | Declared total bytes |
| `fileSessionReceivedBytes` | Bytes received so far |
| `fileSessionQuarantineBasename` | Tmp file basename while upload is active |
| `fileSessionOriginalFilename` | Client filename |
| `fileSessionMimeType` | MIME type |
| `fileSessionClientUploadId` | Client-side correlation ID |
| `fileSessionNormalizedFilename` | Normalized basename for duplicate checks |

### Upload Progress UI

| Field | Meaning |
|---|---|
| `fileProgressFilename` | Filename shown in progress bar (null = hidden) |
| `fileProgressUploadedBytes`, `fileProgressTotalBytes` | Progress values |
| `uploadProgressLastSentAt` | Microtime of last progress signal (for throttle) |

## Lifecycle

- **Created**: `ConnectionsActions::register(acceptKey, userId)` in `ChatAgent::onSignalHandshake()`.
- **Updated**: moderation fields set during message submit/result handling; upload fields set during binary upload init/frame processing.
- **Deleted**: `Hilos::$rt->connections[$acceptKey]->actions->unregister()` in `ChatAgent::onSignalConnectionClose()`.

Completed uploads no longer live on `Connection`; they become `AttachmentDraft` rows keyed by draft id and owned by the same `acceptKey`.

## Truth Source

`ChatAgent` owns `RtChatContext::connections`.

## Note on Immutability

`acceptKey` is immutable (`__set` throws `RtStateReadOnlyException` for it).
