/**
 * Parsers for entity payloads from transport (users, events).
 * Used by ChatEntitiesReceiver and by message handlers that need typed payloads.
 */

import {
  Event,
  type EventAttachment,
  type EventMessage,
  type EventUserRegistration,
  type EventUserRename,
} from '@/types/domain/Event'
type JsonRecord = Record<string, unknown>

export type UserPayload = {
  id: number
  name: string
  lastActivity?: string | null
}

export type BotPayload = {
  id: number
  name: string
  description?: string | null
  personality?: string | null
  style?: string | null
  topics?: string | null
  active?: boolean
}

export const isBotPayload = (value: unknown): value is BotPayload => {
  return isRecord(value) && typeof value.id === 'number' && typeof value.name === 'string'
}

export function parseBotPayloads(value: unknown): BotPayload[] | null {
  if (value === undefined || !Array.isArray(value)) return null
  return value.every(isBotPayload) ? value : null
}

export type EventPayload = {
  id: number
  type: string
  timestamp: number | string
  eventMessage?: EventMessagePayload | null
  eventUserRegistration?: EventUserRegistrationPayload | null
  eventUserRename?: EventUserRenamePayload | null
  attachments?: EventAttachmentPayload[]
}

export type EventMessagePayload = EventMessage

export type EventUserRegistrationPayload = EventUserRegistration

export type EventUserRenamePayload = EventUserRename

export type EventAttachmentPayload = EventAttachment

export const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

export const isUserPayload = (value: unknown): value is UserPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.name !== 'string') {
    return false
  }
  if (
    'onlineSessionCount' in value
    || 'presence' in value
    || 'sessionToken' in value
    || 'moderationState' in value
  ) {
    return false
  }
  if (value.lastActivity !== undefined && value.lastActivity !== null && typeof value.lastActivity !== 'string') {
    return false
  }
  return true
}

const isNullableNumber = (value: unknown): value is number | null =>
  value === null || typeof value === 'number'

export function parseUserPayloads(value: unknown): UserPayload[] | null {
  if (value === undefined) {
    return null
  }
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isUserPayload) ? value : null
}

export function parseUserIds(value: unknown): number[] | null {
  if (value === undefined) {
    return null
  }
  if (!Array.isArray(value)) {
    return null
  }
  return value.every((item) => typeof item === 'number') ? value : null
}

export const isEventPayload = (value: unknown): value is EventPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.type !== 'string') {
    return false
  }
  if (
    'data' in value
    || 'userId' in value
    || 'botId' in value
    || 'message' in value
    || 'authorUserId' in value
    || 'authorBotId' in value
    || 'targetUserId' in value
    || 'actorUserId' in value
    || 'oldName' in value
    || 'newName' in value
  ) {
    return false
  }
  if (typeof value.timestamp !== 'number' && typeof value.timestamp !== 'string') {
    return false
  }
  if ('eventMessage' in value && value.eventMessage !== null && !isEventMessagePayload(value.eventMessage)) {
    return false
  }
  if (
    'eventUserRegistration' in value
    && value.eventUserRegistration !== null
    && !isEventUserRegistrationPayload(value.eventUserRegistration)
  ) {
    return false
  }
  if (
    'eventUserRename' in value
    && value.eventUserRename !== null
    && !isEventUserRenamePayload(value.eventUserRename)
  ) {
    return false
  }
  if ('attachments' in value && parseEventAttachmentPayloads(value.attachments) === null) {
    return false
  }
  return true
}

export const isEventMessagePayload = (value: unknown): value is EventMessagePayload => {
  return isRecord(value)
    && typeof value.eventId === 'number'
    && isNullableNumber(value.authorUserId)
    && isNullableNumber(value.authorBotId)
    && typeof value.message === 'string'
}

export const isEventUserRegistrationPayload = (value: unknown): value is EventUserRegistrationPayload => {
  return isRecord(value)
    && typeof value.eventId === 'number'
    && typeof value.targetUserId === 'number'
}

export const isEventUserRenamePayload = (value: unknown): value is EventUserRenamePayload => {
  return isRecord(value)
    && typeof value.eventId === 'number'
    && typeof value.targetUserId === 'number'
    && isNullableNumber(value.actorUserId)
    && typeof value.oldName === 'string'
    && typeof value.newName === 'string'
}

export function parseEventAttachmentPayloads(value: unknown): EventAttachmentPayload[] | null {
  if (value === undefined) {
    return []
  }
  if (!Array.isArray(value)) {
    return null
  }
  if (!value.every((item) => (
    isRecord(item)
    && typeof item.id === 'number'
    && typeof item.eventId === 'number'
    && typeof item.filename === 'string'
    && typeof item.mimeType === 'string'
    && !('storedName' in item)
  ))) {
    return null
  }

  return value as EventAttachmentPayload[]
}

export function parseEventPayloads(value: unknown): EventPayload[] | null {
  if (value === undefined) {
    return null
  }
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isEventPayload) ? value : null
}

/**
 * Convert transport event payload to domain Event (for handlers that need Event instance).
 */
export function eventPayloadToEvent(p: EventPayload): InstanceType<typeof Event> {
  const timestampSeconds =
    typeof p.timestamp === 'string' ? Math.floor(Date.parse(p.timestamp) / 1000) : p.timestamp
  const timestampString = Number.isFinite(timestampSeconds)
    ? new Date((timestampSeconds as number) * 1000).toISOString().slice(0, 19).replace('T', ' ')
    : ''
  return Event.fromObject({
    id: p.id,
    type: p.type,
    timestamp: timestampString,
    eventMessage: p.eventMessage ?? null,
    eventUserRegistration: p.eventUserRegistration ?? null,
    eventUserRename: p.eventUserRename ?? null,
    attachments: p.attachments ?? [],
  })
}
