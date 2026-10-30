# Attachment Moderation Flow

There is no separate upload-specific action. Uploaded files are complete rows of
the framework uploads agent ([file-upload-flow.md](file-upload-flow.md)); a
message keeps its files in the composer while ChatAgent coordinates the shared
checks and publishes them only after every required check approves.

## Flow

```
Upload completes (framework uploads agent)
        |
Frontend shows a chip for the complete upload
        |
User sends message {content, attachments: list<clientUploadId>}
        |
MainPage validates each id and stores the composer on Connection
        |
ChatAgent captures all original upload identities and opens `chat_message` as `TEXT_WITH_FILES`
with text, ordered upload ids and the immutable snapshot
        |
Uploads owner confirms the full snapshot with a hold under the first check's request id
        |
Framework text moderation reads upload metadata only, never non-image bytes
        |
After text approval, ChatAgent checks each image separately in attachment order
with `chat_message_picture` / `IMAGE` / `imageCopy: true`, keeping the full snapshot,
hold token and first deadline
        |
After all checks approve: forget the last check, queue hold approval, publish uploads
        |
Uploads owner requires live, finally approved holds before handing the original files over
        |
ChatAgent receives `chat_attachments_published`
```

The text check includes one metadata line per ordered upload id:
`Attachment: name=…, mime=…, size=… bytes.` The name comes from the upload,
MIME uses the detected type when present and otherwise the declared type, and
size is the declared size. The model sees no attachment bytes at this stage;
names, types and sizes are not grounds for refusing the text. A missing,
incomplete or stale upload ends as `service_unavailable` instead of being left
out. After the text is allowed, each image is rendered as a 512-by-512 JPEG
with `CONTAIN` and sent to the image profile, one at a time in attach order. The
first refusal stops the chain and names the refused picture when it is a content
refusal or a local `unreadable` renderer failure. A non-image file is never
opened. The deadline stays fixed from the first check through every image.

The snapshot is an ordered `UploadSnapshotCollection` of
`(clientUploadId, tmpIndex, contentHash)`, captured once from complete, fresh
uploads belonging to the sender and the `chat_attachment` target. The first
check's generated `requestId` supplies its `uploadHoldId`; each later image
check gets a fresh request id but retains the whole original snapshot and token,
including earlier images. An image check keeps `clientUploadIds` empty and names
its image in `clientUploadId`. Equal hashes with different temporary names do
not describe the same upload. The moderator and Chat require matching owner
marks for every original attachment before allowing the next stage.

The uploads owner refuses browser mutation of a live hold, independently of the
Chat connection replica. The `chat_attachment` target additionally refuses browser cancel and reinitialization of
every upload named by a `checking` composer. That includes already approved
images while another image is pending and the wait before publication handover.
Replacement with a different target is refused before the original file is
removed. The policy reads the existing connection View, so a ChatAgent restart
does not release the held files; a stale connection source refuses mutation.
The upload owner's internal handover and cleanup remain available. A rejected
or unavailable attempt queues `HILOS_UPLOAD_HOLD_SETTLE` with `RELEASED` and its
token. Close and user switch capture that token before deleting the check or
changing the connection; after the last check it comes from surviving owner
marks on the current user's attachments. Release keeps the old token as a
tombstone, clearing its deadline and approval. A fresh attempt may hold the same
bytes with a new token; a late release cannot change the new hold.

An owner expiry fact ends the matching current composer as `unavailable`, even
while it is waiting for handover after the last check. The check's initial
deadline includes owner admission and is never recomputed from the composer or
a changed setting. Chat restart retains checks, stages and holds. Uploads owner
restart keeps its existing cleanup semantics: the files and rows go, so an
active chain loses its source and ends as `unavailable`.

## Approved

1. Without files: after every check approves, write the `message_sent` event and then clear the outbound composer.
2. With files: delete the final check, queue `HILOS_UPLOAD_HOLD_SETTLE` with `APPROVED`, then call `Hilos::$files->publishUploads($acceptKey, 'chat_attachment', $ids, 'chat_attachments_published')` on the same FIFO channel. The target requires live, finally approved owner holds and declares `FileVisibility::AUTHENTICATED`. The composer stays `checking` until the registry answers `ChatAgent` with `FilesPublishedSignalData`.
3. A late or orphan answer is removed through the files door; a current answer is checked against the connection, exact ordered ids, absence of active checks, fresh sources and file ownership. Upload rows normally disappear at handover; their deletion after the final check does not fail the composer or reject a legitimate registry reply.
4. The answer carries an error: keep the draft and set `unavailable` with the registry's safe sentence.
5. Published: write the event with one `event_attachment` link `(event_id, file_id)` per file in attach order and clear the composer in the same ORM transaction, then call `Hilos::$files->markBound($fileIds)` after commit. A write failure rolls back the whole message, retains the draft and chips as `unavailable` under the original submit lockout, and requests removal of known unlinked files through the files door. A stale publication source also returns a fresh current composer as `unavailable`. A failed commit announcement or bind request does not undo the saved message; duplicate answers never remove its linked files.
6. Browser source fan-out updates the main event rows; the uploaded files and chips are now linked to the message.

The files protocol is unchanged. A failed or reordered owner approval refuses
handover. Once the rows have been handed over, no hold remains and no new
deadline, crash recovery or exactly-once guarantee is introduced for the files
library's reply.

## Rejected or Unavailable

1. The uploads stay complete with the uploads agent, so the chips stay and the user can retry.
2. Set `outboundModerationPhase` to `rejected` or `unavailable` on the originating `Connection`. An image content refusal or `unreadable` renderer failure sets `outboundModerationPicture` to that image id; text refusals and `service_unavailable` do not name one.
3. Only a content refusal may send the existing best-effort rejection notification. `unreadable` and `service_unavailable` are not content refusals and send no notification.
4. Browser source fan-out updates the originating connection's `selfConnection` row.

The uploads stay subject to the uploads agent's hour without change and to the life of their connection.
