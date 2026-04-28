# File Upload Flow

Binary file upload via WebSocket, split into init + binary stream phases.

## Phase 1: Init (JSON)

```
Client: ws.send('file_upload_init', {
    filename: 'photo.jpg',
    mimeType: 'image/jpeg',
    size: 204800,
    clientUploadId: 'uuid-...'
})
```

Handled by `MainPage::onAction()` → `UploadFileTrait::handleFileUploadInit()`:

1. Validate: size ≤ `ChatAttachmentDefaults::MAX_SIZE`, MIME type in allowed list
2. Check: no duplicate normalized filename in existing attachments
3. If previous upload session active → send `FILE_UPLOAD_ABORTED` to client
4. Create upload session on `Connection` RT state:
   - `fileSessionUploadId` = new UUID
   - `fileSessionDeclaredSize`, `fileSessionOriginalFilename`, `fileSessionMimeType`
   - `fileSessionQuarantineBasename` = quarantine path
5. Send `FILE_UPLOAD_READY { uploadId }` to client

## Phase 2: Binary stream

Client sends raw binary WS frames (no JSON wrapper).

`PageSignalRouter` routes `frame_binary` to `MainPage::onSignalFrameBinary()` → `UploadFileTrait`:
1. Validate `fileSessionUploadId` matches frame header (first 36 bytes = uploadId)
2. Append bytes to quarantine file
3. Update `fileSessionReceivedBytes` on Connection
4. Send throttled `FILE_UPLOAD_PROGRESS_UPDATE` (min interval: `0.3s`)
5. When `receivedBytes == declaredSize` → send `FILE_UPLOAD_COMPLETE`
6. Queue `MODERATE_FILE_REQUEST` → `ModeratorAgent`

## Error cases

- Frame with wrong/missing uploadId → `FILE_UPLOAD_INVALID`, clear session
- Bytes exceed declared size → `FILE_UPLOAD_INVALID`
- New `file_upload_init` during active session → abort old session, start new

## Quarantine

Files land in quarantine directory before moderation.
Path: configured via env/settings (see `FsContext`).
After moderation: if approved → moved to final location; if rejected → deleted.

## Connection state for uploads

All upload session data lives on `Connection` RT item (keyed by `acceptKey`).
If connection closes mid-upload → session abandoned, quarantine file left (cleanup needed).
