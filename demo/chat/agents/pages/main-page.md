# MainPage

**Page constant:** `PageConstants::MAIN` | **Agent:** `ChatAgent`

The primary chat page. Handles subscription, message submit, binary upload init, binary upload frames, and outbound moderation results.

## onSubscribe

1. Invariant: `acceptKey` must exist in `Hilos::$rt->connections`, otherwise `PageInternalErrorException`.
2. Delegates payload assembly to `Frontend\MainPageSubscriptionProjector`.
3. Sends `SUBSCRIPTION_PAGE_MAIN` with:
   - Full entities snapshot: active `bots`, `events` history, compact relevant `users`
   - Frontend state snapshot for visible users and bots
   - `selfConnection` with current connection-local moderation, drafts, upload state/progress, and rate-limit summary

## Actions Handled

| Action | DTO | Handler |
|---|---|---|
| `message` | `MessageActionDTO` | Validate text/drafts -> common rate limit -> outbound moderation |
| `file_upload_init` | `FileUploadInitActionDTO` | Initialize per-connection binary upload session |
| `attachment_draft_delete` | `AttachmentDraftDeleteActionDTO` | Delete one completed draft for this connection |

## File Upload

File upload init and binary frame logic lives in `Pages/MainPage`:

- Validates file size, MIME type, total storage, and filename uniqueness.
- Keeps in-flight upload state on `Connection`.
- Converts completed uploads into `RtChatContext::attachmentDrafts`.
- Publishes ready/failed upload state, progress, and attachment drafts through runtime-backed frontend projection.

## Incremental Signals

Frontend receives initial state through `subscription_page_main`.
Subsequent updates arrive through:

- `new_event`
- `self_connection_update`
