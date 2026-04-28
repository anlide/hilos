# Message Flow

Path of a text chat message from user input to all connected clients.

## Happy path (approved)

```
User types message, hits send
        │
Frontend: ws.send('message', { text: '...' })
        │
WS Server → WS_ACTION signal → PageSignalRouter
        │
MainPage::onAction('message', MessageActionDTO)
        │
UserStatesActions::canSendMessage() check → rate limit (10s per user)
        │
Hilos::$rt->userStates->actions->setPendingModeration(userId, text)
        │
ChatAgent::sendToAgent(MODERATE_REQUEST, ModerationRequestSignalData)
        │
        ▼
ModeratorAgent::onSignalAgent() → queues request
        │
LLM call (async, non-blocking)
        │
ModeratorAgent::sendToAgent(MODERATION_RESULT, ModerationResultSignalData { allowed: true })
        │
        ▼
PageSignalRouter routes MODERATION_RESULT to MainPage::onSignalAgent()
        │
Hilos::$db->events->actions->add(MESSAGE, userId, text)  → DB_SYNC_CREATED broadcast
        │
ChatAgent::sendToAllUsers(NEW_EVENT, ChatEventSignalDTO)
        │
All connected clients receive new message
```

## Rejected path

LLM returns `allowed: false` → `MainPage` clears `userStates` moderation field, no event saved.
Optionally sends moderation state update to originating user.

## Rate limiting

`RtChatContext::userStates.lastMessageSentAt` — tracks last approved message time.
If `time - last < 10s` → message rejected immediately (no LLM call).
User sees no visual feedback (silent rate limit).

## DB_SYNC broadcast

When `events->actions->add()` writes to DB, it queues `DB_SYNC_CREATED` signal.
Daemon broadcasts to all workers. All agents receive `onSignalDbSyncCreated()`.
