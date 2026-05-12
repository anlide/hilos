# Attachment Moderation Flow

There is no separate file moderation signal path anymore. Uploaded files become per-connection attachment drafts, and moderation happens once for the outbound message that contains text and/or drafts.

## Flow

```
Upload completes
        |
MainPage creates AttachmentDraft in quarantine
        |
Frontend shows draft in composer
        |
User sends message; backend reads current drafts from `Hilos::$rt->connections[$acceptKey]->attachmentDrafts`
        |
MainPage starts outbound moderation for the current connection
        |
ModeratorAgent receives MODERATE_REQUEST and builds moderation content from runtime drafts
        |
MainPage receives MODERATION_RESULT
```

Moderation content includes message text plus attachment metadata such as filename, MIME type, and size. The file bytes themselves are not inspected by this flow.

## Approved

1. Move each draft file from quarantine to published storage.
2. Clear outbound moderation fields on the originating `Connection`.
3. Remove draft rows from `ChatRtContext::attachmentDrafts`.
4. Add one `message_sent` event with `data.message` and `data.attachments`.
5. Broadcast `new_event`.

## Rejected or Unavailable

1. Keep draft rows in quarantine so the user can retry.
2. Set `outboundModerationPhase` to `rejected` or `unavailable` on the originating `Connection`.
3. Runtime projection sends `self_connection_update` to the originating connection.

Drafts are still subject to the one-hour TTL and disconnect cleanup.
