// One completed attachment draft of the composer — the view-model of an
// `attachmentDrafts` list item. A draft is an upload the backend has accepted
// into quarantine; it stays pending until the next message submit, when it binds
// to that message and passes moderation with it. A view-model, not an entity;
// the page draft selector builds it from the list item's plain slots. Wire keys
// mirror the backend AttachmentDraftSignalData.

/** One pending attachment draft, rendered as a removable composer chip. */
export interface AttachmentDraftItem {
  /** The draft id — the keyed-render identity and the value the delete action carries. */
  readonly draftId: string
  /** The original file name shown on the chip. */
  readonly filename: string
  /** The declared MIME type, for a per-type chip icon. */
  readonly mimeType: string
  /** The uploaded file's byte size. */
  readonly size: number
}
