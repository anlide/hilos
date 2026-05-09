import { DomainObject } from '@hilos/sdk/types'

export type EventAttachment = {
  id: number
  eventId: number
  filename: string
  mimeType: string
}

/**
 * Event - matches Event structure from database
 *
 * Fields are projected from backend/Database/View/Item/Event.php:
 * - id: ?int (in TypeScript: number | null)
 * - type: string
 * - timestamp: string
 * - message: ?string - message text for message events
 * - authorUserId: ?int - authoring user for message events
 * - authorBotId: ?int - authoring bot for message events
 * - targetUserId: ?int - subject user for registration/rename events
 * - actorUserId: ?int - initiating user for rename events
 * - oldName: ?string - previous display name for rename events
 * - newName: ?string - new display name for rename events
 * - attachments: EventAttachment[] - published files linked to this event
 */
export class Event extends DomainObject {
  id: number | null
  type: string
  timestamp: string
  message: string | null
  authorUserId: number | null
  authorBotId: number | null
  targetUserId: number | null
  actorUserId: number | null
  oldName: string | null
  newName: string | null
  attachments: EventAttachment[]

  constructor(
    id: number | null,
    type: string,
    timestamp: string,
    message: string | null = null,
    authorUserId: number | null = null,
    authorBotId: number | null = null,
    targetUserId: number | null = null,
    actorUserId: number | null = null,
    oldName: string | null = null,
    newName: string | null = null,
    attachments: EventAttachment[] = []
  ) {
    super()
    this.id = id
    this.type = type
    this.timestamp = timestamp
    this.message = message
    this.authorUserId = authorUserId
    this.authorBotId = authorBotId
    this.targetUserId = targetUserId
    this.actorUserId = actorUserId
    this.oldName = oldName
    this.newName = newName
    this.attachments = attachments
  }

  /**
   * Create Event from a transport payload object.
   */
  static fromObject(data: {
    id?: number | null
    type: string
    timestamp: string
    message?: string | null
    authorUserId?: number | null
    authorBotId?: number | null
    targetUserId?: number | null
    actorUserId?: number | null
    oldName?: string | null
    newName?: string | null
    attachments?: EventAttachment[]
  }): Event {
    return new Event(
      data.id ?? null,
      data.type,
      data.timestamp,
      data.message ?? null,
      data.authorUserId ?? null,
      data.authorBotId ?? null,
      data.targetUserId ?? null,
      data.actorUserId ?? null,
      data.oldName ?? null,
      data.newName ?? null,
      data.attachments ?? []
    )
  }
}
