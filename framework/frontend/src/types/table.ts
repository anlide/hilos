/**
 * Table mutation types matching the backend TableMutationType enum.
 */
export type TableMutationType = 'created' | 'updated' | 'deleted'

/**
 * Single mutation entry from the server (broadcast in real-time).
 */
export interface TableMutationEntry {
  type: TableMutationType
  rowKey: string | number
  row?: Record<string, unknown>
}

/**
 * Table data state stored per table key.
 * Stateless: rows come from the latest full snapshot, mutations accumulate until refresh.
 */
export interface TableDataState {
  rows: Record<string, unknown>[]
  totalCount: number
  offset: number
  limit: number
  mutations: TableMutationEntry[]
  /** Row key field for matching mutations (default: 'id') */
  rowKeyField?: string
}

/**
 * Pending changes summary for the Table component.
 */
export interface PendingChanges {
  added: number
  updated: number
  deleted: number
}

/**
 * Change markers (row keys) for the Table component.
 */
export interface ChangeMarkers {
  added: (string | number)[]
  updated: (string | number)[]
  deleted: (string | number)[]
}

/**
 * Result of applying pending mutations to table state.
 */
export interface ApplyMutationsResult {
  state: TableDataState
  hasDeletes: boolean
}
