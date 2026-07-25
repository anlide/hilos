# Known Issues and Technical Debt

## Framework-Level TODO

- `TODO` in `DaemonManager::sendToGroup()`: Group subscription tracking is not properly implemented. Currently sends to all clients instead of group members only.

## Draft Cleanup Cadence

Completed attachment drafts expire one hour after upload completion, but cleanup runs on cron plus upload/message touch points.
An expired draft can remain visible until the next cleanup trigger.

## Rate Limiting UX

The backend rejects submits inside the 10-second common outbound limit with an action error.
The composer has its own countdown, but cross-tab submits can still produce the backend error.

## Group Subscription Routing

`sendToGroup()` in daemon sends to ALL clients (basic implementation).
Not a correctness bug for current chat (all users are in one implicit group) but will be wrong for multi-room scenarios.

## Context Analyzer and Bot Coordination

`ChatContextAnalyzerAgent` writes to RT, but `BotAgent` reads RT state from its own worker copy.
If bot worker has not received RT sync yet, it may use stale context.
No explicit consistency guarantee between context update and next bot message.
