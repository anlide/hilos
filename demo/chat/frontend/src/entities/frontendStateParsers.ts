import { type Presence, isPresence } from '@/types/domain/Presence'

type JsonRecord = Record<string, unknown>

export type UserPresencePayload = {
  userId: number
  presence: Presence
}

export type UserConnectionStatsPayload = {
  userId: number
  onlineSessionCount: number
}

export type BotPresencePayload = {
  botId: number
  presence: Presence
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function isUserPresencePayload(value: unknown): value is UserPresencePayload {
  return isRecord(value) && typeof value.userId === 'number' && isPresence(value.presence)
}

export function parseUserPresencePayloads(value: unknown): UserPresencePayload[] | null {
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isUserPresencePayload) ? value : null
}

export function isUserConnectionStatsPayload(value: unknown): value is UserConnectionStatsPayload {
  return isRecord(value) && typeof value.userId === 'number' && typeof value.onlineSessionCount === 'number'
}

export function parseUserConnectionStatsPayloads(value: unknown): UserConnectionStatsPayload[] | null {
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isUserConnectionStatsPayload) ? value : null
}

export function isBotPresencePayload(value: unknown): value is BotPresencePayload {
  return isRecord(value) && typeof value.botId === 'number' && isPresence(value.presence)
}

export function parseBotPresencePayloads(value: unknown): BotPresencePayload[] | null {
  if (!Array.isArray(value)) {
    return null
  }
  return value.every(isBotPresencePayload) ? value : null
}
