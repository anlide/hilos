/**
 * Parsers for entity payloads from transport (users, events).
 * Used by ChatEntitiesReceiver and by message handlers that need typed payloads.
 */

import { Event } from '@/types/domain/Event'
import { type Presence, isPresence } from '@/types/domain/Presence'

type JsonRecord = Record<string, unknown>

export type UserPayload = {
  id: number
  name: string
  lastActivity?: string | null
  presence?: Presence
  moderationState?: string | null
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
  userId: number | null
  botId?: number | null
  type: string
  timestamp: number | string
  data: Record<string, unknown> | string | null
}

export const isRecord = (value: unknown): value is JsonRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

export const isUserPayload = (value: unknown): value is UserPayload => {
  if (!isRecord(value) || typeof value.id !== 'number' || typeof value.name !== 'string') {
    return false
  }
  if (value.lastActivity !== undefined && value.lastActivity !== null && typeof value.lastActivity !== 'string') {
    return false
  }
  if (
    value.moderationState !== undefined &&
    value.moderationState !== null &&
    typeof value.moderationState !== 'string'
  ) {
    return false
  }
  return value.presence === undefined || isPresence(value.presence)
}

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
  return !(value.timestamp !== undefined && typeof value.timestamp !== 'number' && typeof value.timestamp !== 'string');
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
  const eventData =
    typeof p.data === 'string'
      ? (() => {
          try {
            return JSON.parse(p.data) as Record<string, unknown>
          } catch {
            return {}
          }
        })()
      : (p.data ?? {}) as Record<string, unknown>
  const timestampSeconds =
    typeof p.timestamp === 'string' ? Math.floor(Date.parse(p.timestamp) / 1000) : p.timestamp
  const timestampString = Number.isFinite(timestampSeconds)
    ? new Date((timestampSeconds as number) * 1000).toISOString().slice(0, 19).replace('T', ' ')
    : ''
  return Event.fromObject({
    id: p.id,
    userId: p.userId,
    botId: p.botId ?? null,
    type: p.type,
    timestamp: timestampString,
    data: eventData,
  })
}
