/**
 * Transport shape for entity changes payload.
 * Matches backend EntitiesChangesDTO when serialized (toArray with toFrontend).
 * Keys are collection names (e.g. 'users', 'events'); values are arrays.
 */
export interface EntitiesEnvelope {
  full?: Record<string, unknown[]>
  updates?: Record<string, unknown[]>
  deleted?: Record<string, number[]>
}

/**
 * Check if value is a plain object (not null, not array).
 */
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Check that array elements are numbers.
 */
function isNumberArray(value: unknown): value is number[] {
  if (!Array.isArray(value)) {
    return false
  }
  return value.every((item) => typeof item === 'number')
}

/**
 * Extract entities envelope from a message payload.
 * Returns null if payload has no entities or shape is invalid.
 *
 * @param payload - Raw message payload (e.g. message.data)
 * @returns Envelope with full/updates/deleted or null
 */
export function extractEntitiesEnvelope(payload: unknown): EntitiesEnvelope | null {
  if (!isRecord(payload) || payload.entities === undefined) {
    return null
  }

  const entities = payload.entities
  if (!isRecord(entities)) {
    return null
  }

  const full = isRecord(entities.full) ? (entities.full as Record<string, unknown[]>) : undefined
  const updates = isRecord(entities.updates) ? (entities.updates as Record<string, unknown[]>) : undefined
  const deleted = isRecord(entities.deleted) ? (entities.deleted as Record<string, unknown>) : undefined

  const result: EntitiesEnvelope = {}

  if (full !== undefined) {
    result.full = {}
    for (const [key, val] of Object.entries(full)) {
      if (Array.isArray(val)) {
        result.full[key] = val
      }
    }
  }

  if (updates !== undefined) {
    result.updates = {}
    for (const [key, val] of Object.entries(updates)) {
      if (Array.isArray(val)) {
        result.updates[key] = val
      }
    }
  }

  if (deleted !== undefined) {
    result.deleted = {}
    for (const [key, val] of Object.entries(deleted)) {
      if (isNumberArray(val)) {
        result.deleted[key] = val
      }
    }
  }

  return result
}

/**
 * Check if payload has an entities field (object).
 * Use before calling receiver.apply() to avoid unnecessary work.
 */
export function hasEntities(payload: unknown): boolean {
  if (!isRecord(payload) || payload.entities === undefined) {
    return false
  }
  return isRecord(payload.entities)
}
