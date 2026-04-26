import type {
  TableDataState,
  PendingChanges,
  ChangeMarkers,
  ApplyMutationsResult,
  TableRowMutationDTO,
} from '../types/table'

/**
 * Compute display rows from table state.
 * Returns only the rows from the current full snapshot; mutations are not applied until the user triggers apply.
 */
export function getTableDisplayRows<T>(state: TableDataState | undefined): T[] {
  if (!state) return []
  return state.rows as T[]
}

function applyMutationsToState(state: TableDataState, mutations: TableRowMutationDTO[]): ApplyMutationsResult {
  const rows = [...state.rows]
  let totalCount = state.totalCount
  const { limit } = state
  const rowKeyField = state.rowKeyField ?? 'id'
  let hasDeletes = false

  for (const m of mutations) {
    switch (m.type) {
      case 'create':
        if (m.row) {
          const idx = rows.findIndex(r => r[rowKeyField] === m.rowKey)
          if (idx >= 0) {
            rows[idx] = m.row
          } else {
            totalCount++
            if (limit === 0 || rows.length < limit) {
              rows.push(m.row)
            }
          }
        }
        break
      case 'update':
        if (m.row) {
          const idx = rows.findIndex(r => r[rowKeyField] === m.rowKey)
          if (idx >= 0) {
            rows[idx] = { ...rows[idx], ...m.row }
          }
        }
        break
      case 'delete':
        hasDeletes = true
        {
          const idx = rows.findIndex(r => r[rowKeyField] === m.rowKey)
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
 * Apply one immediate table mutation without changing the pending queue.
 */
export function applyTableMutation(state: TableDataState, mutation: TableRowMutationDTO): ApplyMutationsResult {
  const result = applyMutationsToState(state, [mutation])

  return {
    state: { ...result.state, mutations: state.mutations },
    hasDeletes: result.hasDeletes,
  }
}

/**
 * Apply pending mutations to table state (called when user clicks "Apply changes").
 *
 * - Create: row is appended only if the current page has room (rows.length < limit, or limit=0).
 * - Update: row data is merged into matching existing row.
 * - Delete: row is removed from current page if present.
 *
 * Returns the new state (with cleared mutations) and metadata about the result.
 */
export function applyTableMutations(state: TableDataState): ApplyMutationsResult {
  return applyMutationsToState(state, state.mutations)
}

/**
 * Compute pending changes summary from table state.
 */
export function getTablePendingChanges(state: TableDataState | undefined): PendingChanges {
  if (!state) return { added: 0, updated: 0, deleted: 0 }

  let added = 0, updated = 0, deleted = 0
  for (const m of state.mutations) {
    switch (m.type) {
      case 'create': added++; break
      case 'update': updated++; break
      case 'delete': deleted++; break
    }
  }
  return { added, updated, deleted }
}

/**
 * Compute change markers (row keys per change type) from table state.
 */
export function getTableChangeMarkers(state: TableDataState | undefined): ChangeMarkers {
  if (!state) return { added: [], updated: [], deleted: [] }

  const added: (string | number)[] = []
  const updated: (string | number)[] = []
  const deleted: (string | number)[] = []

  for (const m of state.mutations) {
    switch (m.type) {
      case 'create': added.push(m.rowKey); break
      case 'update': updated.push(m.rowKey); break
      case 'delete': deleted.push(m.rowKey); break
    }
  }
  return { added, updated, deleted }
}
