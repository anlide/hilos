/**
 * Transport shape for explicit frontend state collection changes.
 * Keys are stable client collection names; values are arrays of frontend state rows.
 */
export interface FrontendChangesEnvelope {
  full?: Record<string, unknown[]>
  updates?: Record<string, unknown[]>
  deleted?: Record<string, number[]>
  replaceFull?: string[]
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isNumberArray(value: unknown): value is number[] {
  return Array.isArray(value) && value.every((item) => typeof item === 'number')
}

export function extractFrontendChangesEnvelope(payload: unknown): FrontendChangesEnvelope | null {
  if (!isRecord(payload) || payload.frontend === undefined) {
    return null
  }

  const frontend = payload.frontend
  if (!isRecord(frontend)) {
    return null
  }

  const result: FrontendChangesEnvelope = {}

  if (isRecord(frontend.full)) {
    result.full = {}
    for (const [key, value] of Object.entries(frontend.full)) {
      if (Array.isArray(value)) {
        result.full[key] = value
      }
    }
  }

  if (isRecord(frontend.updates)) {
    result.updates = {}
    for (const [key, value] of Object.entries(frontend.updates)) {
      if (Array.isArray(value)) {
        result.updates[key] = value
      }
    }
  }

  if (isRecord(frontend.deleted)) {
    result.deleted = {}
    for (const [key, value] of Object.entries(frontend.deleted)) {
      if (isNumberArray(value)) {
        result.deleted[key] = value
      }
    }
  }

  if (Array.isArray(frontend.replaceFull)) {
    const replaceFull = frontend.replaceFull.filter((key): key is string => typeof key === 'string')
    if (replaceFull.length > 0) {
      result.replaceFull = replaceFull
    }
  }

  return result
}

export function hasFrontendChanges(payload: unknown): boolean {
  if (!isRecord(payload) || payload.frontend === undefined) {
    return false
  }
  return isRecord(payload.frontend)
}
