/**
 * Browser page state transport produced by BrowserContext.
 *
 * Rows are page-shaped logical rows. Each row is keyed by `rowKey` and carries
 * source fragments under their backend source keys.
 */
export interface BrowserPageRow {
  rowKey: string | number
  sources: Record<string, unknown>
}

export interface BrowserPageTableChanges {
  rows?: BrowserPageRow[]
  deleted?: Array<string | number>
}

export interface BrowserPagePayload {
  tables: Record<string, BrowserPageTableChanges>
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isBrowserPageRow(value: unknown): value is BrowserPageRow {
  if (!isRecord(value)) {
    return false
  }
  if (typeof value.rowKey !== 'string' && typeof value.rowKey !== 'number') {
    return false
  }

  return isRecord(value.sources)
}

function isDeletedRowKeys(value: unknown): value is Array<string | number> {
  return Array.isArray(value)
    && value.every((rowKey) => typeof rowKey === 'string' || typeof rowKey === 'number')
}

function parseBrowserTableChanges(value: unknown): BrowserPageTableChanges | null {
  if (!isRecord(value)) {
    return null
  }
  if (
    'totalCount' in value
    || 'offset' in value
    || 'limit' in value
    || 'catalogKeys' in value
    || 'rowKeyField' in value
  ) {
    return null
  }

  const changes: BrowserPageTableChanges = {}
  if (value.rows !== undefined) {
    if (!Array.isArray(value.rows) || !value.rows.every(isBrowserPageRow)) {
      return null
    }
    changes.rows = value.rows
  }

  if (value.deleted !== undefined) {
    if (!isDeletedRowKeys(value.deleted)) {
      return null
    }
    changes.deleted = value.deleted
  }

  return changes.rows !== undefined || changes.deleted !== undefined ? changes : null
}

export function extractBrowserPagePayload(payload: unknown): BrowserPagePayload | null {
  if (!isRecord(payload) || !isRecord(payload.tables)) {
    return null
  }

  const tables: Record<string, BrowserPageTableChanges> = {}
  for (const [tableKey, rawChanges] of Object.entries(payload.tables)) {
    const changes = parseBrowserTableChanges(rawChanges)
    if (changes === null) {
      return null
    }
    tables[tableKey] = changes
  }

  return { tables }
}

export function hasBrowserPagePayload(payload: unknown): boolean {
  return extractBrowserPagePayload(payload) !== null
}
