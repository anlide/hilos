# Connection

**Collection:** `ChatRtContext::connections` | **Key:** `acceptKey`

Runtime row for one active WebSocket connection. Holds transport metadata plus
the browser-facing composer draft and status for this socket. The files a
person attaches are not here: they are rows of the framework uploads agent
(`Hilos::$rt->hilosUploads`), and the row names the ones a moderated message
carries by their client ids.

## Fields

### Transport

| Field | Type | Meaning |
|---|---|---|
| `acceptKey` | `string` | WS accept key (immutable, collection ID) |
| `userId` | `int` | DB user ID |
| `connectedAt` | `int` | Unix timestamp of connection |

### Outbound Moderation

| Field | Type | Meaning |
|---|---|---|
| `outboundModerationPhase` | `string` | `checking`, `rejected`, `unavailable`, or empty when clear |
| `outboundModerationMessage` | `?string` | Submitted message text |
| `outboundModerationAttachments` | `list<string>` | Client ids of the complete uploads the submitted message carries, in attach order; empty when none |
| `outboundModerationPicture` | `?string` | Client id of a picture content refusal or `unreadable` renderer result; null otherwise |
| `outboundModerationReason` | `?string` | Rejection/unavailable reason, null when none |
| `outboundModerationUpdatedAt` | `int` | Unix time in seconds of the last moderation field change |

These six fields describe the composer to the browser. The shared
`Hilos::$rt->hilosModerationChecks` collection carries the immutable check
input, current stage and fixed deadline, keyed by connection and kind.

After the last attachment approval, Chat records three internal fields on this
replicated row before removing the final check. `outboundPublicationHoldId`
names the accepted upload hold, `outboundPublicationDeadlineAt` carries the
original deadline in epoch milliseconds, and `outboundPublicationUserId` names
the author. Chat clears the three fields when the composer leaves that wait.
A successor uses them to expire the same attempt after importing the snapshot.

## Lifecycle

- **Created**: `ConnectionsActions::register(acceptKey, userId)` in `ChatAgent::onSignalHandshake()`.
- **Updated**: `ChatAgent` sets composer fields during message admission and result handling.
- The socket close always calls `unregister()`, including when check cleanup is delayed. Startup reconciles the imported connections against the socket roster. A row awaiting mandatory removal cannot receive a publication or be rebound.

Uploads have not lived on this row since HIL-144: the uploads agent keeps them per connection and removes them once the connection is gone.

## Truth Source

`ChatAgent` owns `ChatRtContext::connections`.

## Note on Immutability

`acceptKey` is immutable (`__set` throws `RtStateReadOnlyException` for it).
