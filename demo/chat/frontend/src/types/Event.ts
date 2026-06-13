// The chat event entity, its inline detail fragments, and its collections. An
// event is an entity (it bears an id); its message/registration/rename details
// ride the list item as inline slots (no id of their own), while a published
// attachment is again an entity. The event stream selector reads these typed
// projections instead of touching raw slots.
import {
  entityCollection,
  readNumber,
  readNumberOrNull,
  readString,
  type Entity,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../session'

/** Canonical entity types — keep in sync with the backend event sources. */
export const EVENT_TYPE = 'event'
export const EVENT_ATTACHMENT_TYPE = 'eventAttachment'

/** A chat event header: its kind and when it happened. */
export interface Event extends Entity {
  readonly type: string
  readonly timestamp: string
}

/** A published file attached to a message event. */
export interface EventAttachment extends Entity {
  readonly eventId: number
  readonly filename: string
  readonly mimeType: string
}

/** The message detail of a `message_sent` event. */
export interface EventMessage {
  readonly authorUserId: number | null
  readonly authorBotId: number | null
  readonly message: string
}

/** The registration detail of a `user_registered` event. */
export interface EventUserRegistration {
  readonly targetUserId: number
}

/** The rename detail of a `user_renamed` / `user_renamed_by_admin` event. */
export interface EventUserRename {
  readonly targetUserId: number
  readonly actorUserId: number | null
  readonly oldName: string
  readonly newName: string
}

/**
 * Project a committed event's raw fields into the typed entity.
 *
 * @param fields The event entity's committed fields.
 */
export function eventFromFields(fields: Readonly<Record<string, unknown>>): Event {
  return {
    id: readNumber(fields, 'id'),
    type: readString(fields, 'type'),
    timestamp: readString(fields, 'timestamp'),
  }
}

/**
 * Project a committed attachment's raw fields into the typed entity.
 *
 * @param fields The attachment entity's committed fields.
 */
export function eventAttachmentFromFields(
  fields: Readonly<Record<string, unknown>>,
): EventAttachment {
  return {
    id: readNumber(fields, 'id'),
    eventId: readNumber(fields, 'eventId'),
    filename: readString(fields, 'filename'),
    mimeType: readString(fields, 'mimeType'),
  }
}

/**
 * Project an inline message slot into the typed detail, or null when absent.
 *
 * @param slot The list item's `eventMessages` inline slot.
 */
export function eventMessageFrom(
  slot: Record<string, unknown> | undefined,
): EventMessage | null {
  if (!slot) {
    return null
  }

  return {
    authorUserId: readNumberOrNull(slot, 'authorUserId'),
    authorBotId: readNumberOrNull(slot, 'authorBotId'),
    message: readString(slot, 'message'),
  }
}

/**
 * Project an inline registration slot into the typed detail, or null.
 *
 * @param slot The list item's `eventUserRegistrations` inline slot.
 */
export function eventRegistrationFrom(
  slot: Record<string, unknown> | undefined,
): EventUserRegistration | null {
  if (!slot) {
    return null
  }

  return { targetUserId: readNumber(slot, 'targetUserId') }
}

/**
 * Project an inline rename slot into the typed detail, or null.
 *
 * @param slot The list item's `eventUserRenames` inline slot.
 */
export function eventRenameFrom(
  slot: Record<string, unknown> | undefined,
): EventUserRename | null {
  if (!slot) {
    return null
  }

  return {
    targetUserId: readNumber(slot, 'targetUserId'),
    actorUserId: readNumberOrNull(slot, 'actorUserId'),
    oldName: readString(slot, 'oldName'),
    newName: readString(slot, 'newName'),
  }
}

/** The event collection: typed reference resolution for the `event` type. */
export const Events: EntityCollection<Event> = entityCollection(
  scopes,
  EVENT_TYPE,
  eventFromFields,
)

/** The attachment collection: typed resolution for the `eventAttachment` type. */
export const EventAttachments: EntityCollection<EventAttachment> =
  entityCollection(scopes, EVENT_ATTACHMENT_TYPE, eventAttachmentFromFields)
