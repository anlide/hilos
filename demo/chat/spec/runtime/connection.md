# Connection

**Collection:** `ChatRtContext::connections` | **Key:** `acceptKey`

Runtime row for one active WebSocket connection. Holds transport metadata plus connection-local outbound and rename moderation for this socket. The files a person attaches are not here: they are rows of the framework uploads agent (`Hilos::$rt->hilosUploads`), and the row names the ones a moderated message carries by their client ids.

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
| `outboundModerationPhase` | `checking`, `rejected`, `unavailable`, or empty when clear |
| `outboundModerationMessage` | Submitted message text |
| `outboundModerationAttachments` | Client ids of the complete uploads the submitted message carries, in attach order; empty when none |
| `outboundModerationReason` | Rejection/unavailable reason, empty when none |
| `outboundModerationUpdatedAt` | Unix time of last moderation field change |

### Rename Moderation

| Field | Meaning |
|---|---|
| `renameModerationPhase` | `checking`, `rejected`, `unavailable`, or empty when clear |
| `renameModerationName` | Requested display name |
| `renameModerationReason` | Rejection/unavailable reason, empty when none |
| `renameModerationUpdatedAt` | Unix time of last rename moderation field change |

## Lifecycle

- **Created**: `ConnectionsActions::register(acceptKey, userId)` in `ChatAgent::onSignalHandshake()`.
- **Updated**: moderation fields set during message submit/result handling; rename moderation fields set during profile rename submit/result handling.
- **Deleted**: `Hilos::$rt->connections[$acceptKey]->actions->unregister()` in `ChatAgent::onSignalConnectionClose()`.

Uploads have not lived on this row since HIL-144: the uploads agent keeps them per connection and removes them once the connection is gone.

## Truth Source

`ChatAgent` owns `ChatRtContext::connections`.

## Note on Immutability

`acceptKey` is immutable (`__set` throws `RtStateReadOnlyException` for it).
