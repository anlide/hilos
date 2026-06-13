// An event line of the main page stream and its attachment lines — the
// view-model of one `mainEvents` list item: a message or a service notice, with
// the acting party resolved and any published attachments. A view-model, not an
// entity; the page event-stream selector builds it from the list item's slots.

/** One published attachment shown under a message event line. */
export interface EventAttachmentItem {
  /** The attachment id, stable for keyed rendering. */
  readonly key: string
  /** The original file name, resolved from the referenced entity. */
  readonly filename: string
  /** The declared MIME type — image/* renders inline, anything else as a download. */
  readonly mimeType: string
  /** Same-origin download URL (/chat/attachment?id=…); the session cookie authorizes it. */
  readonly url: string
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
  /** True when the author is a bot, so the view can mark it. */
  readonly authorIsBot: boolean
  /** The message body for a `message_sent` event, otherwise empty. */
  readonly text: string
  /** A service notice line for a non-message event, otherwise empty. */
  readonly description: string
  /** The published attachments of a message event, resolved reactively. */
  readonly attachments: readonly EventAttachmentItem[]
}
