# Connection

**Collection:** `RtChatContext::connections` | **Key:** `acceptKey`

Runtime row for one active WebSocket connection. Holds transport metadata + all file upload/moderation session state for this socket.

## Fields

### Transport
| Field | Type | Meaning |
|---|---|---|
| `acceptKey` | `string` | WS accept key (immutable, collection ID) |
| `userId` | `int` | DB user ID |
| `connectedAt` | `int` | Unix timestamp of connection |

### Upload session (active binary upload)
| Field | Meaning |
|---|---|
| `fileSessionUploadId` | Active upload UUID (null = no upload) |
| `fileSessionDeclaredSize` | Declared total bytes |
| `fileSessionReceivedBytes` | Bytes received so far |
| `fileSessionQuarantineBasename` | Quarantine file path |
| `fileSessionOriginalFilename` | Client filename |
| `fileSessionMimeType` | MIME type |
| `fileSessionClientUploadId` | Client-side correlation ID |
| `fileSessionNormalizedFilename` | Lowercase for dedup checks |

### File moderation UI
| Field | Meaning |
|---|---|
| `fileModPhase` | `'moderating'`, `'rejected'`, or null |
| `fileModFilename`, `fileModUploadedBytes`, `fileModTotalBytes` | Display values |
| `fileModReason` | Rejection reason text |
| `fileModUpdatedAt` | Last update unix time |

### Upload progress UI
| Field | Meaning |
|---|---|
| `fileProgressFilename` | Filename shown in progress bar (null = hidden) |
| `fileProgressUploadedBytes`, `fileProgressTotalBytes` | Progress values |
| `uploadProgressLastSentAt` | Microtime of last progress signal (for throttle) |

## Lifecycle

- **Created**: `ConnectionActions::create(acceptKey, userId)` in `ChatAgent::onSignalHandshake()`
- **Updated**: upload fields set during binary frame processing
- **Deleted**: `ConnectionActions::delete(acceptKey)` in `ChatAgent::onSignalConnectionClose()`

## Truth source

`ChatAgent` owns `RtChatContext::connections`.

## Note on immutability

`acceptKey` is immutable (`__set` throws `RtStateReadOnlyException` for it).
