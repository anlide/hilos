# Attachment Moderation Flow

There is no separate file moderation signal path anymore. Uploaded files become per-connection attachment drafts, and moderation happens once for the outbound message that contains text and/or drafts.

## Flow

```
Upload completes
        |
UploadFileTrait creates AttachmentDraft in quarantine
        |
Frontend shows draft in composer
        |
User sends message with attachmentDraftIds
        |
MainPage validates draft ownership and starts outbound moderation
        |
ModeratorAgent receives MODERATE_REQUEST with contentForModeration
        |
MainPage receives MODERATION_RESULT
```

`contentForModeration` includes message text plus attachment metadata such as filename, MIME type, and size. The file bytes themselves are not inspected by this flow.

## Approved

1. Move each draft file from quarantine to published storage.
2. Remove draft rows from `RtChatContext::attachmentDrafts`.
3. Add one `message_sent` event with `data.message` and `data.attachments`.
4. Broadcast `new_event`.
5. Clear `outboundModerationState` for the originating connection.

## Rejected or Unavailable

1. Keep draft rows in quarantine so the user can retry.
2. Set `outboundModerationState.phase` to `rejected` or `unavailable`.
3. Send `outbound_moderation_state_update` to the originating connection.

Drafts are still subject to the one-hour TTL and disconnect cleanup.
