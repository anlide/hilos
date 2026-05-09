# ChatAgent

**Type:** `AgentType::CHAT` (`'chat'`) | **Worker:** Monopolistic

The central agent owns chat DB/RT state and handles chat-wide lifecycle signals.
Main-page message and upload workflows are routed to `MainPage` through `PageSignalRouter`.

## Responsibilities

- **Handshake**: authenticate session token, create `Connection` RT state, send `HANDSHAKE_RESPONSE`, broadcast runtime presence changes.
- **Message routing**: main-page message actions and outbound moderation results are routed to `MainPage`.
- **File upload routing**: binary WS frames are routed to `MainPage`; completed uploads become attachment drafts.
- **Moderation results**: user outbound results are page-routed.
- **Bot lifecycle**: handles generated bot messages and chat-visible bot events.
- **Truth source**: owns `DbChatContext::events`, `eventAttachments`, `users`, and `RtChatContext::connections`, `userStates`, `attachmentDrafts`.

## Key Signal Handlers

| Signal | Handler |
|---|---|
| `WS_HANDSHAKE` | `onSignalHandshake()` - auth, register connection |
| `WS_CLOSE` | `onSignalConnectionClose()` - delete connection drafts and unregister connection |
| `PAGE_SUBSCRIBE(main)` | `MainPage::onSubscribe()` - send initial state |
| `WS_ACTION(message)` | `MainPage::onAction()` - text and/or attachment drafts |
| `WS_ACTION(file_upload_init)` | `MainPage::onAction()` - init binary upload session |
| `WS_FRAME_BINARY` | `MainPage::onSignalFrameBinary()` -> `MainPage::handleFileUploadBinaryFrame()` |
| `AGENT_SIGNAL(moderation_result)` | `MainPage::onSignalAgent()` -> outbound moderation result |
| `AGENT_SIGNAL(bot_message)` | Publish generated bot message |
| `DB_SYNC_*` | `onSignalDbSync*()` - keep local cache in sync |

## Rate Limiting

Outbound submissions: 10 seconds per user.
Tracked in `RtChatContext::userStates.lastOutboundSubmittedAt` through `UserStatesActions`.
The limit applies to text-only, attachment-only, and mixed messages.

## File Upload

Active binary upload state lives on `Connection` RT item.
Completed uploaded files waiting for submit live in `RtChatContext::attachmentDrafts`.
See `data-flow/file-upload-flow.md`.

## Cron

- `cleanup_history`: `ChatAgent::onSignalCron()` clears chat events and broadcasts `chat_cleared`; `MainPage::onSignalCron()` clears published/quarantine files and upload/draft UI state.
- `cleanup_attachment_drafts`: `MainPage::onSignalCron()` deletes drafts older than one hour from upload completion.
