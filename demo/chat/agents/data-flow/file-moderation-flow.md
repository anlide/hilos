# File Moderation Flow

After all bytes received, the file goes through AI moderation before being published.

## Flow

```
ChatAgent receives FILE_UPLOAD_COMPLETE
        │
Generate synthetic description of file (filename, MIME, size)
        │
Update Connection: fileModPhase = 'moderating'
Send FILE_MODERATION_STATE_UPDATE to user (shows "moderating" UI)
        │
sendToAgent(MODERATE_FILE_REQUEST, ModerationFileRequestSignalData {
    filename, mimeType, size, uploadId, acceptKey, quarantinePath
})
        │
        ▼
ModeratorAgent::onSignalAgent()
LLM call: "Is this file safe to share? filename: photo.jpg, type: image/jpeg, size: 200KB"
        │
ModeratorAgent::sendToAgent(MODERATION_FILE_RESULT, ModerationFileResultSignalData {
    allowed: true/false,
    reason: '...',
    uploadId, acceptKey
})
        │
        ▼
ChatAgent::onSignalAgent() handles MODERATION_FILE_RESULT
```

## Approved

1. Move file from quarantine to final storage path
2. Add file event to `DbChatContext::events`
3. Broadcast `NEW_EVENT` to all users
4. Clear `fileModPhase` on Connection
5. Clear `fileModerationState` on `ChatUserState`

## Rejected

1. Delete quarantine file
2. Update Connection: `fileModPhase = 'rejected'`, `fileModReason`
3. Send `FILE_MODERATION_STATE_UPDATE` to user (shows rejection reason)
4. User can dismiss via `FILE_MODERATION_DISMISS` action

## Client signals

| Signal | Direction | Meaning |
|---|---|---|
| `file_moderation_state_update` | Server → user | Moderating / rejected (private) |
| `file_moderation_dismiss` | Client → server | User dismisses rejection UI |

## Notes

- File moderation state is **per-connection** (not per-user) — tracks the current upload only
- If user disconnects during moderation, result is lost (Connection RT state gone)
