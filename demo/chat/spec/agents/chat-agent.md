# ChatAgent

**Type:** `AgentType::CHAT` (`'chat'`) | **Worker:** Monopolistic

The central agent owns chat DB/RT state and handles chat-wide lifecycle signals.
`MainPage` validates and admits a message submission, then starts its moderation
through this agent. Uploads belong to the framework uploads agent.

## Responsibilities

- **Handshake**: authenticate session token, create `Connection` RT state, send `HANDSHAKE_RESPONSE`, broadcast runtime presence changes.
- **Message moderation**: opens the first shared check, applies fresh framework verdicts, and checks attached images one at a time under the first check's deadline.
- **Attachment publication**: receives the registry's `chat_attachments_published` answer, writes the approved event and binds its files. Binary WS frames go to the framework uploads agent.
- **Bot lifecycle**: handles generated bot messages and chat-visible bot events.
- **Truth source**: owns `ChatDbContext::events`, `eventAttachments`, `users`, and `ChatRtContext::connections`, `userStates`.

## Key Signal Handlers

| Signal | Handler |
|---|---|
| `WS_HANDSHAKE` | `onSignalHandshake()` - auth, register connection |
| `WS_CLOSE` | `onSignalConnectionClose()` - unregister connection (its uploads are the uploads agent's to remove) |
| `PAGE_SUBSCRIBE(main)` | `MainPage::onSubscribe()` - send initial state |
| `WS_ACTION(message)` | `MainPage::onAction()` - text and/or complete uploads named by client id |
| `HILOS_MODERATION_VERDICT` | `onSignalAgent()` -> apply the current shared check and continue or finish the composer |
| `AGENT_SIGNAL(chat_attachments_published)` | `onSignalAgent()` -> write the approved message with its files |
| `AGENT_SIGNAL(bot_message)` | Publish generated bot message |
| `DB_SYNC_*` | `onSignalDbSync*()` - keep local cache in sync |

## Rate Limiting

Outbound submissions: 10 seconds per user.
Tracked in `ChatRtContext::userStates.lastOutboundSubmittedAt` through `UserStatesActions`.
The limit applies to text-only, attachment-only, and mixed messages.

## File Upload

Uploads, unfinished and complete, are rows of the framework uploads agent (`Hilos::$rt->hilosUploads`); the chat declares the one target `chat_attachment` and keeps no upload state of its own.
See `data-flow/file-upload-flow.md`.

## Moderation State

The six outbound composer fields on `Connection` are the browser-facing draft
and status. The immutable attempt and its deadline live in the shared
`hilosModerationChecks` RT collection; the framework `ModerationAgent` reads
that row and returns the common verdict. Chat does not keep a second queue or
hold the message composer in the session subsystem.

## Cron

- `cleanup_history` (every 30 minutes): `ChatAgent::onSignalCron()` collects the registry file ids of every attachment, deletes the events, adds the `chat_cleared` event, then hands the ids to `Hilos::$files->remove()` — the files library removes the rows and their bytes at once.
