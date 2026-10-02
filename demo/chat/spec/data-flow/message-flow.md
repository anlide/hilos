# Message Flow

Path of an outbound chat message from user input to all connected clients. A message may contain text, attached files, or both; the files are complete uploads of the framework uploads agent ([file-upload-flow.md](file-upload-flow.md)).

## Happy Path

```
User sends text and/or the files whose chips show
        |
Frontend: ws.send('message', { content, attachments: list<clientUploadId> })
        |
WS Server -> WS_ACTION signal -> PageSignalRouter
        |
MainPage::onAction('message', MessageActionDTO)
        |
Validate connection, active moderation, 10s common submit limit, and the named uploads
        |
ConnectionActions::startOutboundModeration(content, attachments)
        |
ModeratorAgent::onTick() picks up the connection whose phase is `checking`
        |
LLM call (async, non-blocking)
        |
ModeratorAgent::sendToAgent(MODERATION_RESULT, ModerationResultSignalData)
        |
        v
PageSignalRouter routes MODERATION_RESULT to MainPage::onSignalAgent()
        |
With files: Hilos::$files->publishUploads() -> chat_attachments_published -> MainPage
        |
Hilos::$db->events->actions->addMessage(content, userId, fileIds)
        |
Browser source fan-out updates subscribed main-page event rows
        |
Subscribed clients receive the new message_sent row with its attachments
```

`MainPage` refuses the submit before moderation when an id is not a complete `chat_attachment` upload of this connection (`Attachment is not ready`), when an id repeats (`Attachment is listed twice`), and when the text is empty and no file is named (`Message cannot be empty`). The approved message's files are published into the registry and linked in order; the full approval path is [file-moderation-flow.md](file-moderation-flow.md).

## Attachment-Only Messages

The same `message_sent` event is used even when `content === ''`.
Attachments are `event_attachment` links `(event_id, file_id)` to rows of the files registry (`hilos_file`), which keeps the name and the type; the feed's `mainEvents` row carries each one as `{id, eventId, fileId, filename, mimeType, url, thumbUrl}`. Standalone `file_shared` is legacy-only.

## Rejected or Unavailable

`MainPage` keeps the outbound state instead of publishing an event:

- `phase = rejected` for normal moderation denial.
- `phase = unavailable` for service errors, or when the registry refuses to publish the files (its sentence is the reason).
- The frontend keeps the composer content and the chips so the user can retry after the common submit cooldown.

## Rate Limiting

`ChatRtContext::userStates.lastOutboundSubmittedAt` tracks the last accepted outbound submit.
If `microtime(true) - last < 9s`, `MainPage` rejects the submit before moderation.
The limit applies equally to text-only, attachment-only, and mixed messages.
