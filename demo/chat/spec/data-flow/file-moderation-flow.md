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
After all checks approve, record the publication wait, forget the last check, queue hold approval and publish uploads
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
token. Close and a change of session or person take the token from the exact
check before handover or from the connection's accepted publication, which
records the hold, its deadline, and its author. Release keeps the old token as a
tombstone, clearing its deadline and approval. A fresh attempt may hold the same
bytes with a new token; a late release cannot change the new hold.

An owner expiry fact ends the matching current composer as `unavailable`, even
while it is waiting for handover after the last check. The check's initial
deadline includes owner admission and is never recomputed from the composer or
a changed setting. Chat restart retains checks, stages and holds. Uploads owner
restart keeps its existing cleanup semantics: the files and rows go, so an
active chain loses its source and ends as `unavailable`.

If a source disappears, is no longer `COMPLETE`, or no longer matches the
original snapshot's upload identity before the deadline, `ChatAgent` keeps that
snapshot unchanged, ends the attempt as `unavailable`, forgets its checks and
releases the hold. A replacement does not refresh the snapshot, and the model
need not be called.

The existing [regression scenario](../../tests/e2e/tests/message-picture-moderation.spec.ts)
uses a controlled accepted replacement for the submitted picture on the same
client upload id and live socket while upload admission is held. It uses no
timer override or new test-only command, and does not assert a live moderation
check after replacement B completes.

## Approved

1. Without files, write the `message_sent` event and clear the composer after every check approves.
2. With files, record the accepted hold, original deadline and author on the live `Connection` before removing the last check. Queue `HILOS_UPLOAD_HOLD_SETTLE` with `APPROVED` and call `Hilos::$files->publishUploads($acceptKey, 'chat_attachment', $ids, 'chat_attachments_published', $holdId)` on the same FIFO. The target requires a live, approved owner hold for every upload and declares `FileVisibility::AUTHENTICATED`.
3. Before the deadline, Chat accepts the registry's reply for the same hold, author and ordered client ids on a fresh, live composer. A leftover check for that hold is an unfinished handover. Chat removes the exact check and waits for the original deadline without sending publication again. An earlier check for a different hold leaves the current publication alone. Upload rows normally disappear during handover.
4. An exact refusal returns the registry's safe sentence in `unavailable`. A missing reply leaves the draft `checking` until its original deadline, when the owner sets `unavailable` and releases the exact hold. Recovery after the connections snapshot reads the same deadline. A late reply can remove the unbound files that remain unlinked. An answer without a hold or without a fresh basis for the connection leaves the files and composer alone.
5. On success, write the event and the ordered `event_attachment` links, and clear the composer in one ORM transaction. Call `Hilos::$files->markBound($fileIds)` after commit. A write failure rolls back the message, retains the draft as `unavailable`, and requests removal of known unbound, unlinked files. A failed commit announcement or bind request leaves the saved message in place.
6. Browser source fan-out updates the main event rows and their attachments.

The files library's reply still uses ordinary signal delivery.

## Rejected or Unavailable

1. The uploads stay complete with the uploads agent, so the chips stay and the user can retry.
2. Set `outboundModerationPhase` to `rejected` or `unavailable` on the originating `Connection`. An image content refusal or `unreadable` renderer failure sets `outboundModerationPicture` to that image id; text refusals and `service_unavailable` do not name one.
3. Only a content refusal may send the existing best-effort rejection notification. `unreadable` and `service_unavailable` are not content refusals and send no notification.
4. Browser source fan-out updates the originating connection's `selfConnection` row.

The uploads stay subject to the uploads agent's hour without change and to the life of their connection.
