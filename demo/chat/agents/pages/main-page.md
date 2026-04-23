# MainPage

**Page constant:** `PageConstants::MAIN` | **Agent:** `ChatAgent`

The primary chat page. Handles subscription (initial state delivery) and all user actions.

## onSubscribe

1. Invariant: `acceptKey` must exist in `Hilos::$rt->connections`, otherwise `PageInternalErrorException` (→ `subscription_page_error` with `internal_error`)
2. Reads text moderation, file-mod UI, and binary upload progress from the connection and `userStates`
3. Sends `SUBSCRIPTION_PAGE_MAIN` signal to user with:
   - Full entities snapshot: `users` (online), `bots` (active), `events` (history)
   - Current `moderationState`, `fileModerationState`, `fileUploadProgress`

## Actions handled

| Action | DTO | Handler |
|---|---|---|
| `message` | `MessageActionDTO` | Validate → rate limit → send to ModeratorAgent |
| `rename` | `RenameActionDTO` | Update display name in DB |
| `file_upload_init` | `FileUploadInitActionDTO` | Initialize upload session on Connection |
| `file_moderation_dismiss` | `FileModerationDismissActionDTO` | Clear file mod UI state |

## UploadFileTrait

File upload init logic extracted to `Pages/Main/UploadFileTrait`:
- Validates file size, MIME type, filename uniqueness
- Creates upload session on `Connection` RT state
- Sends `FILE_UPLOAD_READY` or `FILE_UPLOAD_REJECTED` to client

## Page sends on subscribe

Frontend receives one message of type `subscription_page_main` with all state needed to render the chat.
Subsequent updates arrive as incremental signals (`NEW_EVENT`, `moderation_state_update`, etc.).
