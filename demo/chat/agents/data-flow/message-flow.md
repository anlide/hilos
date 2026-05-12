# Message Flow

Path of an outbound chat message from user input to all connected clients. A message may contain text, attachment drafts, or both.

## Happy Path

```
User sends text and/or uploaded attachment drafts
        |
Frontend: ws.send('message', { content })
        |
WS Server -> WS_ACTION signal -> PageSignalRouter
        |
MainPage::onAction('message', MessageActionDTO)
        |
Validate connection, active moderation, 10s common submit limit, and runtime draft state
        |
ConnectionActions::startOutboundModeration(content)
        |
ChatAgent::sendToAgent(MODERATE_REQUEST, ModerationRequestSignalData)
        |
        v
ModeratorAgent::onSignalAgent() -> queues request
        |
LLM call (async, non-blocking)
        |
ModeratorAgent::sendToAgent(MODERATION_RESULT, ModerationResultSignalData)
        |
        v
PageSignalRouter routes MODERATION_RESULT to MainPage::onSignalAgent()
        |
Move approved draft files from quarantine to published
        |
Hilos::$db->events->actions->addMessage(content, userId, attachments)
        |
ChatSignalMapper broadcasts NEW_EVENT
        |
All connected clients receive message_sent with data.attachments
```

## Attachment-Only Messages

The same `message_sent` event is used even when `content === ''`.
Attachments are stored in `data.attachments`; standalone `file_shared` is legacy-only.

## Rejected or Unavailable

`MainPage` keeps the outbound state instead of publishing an event:

- `phase = rejected` for normal moderation denial.
- `phase = unavailable` for service errors or missing draft files.
- The frontend keeps the composer content and draft list so the user can retry after the common submit cooldown.

## Rate Limiting

`ChatRtContext::userStates.lastOutboundSubmittedAt` tracks the last accepted outbound submit.
If `microtime(true) - last < 9s`, `MainPage` rejects the submit before moderation.
The limit applies equally to text-only, attachment-only, and mixed messages.
