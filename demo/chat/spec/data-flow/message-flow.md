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
Validate connection, active moderation, 10s common submit limit and named uploads
        |
ConnectionActions::startOutboundModeration(content, attachments)
        |
ChatAgent::startMessageModeration(acceptKey)
        |
Shared hilosModerationChecks: chat_message / TEXT_WITH_FILES
        |
Framework ModerationAgent -> HILOS_MODERATION_VERDICT -> ChatAgent
        |
For each image in attach order: chat_message_picture / IMAGE / imageCopy=true
        |
After all checks approve:
  no files -> add event
  with files -> publish uploads -> chat_attachments_published -> ChatAgent
        |
Write event, clear composer, bind files when present
        |
Browser source fan-out updates subscribed main-page event rows
```

`MainPage` refuses the submit before moderation when an id is not a complete `chat_attachment` upload of this connection (`Attachment is not ready`), when an id repeats (`Attachment is listed twice`), or when the text is empty and no file is named (`Message cannot be empty`). It records the draft on `Connection` and delegates the start to `ChatAgent`. That agent stores the immutable attempt and fixed deadline in the shared check collection. The text model receives each attachment's name, MIME type and size as metadata only; after text approval, images are checked separately in attach order. The approved message's files are published only after the entire chain approves ([file-moderation-flow.md](file-moderation-flow.md)).

## Attachment-Only Messages

The same `message_sent` event is used even when `content === ''`.
Attachments are `event_attachment` links `(event_id, file_id)` to rows of the files registry (`hilos_file`), which keeps the name and the type; the feed's `mainEvents` row carries each one as `{id, eventId, fileId, filename, mimeType, url, thumbUrl}`. Standalone `file_shared` is legacy-only.

## Rejected or Unavailable

`ChatAgent` keeps the outbound state instead of publishing an event:

- `phase = rejected` for a content refusal or a local `unreadable` image-render failure.
- `phase = unavailable` for service errors, expired checks, or when the registry refuses to publish the files.
- A content refusal may send the existing best-effort notification; `unreadable` and `service_unavailable` are not content refusals and send no notification.
- The frontend keeps the composer text and chips so the user can retry after the common submit cooldown. Unavailable results do not name an image.

## Rate Limiting

`ChatRtContext::userStates.lastOutboundSubmittedAt` tracks the last accepted outbound submit.
If `microtime(true) - last < 9s`, `MainPage` rejects the submit before moderation.
The limit applies equally to text-only, attachment-only, and mixed messages.
