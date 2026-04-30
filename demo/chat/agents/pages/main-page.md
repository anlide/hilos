# MainPage

**Page constant:** `PageConstants::MAIN` | **Agent:** `ChatAgent`

The primary chat page. Handles subscription, message submit, binary upload init, binary upload frames, and outbound moderation results.

## onSubscribe

1. Invariant: `acceptKey` must exist in `Hilos::$rt->connections`, otherwise `PageInternalErrorException`.
2. Reads current outbound moderation state from `userStates`.
3. Reads completed attachment drafts and binary upload progress for this connection.
4. Sends `SUBSCRIPTION_PAGE_MAIN` with:
   - Full entities snapshot: active `bots`, `events` history
   - Frontend state snapshot for visible users and bots
   - `outboundModerationState`
   - `attachmentDrafts`
   - `fileUploadProgress`

## Actions Handled

| Action | DTO | Handler |
|---|---|---|
| `message` | `MessageActionDTO` | Validate text/drafts -> common rate limit -> outbound moderation |
| `file_upload_init` | `FileUploadInitActionDTO` | Initialize per-connection binary upload session |
| `attachment_draft_delete` | `AttachmentDraftDeleteActionDTO` | Delete one completed draft for this connection |

## UploadFileTrait

File upload init and binary frame logic lives in `Pages/Main/UploadFileTrait`:

- Validates file size, MIME type, total storage, and filename uniqueness.
- Keeps in-flight upload state on `Connection`.
- Converts completed uploads into `RtChatContext::attachmentDrafts`.
- Sends `FILE_UPLOAD_READY`, `FILE_UPLOAD_REJECTED`, `FILE_UPLOAD_PROGRESS_UPDATE`, `FILE_UPLOAD_COMPLETE`, and `ATTACHMENT_DRAFTS_UPDATE`.

## Incremental Signals

Frontend receives initial state through `subscription_page_main`.
Subsequent updates arrive through:

- `new_event`
- `outbound_moderation_state_update`
- `attachment_drafts_update`
- `file_upload_progress_update`
