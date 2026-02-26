/**
 * Partial user update payload (e.g. entities.updates.users: array of items with id + changed fields).
 * Used for incremental updates (rename: [{ id, name }]).
 */

import { type Presence, isPresence } from '@/types/domain/Presence'

type JsonRecord = Record<string, unknown>

export type PartialUserPayload = {
  id: number
  name?: string
  lastActivity?: string | null
  presence?: Presence
  moderationState?: string | null
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function isPartialUserPayload(value: unknown): value is PartialUserPayload {
  if (!isRecord(value) || typeof value.id !== 'number') {
    return false
  }
  if (value.name !== undefined && typeof value.name !== 'string') {
    return false
  }
  if (
    value.lastActivity !== undefined &&
    value.lastActivity !== null &&
    typeof value.lastActivity !== 'string'
  ) {
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

/**
 * Parse updates.users when sent as array of partial items: [{ id, name }, ...].
 */
export function parsePartialUserPayloads(value: unknown): PartialUserPayload[] | null {
  if (value === undefined || value === null) {
    return null
  }
  if (!Array.isArray(value)) {
    return null
  }
  if (!value.every(isPartialUserPayload)) {
    return null
  }
  return value
}
