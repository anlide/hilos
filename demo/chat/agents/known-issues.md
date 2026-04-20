# Known Issues and Technical Debt

## Pending renames (TODO from code)

- `TODO(hilos-refactor)` in `Bootstrap/cli.php`: Legacy CLI commands `db:idea:*` are aliases for `db:hilos:*` / `db:entity:*`. Need to remove the compatibility branch after renaming.
- `TODO(hilos-refactor)` in `README.md`: Bootstrap/CLI parameter naming uses `initIdea` — should be renamed to `initHilos`.

## Framework-level TODO

- `TODO` in `DaemonManager::sendToGroup()`: Group subscription tracking not properly implemented. Currently sends to all clients instead of group members only.

## Upload session orphan on disconnect

If a user disconnects mid-upload, the quarantine file is left on disk.
No cleanup mechanism exists for orphaned quarantine files.
Need: periodic cleanup job (cron) to delete old quarantine files.

## Rate limiting UX

Silent rate limit (10s between messages) — user receives no feedback.
Consider sending `moderation_state_update` with "rate limited" reason to show in UI.

## File moderation on reconnect

If connection drops during file moderation (between `FILE_UPLOAD_COMPLETE` and `MODERATION_FILE_RESULT`):
- `Connection` RT state is deleted on close
- Moderation result arrives with an `acceptKey` that no longer exists
- File stays in quarantine indefinitely
Need: handle missing connection gracefully in `ChatAgent` moderation result handler.

## Group subscription routing

`sendToGroup()` in daemon sends to ALL clients (basic implementation).
Not a correctness bug for current chat (all users are in one implicit group) but will be wrong for multi-room scenarios.

## Context analyzer and bot coordination

`ChatContextAnalyzerAgent` writes to RT, but `BotAgent` reads RT state from its own worker copy.
If bot worker hasn't received RT sync yet, it may use stale context.
No explicit consistency guarantee between context update and next bot message.
