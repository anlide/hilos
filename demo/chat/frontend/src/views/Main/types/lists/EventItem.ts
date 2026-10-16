// An event line of the main page stream and its attachment lines — the
// view-model of one `mainEvents` list item: a message or a service notice, with
// the acting party resolved, its photo, whether the message opens a series of
// one author's messages (HIL-1300), and any published attachments. A view-model,
// not an entity; the page event-stream selector builds it from the list item's
// slots.

/** One published attachment shown under a message event line. */
export interface EventAttachmentItem {
  /** The attachment id, stable for keyed rendering. */
  readonly key: string
  /** The original file name, resolved from the referenced entity. */
  readonly filename: string
  /** The type the server read from the content — image/* renders as a thumbnail, anything else as a download. */
  readonly mimeType: string
  /** Same-origin address of the original (/_hilos/file?id=…); the session cookie authorizes it. */
  readonly url: string
  /** Same-origin address of the feed's thumbnail of an image. */
  readonly thumbUrl: string
}

/** One event line of the main page stream — a message or a service notice. */
export interface EventItem {
  /** The list item key (the event id), stable for keyed rendering. */
  readonly key: string
  /** The event type value (see ChatEventType). */
  readonly type: string
  /** The event timestamp string, as the backend stored it. */
  readonly timestamp: string
  /** The acting user or bot's name, or empty for a chat-lifecycle notice. */
  readonly authorName: string
  /** The address of the human author's public avatar photo; null for a bot, a notice or a person without one. */
  readonly authorPhoto: string | null
  /**
   * True for a message that opens a series — the first of consecutive messages
   * by one author (the same person or the same bot); false for a message that
   * continues one and for a service notice, which wears no circle.
   */
  readonly seriesHead: boolean
  /** The message body for a `message_sent` event, otherwise empty. */
  readonly text: string
  /** A service notice line for a non-message event, otherwise empty. */
  readonly description: string
  /** The published attachments of a message event, resolved reactively. */
  readonly attachments: readonly EventAttachmentItem[]
}
