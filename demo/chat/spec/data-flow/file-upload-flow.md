# File Upload Flow

A chat attachment travels on the framework's upload machinery, not on the chat's own: the browser sends each file to the uploads agent under the chat's one upload target, and a message later names the complete ones by their client ids ([message-flow.md](message-flow.md)). The actions, the frame, the phases and the browser client are [uploads.md](../../../../docs/agents/architecture/uploads.md); this file is what the chat adds to them.

## The Target

`chat_attachment` (`backend/Files/ChatAttachmentUploadTarget.php`, registered in `Hilos::UPLOAD_TARGETS`):

- **Sign-in required.** A guest reads the chat and sends nothing into it; its declaration is refused with 401.
- **Types:** `image/*`, `application/pdf`, `text/plain` — the same list the composer's picker offers. The content is sniffed, so a file is judged by what it is: content of another type fails `content_mismatch`.
- **Per-file limit:** the chat setting `chat_attachment_max_file_bytes` (10 MiB by default), asked on every declaration, so an administrator's change applies at once.
- **Total limit:** the framework setting `files.max_total_bytes` over the registry and every upload holding a file; the chat's catalog defaults it to 100 MB. Migration 073 moved a stored value of the earlier `chat_attachment_max_total_bytes` onto it and dropped the old key.
- **Duplicates:** `DuplicateContentCheck` — the same person attaching the same content twice is refused with `This file is already uploaded`. A file name is not checked.

## The Composer

`frontend/src/views/Main/useComposerUpload.ts` hands each picked, dropped or pasted file to `uploadFile('chat_attachment', file)` from `@hilos/core` and reads the client's `hilosUploads` back, keeping only this target's records:

- **Queued, ready, uploading** — the first of them shows as the progress bar; Send is disabled while any exists.
- **Failed** — the latest one's sentence (the server's refusal) is the composer's error line. A new pick cancels this target's failed records, and the line clears with them.
- **Complete** — a chip, in attach order. Its X calls `cancelUpload()`; it is disabled while the message is moderated or the cancel is on its way.

An empty file is dropped silently, never declared. For a guest the paperclip is disabled and a drop or paste is ignored.

## Where A Draft Lives

A file waiting for its message lives in the browser tab — the client's list — and, on the server, as a complete `hilosUploads` row of the connection holding its temporary file. The chat keeps no draft row of its own, and `ChatAgent` does nothing with uploads on close or stop.

- **Dropped connection:** the client sends every unfinished and complete file again, from the start and under the same id, once the new connection's page answers; a chip comes back as soon as its file is whole again.
- **Page reload:** the list is lost; the old connection's uploads go with it on the uploads agent's sweep.
- **Sign-out, or another person signing in:** the client cancels every upload and clears the list.
- **Unchanged for an hour:** the uploads agent removes the upload; a message naming it later is refused (below).

## Error Cases

- A refused declaration (size, type, storage, sign-in) or a refused received file (content, duplicate) -> a failed record with the server's sentence -> the composer's error line.
- A message naming an upload that is not a complete `chat_attachment` upload of this connection -> `Attachment is not ready`; one named twice -> `Attachment is listed twice`.
- An upload gone between the send and the approval -> the registry refuses the publication, and the moderation turns `unavailable` with its sentence ([file-moderation-flow.md](file-moderation-flow.md)).
