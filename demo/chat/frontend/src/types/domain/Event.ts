import { DomainObject } from '@hilos/sdk/types'

export type EventAttachment = {
  id: number
  eventId: number
  filename: string
  mimeType: string
}

export type EventMessage = {
  eventId: number
  authorUserId: number | null
  authorBotId: number | null
  message: string
}

export type EventUserRegistration = {
  eventId: number
  targetUserId: number
}

export type EventUserRename = {
  eventId: number
  targetUserId: number
  actorUserId: number | null
  oldName: string
  newName: string
}

/**
 * Event - matches Event structure from database
 *
 * Fields are projected from backend/Database/View/Item/Event.php:
 * - id: ?int (in TypeScript: number | null)
 * - type: string
 * - timestamp: string
 * - eventMessage: message event detail bridge
 * - eventUserRegistration: registration event detail bridge
 * - eventUserRename: rename event detail bridge
 * - attachments: EventAttachment[] - published files linked to this event
 */
export class Event extends DomainObject {
  id: number | null
  type: string
  timestamp: string
  eventMessage: EventMessage | null
  eventUserRegistration: EventUserRegistration | null
  eventUserRename: EventUserRename | null
  attachments: EventAttachment[]

  constructor(
    id: number | null,
    type: string,
    timestamp: string,
    eventMessage: EventMessage | null = null,
    eventUserRegistration: EventUserRegistration | null = null,
    eventUserRename: EventUserRename | null = null,
    attachments: EventAttachment[] = []
  ) {
    super()
    this.id = id
    this.type = type
    this.timestamp = timestamp
    this.eventMessage = eventMessage
    this.eventUserRegistration = eventUserRegistration
    this.eventUserRename = eventUserRename
    this.attachments = attachments
  }

  /**
   * Create Event from a transport payload object.
   */
  static fromObject(data: {
    id?: number | null
    type: string
    timestamp: string
    eventMessage?: EventMessage | null
    eventUserRegistration?: EventUserRegistration | null
    eventUserRename?: EventUserRename | null
    attachments?: EventAttachment[]
  }): Event {
    return new Event(
      data.id ?? null,
      data.type,
      data.timestamp,
      data.eventMessage ?? null,
      data.eventUserRegistration ?? null,
      data.eventUserRename ?? null,
      data.attachments ?? []
    )
  }
}
