# ChatAgent

**Type:** `AgentType::CHAT` (`'chat'`) | **Worker:** Monopolistic

The central agent owns shared DB/RT state and handles chat-wide lifecycle signals.
Main-page message and file workflows are routed to `MainPage` / `UploadFileTrait` through `PageSignalRouter`.

## Responsibilities

- **Handshake**: authenticate session token, create `Connection` RT state, send `HANDSHAKE_RESPONSE`, broadcast runtime presence changes
- **Message routing**: main-page message actions/results are routed to `MainPage`
- **File upload routing**: binary WS frames and file moderation results are routed to `UploadFileTrait`
- **Moderation results**: bot results stay in `ChatAgent`; main-page text/file results are page-routed
- **Bot lifecycle**: start/stop `BotAgent` instances, relay bot messages to frontend
- **Admin actions**: ban/unban users, rename, CRUD for bots/settings/moderator pieces
- **Truth source**: owns `DbChatContext::events`, `users`, `bots`, `moderatorPromptPieces`, `settings`, and `RtChatContext::connections`, `userStates`

## Key signal handlers

| Signal | Handler |
|---|---|
| `WS_HANDSHAKE` | `onSignalHandshake()` — auth, register connection |
| `WS_CLOSE` | `onSignalConnectionClose()` — cleanup connection |
| `PAGE_SUBSCRIBE(main)` | `MainPage::onSubscribe()` — send initial state |
| `WS_ACTION(message)` | `MainPage::onAction()` — text message |
| `WS_ACTION(file_upload_init)` | Init binary upload session |
| `WS_FRAME_BINARY` | `MainPage::onSignalFrameBinary()` → `UploadFileTrait` |
| `AGENT_SIGNAL(moderation_result)` | `MainPage::onSignalAgent()` → text moderation result |
| `AGENT_SIGNAL(moderation_bot_result)` | Handle bot message mod result |
| `AGENT_SIGNAL(moderation_file_result)` | `MainPage::onSignalAgent()` → `UploadFileTrait` |
| `DB_SYNC_*` | `onSignalDbSync*()` — keep local cache in sync |

## Rate limiting

Text messages: 10 seconds per user.
Tracked in `RtChatContext::userStates.lastMessageSentAt` through `UserStatesActions`.

## File upload

Binary upload state lives on `Connection` RT item (per-connection, not per-user).
See `data-flow/file-upload-flow.md`.

## Cron

`cleanup_history` runs in order: `ChatAgent::onSignalCron()` clears chat events and broadcasts `chat_cleared`,
then `MainPage::onSignalCron()` clears attachment files and file-upload UI state.
