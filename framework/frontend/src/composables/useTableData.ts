import type { TableDataState, PendingChanges, ChangeMarkers, ApplyMutationsResult } from '../types/table'

/**
 * Compute display rows from table state.
 * Returns only the server-fetched rows; mutations are not applied until the user triggers apply.
 */
export function getTableDisplayRows<T>(state: TableDataState | undefined): T[] {
  if (!state) return []
  return state.rows as T[]
}

/**
 * Apply pending mutations to table state (called when user clicks "Apply changes").
 *
 * - Created: row is appended only if the current page has room (rows.length < limit, or limit=0).
 * - Updated: row data is merged into matching existing row.
 * - Deleted: row is removed from current page if present.
 *
 * Returns the new state (with cleared mutations) and metadata about the result.
 */
export function applyTableMutations(state: TableDataState): ApplyMutationsResult {
  const rows = [...state.rows]
  let totalCount = state.totalCount
  const { limit } = state
  let hasDeletes = false

  for (const m of state.mutations) {
    switch (m.type) {
      case 'created':
        totalCount++
        if (m.row && (limit === 0 || rows.length < limit)) {
          rows.push(m.row)
        }
        break
      case 'updated':
        if (m.row) {
          const idx = rows.findIndex(r => r['id'] === m.rowId)
          if (idx >= 0) {
            rows[idx] = { ...rows[idx], ...m.row }
          }
        }
        break
      case 'deleted':
        hasDeletes = true
        {
          const idx = rows.findIndex(r => r['id'] === m.rowId)
          if (idx >= 0) {
            rows.splice(idx, 1)
            totalCount--
          }
        }
        break
    }
  }

  return {
    state: { ...state, rows, totalCount, mutations: [] },
    hasDeletes,
  }
}

/**
 * Compute pending changes summary from table state.
 */
export function getTablePendingChanges(state: TableDataState | undefined): PendingChanges {
  if (!state) return { added: 0, updated: 0, deleted: 0 }

  let added = 0, updated = 0, deleted = 0
  for (const m of state.mutations) {
    switch (m.type) {
      case 'created': added++; break
      case 'updated': updated++; break
      case 'deleted': deleted++; break
    }
  }
  return { added, updated, deleted }
}

/**
 * Compute change markers (row IDs per change type) from table state.
 */
export function getTableChangeMarkers(state: TableDataState | undefined): ChangeMarkers {
  if (!state) return { added: [], updated: [], deleted: [] }

  const added: (string | number)[] = []
  const updated: (string | number)[] = []
  const deleted: (string | number)[] = []

  for (const m of state.mutations) {
    switch (m.type) {
      case 'created': added.push(m.rowId); break
      case 'updated': updated.push(m.rowId); break
      case 'deleted': deleted.push(m.rowId); break
    }
  }
  return { added, updated, deleted }
}
