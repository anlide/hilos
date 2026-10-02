# MainPage

**Page constant:** `PageConstants::MAIN` | **Agent:** `ChatAgent`

The primary chat page. Handles subscription, message submit, outbound moderation results, and the publication of an approved message's files. The files themselves are sent to the framework uploads agent, not to this page ([file-upload-flow.md](../data-flow/file-upload-flow.md)).

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
| `message` | `MessageActionDTO` (`content`, `attachments: list<clientUploadId>`) | Common rate limit -> named uploads -> non-empty -> outbound moderation |

## Attachments

The page reads the uploads a message names and publishes them once it is approved:

- Each id must be named once (`Attachment is listed twice`) and be a complete `chat_attachment` upload of this connection (`Attachment is not ready`); empty text with no file is `Message cannot be empty`.
- The text and the ids are kept on `Connection` (`outboundModerationAttachments`) while `ModeratorAgent` judges them.
- On approval with files it calls `Hilos::$files->publishUploads()` and handles the agent signal `chat_attachments_published` (`FilesPublishedSignalData`): writes the event with its `event_attachment` links and marks the files bound, turns the moderation `unavailable` on a refusal, or removes files nobody waits for any more ([file-moderation-flow.md](../data-flow/file-moderation-flow.md)).

## Incremental Signals

Frontend receives initial state through `subscription_page_main`.
Subsequent DB/RT changes for the subscribed page arrive through the same browser
page signal with updated table rows or deletions.
