# File Upload Flow

Binary file upload via WebSocket, split into init + binary stream phases. Upload completion creates an attachment draft; moderation happens later when the user sends a message containing that draft.

## Phase 1: Init (JSON)

```
Client: ws.send('file_upload_init', {
    filename: 'photo.jpg',
    mimeType: 'image/jpeg',
    size: 204800,
    clientUploadId: 'uuid-...'
})
```

Handled by `MainPage::onAction()` -> `MainPage::handleFileUploadInit()`:

1. Validate size, MIME type, and payload shape.
2. Delete expired completed drafts.
3. Check total storage limit across published attachments, active uploads, and attachment drafts.
4. Check duplicate normalized filename across active uploads, attachment drafts, and published attachments.
5. If previous upload session is active on this connection, abort it and start the new session.
6. Create upload session on `Connection` RT state with `fileUploadPhase=ready`.
7. Frontend receives `selfConnection.fileUploadState` and starts binary streaming when `clientUploadId` matches.

## Phase 2: Binary Stream

Client sends raw binary WS frames. The server associates frames with the active upload session for that `acceptKey`.

`PageSignalRouter` routes `frame_binary` to `MainPage::onSignalFrameBinary()` -> `MainPage::handleFileUploadBinaryFrame()`:

1. Validate that the connection has an active upload session.
2. Append bytes to tmp storage.
3. Update `fileSessionReceivedBytes` and upload progress on `Connection`.
4. Record throttled runtime projection markers for upload progress (min interval: `0.3s`).
5. When `receivedBytes == declaredSize`, move tmp to quarantine and create an attachment draft.
6. Clear upload state/progress runtime fields; draft and progress UI arrive through frontend projection.

## Draft Lifecycle

- Uploading files have no TTL.
- Completed drafts expire one hour after upload completion (`AttachmentDraftsActions::DRAFT_TTL_SECONDS`).
- Drafts are per connection, not synchronized across tabs.
- Users can delete a completed draft explicitly via `attachment_draft_delete`.
- On disconnect, that connection's drafts and active tmp upload are deleted.
- On approval, draft files move from quarantine to published and the draft rows are removed.

## Error Cases

- Invalid metadata -> `selfConnection.fileUploadState.phase=failed`.
- Bytes exceed declared size -> `selfConnection.fileUploadState.phase=failed`.
- New `file_upload_init` during active session -> old session is cleared and replaced by the new ready/failed state.
- Missing draft at send/approval time -> outbound moderation becomes `unavailable`.
