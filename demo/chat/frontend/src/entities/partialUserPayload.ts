/**
 * Partial user update payload (e.g. entities.updates.users: array of items with id + changed fields).
 * Used for incremental updates (rename: [{ id, name }]).
 */

type JsonRecord = Record<string, unknown>

export type PartialUserPayload = {
  id: number
  name?: string
  lastActivity?: string | null
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function isPartialUserPayload(value: unknown): value is PartialUserPayload {
  if (!isRecord(value) || typeof value.id !== 'number') {
    return false
  }
  if ('onlineSessionCount' in value || 'presence' in value || 'sessionToken' in value || 'moderationState' in value) {
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
  return true
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
