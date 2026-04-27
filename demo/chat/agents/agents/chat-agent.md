# ChatAgent

**Type:** `AgentType::CHAT` (`'chat'`) | **Worker:** Monopolistic

The central agent. Owns all shared DB and RT state. Handles WS connections, messages, file uploads, and coordinates moderation.

## Responsibilities

- **Handshake**: authenticate session token, create `Connection` RT state, send `HANDSHAKE_RESPONSE`, broadcast runtime presence changes
- **Message**: rate-limit check (10s), send to `ModeratorAgent` for moderation, on approval → save to `DbChatContext::events`, broadcast `NEW_EVENT`
- **File upload**: receive binary WS frames, write to quarantine, then send to `ModeratorAgent`
- **Moderation results**: receive approved/rejected from `ModeratorAgent`, update RT state, broadcast accordingly
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
| `WS_FRAME_BINARY` | `onSignalFrameBinary()` — accumulate file bytes |
| `AGENT_SIGNAL(moderation_result)` | `onSignalAgent()` — handle text mod result |
| `AGENT_SIGNAL(moderation_bot_result)` | Handle bot message mod result |
| `AGENT_SIGNAL(moderation_file_result)` | Handle file mod result |
| `DB_SYNC_*` | `onSignalDbSync*()` — keep local cache in sync |

## Rate limiting

Text messages: `MESSAGE_RATE_LIMIT_SECONDS = 10` per user.
Tracked in `$lastMessageTimestampByUser[userId]`.

## File upload

Binary upload state lives on `Connection` RT item (per-connection, not per-user).
See `data-flow/file-upload-flow.md`.

## Cron

Handles `ChatCronConstants` jobs: cleanup old connections, etc.
