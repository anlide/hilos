# MainPage

**Page constant:** `PageConstants::MAIN` | **Agent:** `ChatAgent`

The primary chat page. Handles subscription and message admission; `ChatAgent`
owns moderation results and file publication. Files are sent to the framework
uploads agent, not to this page ([file-upload-flow.md](../data-flow/file-upload-flow.md)).

## onSubscribe

1. Invariant: `acceptKey` must exist in `Hilos::$rt->connections`, otherwise `PageInternalErrorException`.
2. Delegates payload assembly to the page `BROWSER` config.
3. Sends `SUBSCRIPTION_PAGE_MAIN` with:
   - Main event rows for chat history
   - Main user and bot rows with their runtime status overlays
   - `selfConnection` with current connection-local user, moderation, and rate-limit summary

## Actions Handled

| Action | DTO | Handler |
|---|---|---|
| `message` | `MessageActionDTO` (`content`, `attachments: list<clientUploadId>`) | Common rate limit -> named uploads -> non-empty -> record composer -> `ChatAgent::startMessageModeration()` |

## Attachments

The page validates the uploads a message names and admits the submission:

- Each id must be named once (`Attachment is listed twice`) and be a complete `chat_attachment` upload of this connection (`Attachment is not ready`); empty text with no file is `Message cannot be empty`.
- The text and ordered ids are kept in the six-field composer projection on `Connection`; `ChatAgent` opens the immutable check in the shared moderation collection.
- `ChatAgent` receives the framework verdict, checks each image separately, and only after the full chain approves calls `Hilos::$files->publishUploads()`. It receives `chat_attachments_published`, writes the event, then clears the composer and marks the files bound ([file-moderation-flow.md](../data-flow/file-moderation-flow.md)).

## Incremental Signals

Frontend receives initial state through `subscription_page_main`.
Subsequent DB/RT changes for the subscribed page arrive through the same browser
page signal with updated table rows or deletions.
