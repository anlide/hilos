# ChatAgent

**Type:** `AgentType::CHAT` (`'chat'`) | **Worker:** Monopolistic

The central agent owns chat DB/RT state and handles chat-wide lifecycle signals.
Main-page message workflows are routed to `MainPage` through `PageSignalRouter`; uploads are the framework uploads agent's.

## Responsibilities

- **Handshake**: authenticate session token, create `Connection` RT state, send `HANDSHAKE_RESPONSE`, broadcast runtime presence changes.
- **Message routing**: main-page message actions and outbound moderation results are routed to `MainPage`.
- **Attachment publication**: the registry's answer `chat_attachments_published` is page-routed to `MainPage`. Binary WS frames are not the chat's: they go to the framework uploads agent.
- **Moderation results**: user outbound results are page-routed.
- **Bot lifecycle**: handles generated bot messages and chat-visible bot events.
- **Truth source**: owns `ChatDbContext::events`, `eventAttachments`, `users`, and `ChatRtContext::connections`, `userStates`.

## Key Signal Handlers

| Signal | Handler |
|---|---|
| `WS_HANDSHAKE` | `onSignalHandshake()` - auth, register connection |
| `WS_CLOSE` | `onSignalConnectionClose()` - unregister connection (its uploads are the uploads agent's to remove) |
| `PAGE_SUBSCRIBE(main)` | `MainPage::onSubscribe()` - send initial state |
| `WS_ACTION(message)` | `MainPage::onAction()` - text and/or complete uploads named by client id |
| `AGENT_SIGNAL(moderation_result)` | `MainPage::onSignalAgent()` -> outbound moderation result |
| `AGENT_SIGNAL(chat_attachments_published)` | `MainPage::onSignalAgent()` -> write the approved message with its files |
| `AGENT_SIGNAL(bot_message)` | Publish generated bot message |
| `DB_SYNC_*` | `onSignalDbSync*()` - keep local cache in sync |

## Rate Limiting

Outbound submissions: 10 seconds per user.
Tracked in `ChatRtContext::userStates.lastOutboundSubmittedAt` through `UserStatesActions`.
The limit applies to text-only, attachment-only, and mixed messages.

## File Upload

Uploads, unfinished and complete, are rows of the framework uploads agent (`Hilos::$rt->hilosUploads`); the chat declares the one target `chat_attachment` and keeps no upload state of its own.
See `data-flow/file-upload-flow.md`.

## Cron

- `cleanup_history` (every 30 minutes): `ChatAgent::onSignalCron()` collects the registry file ids of every attachment, deletes the events, adds the `chat_cleared` event, then hands the ids to `Hilos::$files->remove()` — the files library removes the rows and their bytes at once.
