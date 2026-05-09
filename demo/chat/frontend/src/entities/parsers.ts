/**
 * Parsers for entity payloads from transport (users, events).
 * Used by ChatEntitiesReceiver and by message handlers that need typed payloads.
 */

import { Event } from '@/types/domain/Event'
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
  message?: string | null
  authorUserId?: number | null
  authorBotId?: number | null
  targetUserId?: number | null
  actorUserId?: number | null
  oldName?: string | null
  newName?: string | null
  attachments?: EventAttachmentPayload[]
}

export type EventAttachmentPayload = {
  id: number
  eventId: number
  filename: string
  mimeType: string
}

export const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

export const isUserPayload = (value: unknown): value is UserPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.name !== 'string') {
    return false
  }
  if ('onlineSessionCount' in value || 'presence' in value || 'sessionToken' in value || 'moderationState' in value) {
    return false
  }
  if (value.lastActivity !== undefined && value.lastActivity !== null && typeof value.lastActivity !== 'string') {
    return false
  }
  return true
}

const isNullableNumber = (value: unknown): value is number | null =>
  value === null || typeof value === 'number'

const isNullableString = (value: unknown): value is string | null =>
  value === null || typeof value === 'string'

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
  if ('data' in value || 'userId' in value || 'botId' in value) {
    return false
  }
  if (typeof value.timestamp !== 'number' && typeof value.timestamp !== 'string') {
    return false
  }
  if ('message' in value && !isNullableString(value.message)) {
    return false
  }
  if ('authorUserId' in value && !isNullableNumber(value.authorUserId)) {
    return false
  }
  if ('authorBotId' in value && !isNullableNumber(value.authorBotId)) {
    return false
  }
  if ('targetUserId' in value && !isNullableNumber(value.targetUserId)) {
    return false
  }
  if ('actorUserId' in value && !isNullableNumber(value.actorUserId)) {
    return false
  }
  if ('oldName' in value && !isNullableString(value.oldName)) {
    return false
  }
  if ('newName' in value && !isNullableString(value.newName)) {
    return false
  }
  if ('attachments' in value && parseEventAttachmentPayloads(value.attachments) === null) {
    return false
  }
  return true
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
    message: p.message ?? null,
    authorUserId: p.authorUserId ?? null,
    authorBotId: p.authorBotId ?? null,
    targetUserId: p.targetUserId ?? null,
    actorUserId: p.actorUserId ?? null,
    oldName: p.oldName ?? null,
    newName: p.newName ?? null,
    attachments: p.attachments ?? [],
  })
}
