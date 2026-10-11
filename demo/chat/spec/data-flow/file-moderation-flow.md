# Attachment Moderation Flow

There is no separate file moderation signal path. Uploaded files are complete uploads of the framework uploads agent ([file-upload-flow.md](file-upload-flow.md)), and moderation happens once for the outbound message that names them, with its text or without.

## Flow

```
Upload completes (framework uploads agent)
        |
Frontend shows a chip for the complete upload
        |
User sends message {content, attachments: list<clientUploadId>}
        |
MainPage checks each id and stores text + ids on the Connection row (`outboundModerationAttachments`)
        |
ModeratorAgent reads each named upload. The text model sees names, types and sizes; each picture is then checked from a 512-pixel JPEG copy of its bytes
        |
MainPage receives MODERATION_RESULT
        |
Approved with files: `Hilos::$files->publishUploads()` -> MainPage receives `chat_attachments_published`
```

Moderation content includes the message text plus one line per attachment: `Attachment: name=…, mime=…, size=… bytes.` — the upload's file name, the type read from its content (the declared one when none was read), and its declared size. An upload gone since the send is left out of the prompt; its publication refuses next. After the text is allowed, each picture (a type that starts with `image/`) is drawn as a 512-pixel JPEG and sent to the vision client, one at a time in attach order. The first refusal names that picture by its place among every attachment and by its file name. A picture the engine cannot read is rejected as unreadable. A file that is not a picture is still not opened.

## Approved

1. Without files: clear the outbound moderation and write the `message_sent` event.
2. With files: `Hilos::$files->publishUploads($acceptKey, 'chat_attachment', $ids, 'chat_attachments_published')`. The target declares `FileVisibility::AUTHENTICATED`. The moderation stays `checking` until the registry answers (agent frame `chat_attachments_published`, `FilesPublishedSignalData`), routed to `MainPage`.
3. Nobody waits for the answer — the connection is gone, its phase is no longer `checking`, or it waits for another id list: log it and `Hilos::$files->remove($fileIds)`.
4. The answer carries an error: the moderation turns `unavailable` with the registry's sentence as the reason.
5. Published: clear the outbound moderation, write the event with one `event_attachment` link `(event_id, file_id)` per file in attach order, then `Hilos::$files->markBound($fileIds)`. A message that fails to be written leaves its files unbound, for the registry's janitor.
6. Browser source fan-out updates the main event rows; the uploads went with the publication, so the chips go with them.

## Rejected or Unavailable

1. The uploads stay complete with the uploads agent, so the chips stay and the user can retry.
2. Set `outboundModerationPhase` to `rejected` or `unavailable` on the originating `Connection`. A refusal about one picture also sets `outboundModerationPicture` to that upload's client id; a text refusal leaves it null. `unreadable` is rejected: the picture could not be checked, and it does not notify the author. `service_unavailable` and `unknown` stay unavailable.
3. Browser source fan-out updates the originating connection's `selfConnection` row.

The uploads stay subject to the uploads agent's hour without change and to the life of their connection.
