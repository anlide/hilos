import { SignalDefinition } from '../services/signals'
import type { TableRowMutationDTO, TableMutationType } from '../types/table'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const isTableMutationType = (value: unknown): value is TableMutationType => {
  return value === 'create' || value === 'update' || value === 'delete'
}

const parseTableRowMutationDTO = (value: unknown): TableRowMutationDTO | null => {
  if (!isRecord(value) || !isTableMutationType(value.type)) {
    return null
  }
  const rowKey = value.rowKey
  if (typeof rowKey !== 'string' && typeof rowKey !== 'number') {
    return null
  }
  const row = value.row
  if (row !== undefined && !isRecord(row)) {
    return null
  }
  return {
    type: value.type,
    rowKey,
    ...(row !== undefined ? { row } : {}),
  }
}

/**
 * Signal: `table_data` — bulk payload with one-or-many full table snapshots.
 *
 * Paging/partial loading is reserved in the backend API but is not implemented yet.
 */
export interface TableDataPayload {
  tables: Record<string, unknown>
}

export const tableData = new SignalDefinition<'table_data', TableDataPayload>(
  'table_data',
  (raw: unknown): TableDataPayload | null => {
    if (!isRecord(raw) || !isRecord(raw.tables)) {
      return null
    }
    return { tables: raw.tables }
  },
)

/**
 * Signal: `table_mutation` — single row mutation applied to a named table.
 */
export interface TableMutationPayload {
  tableKey: string
  mutation: TableRowMutationDTO
}

export const tableMutation = new SignalDefinition<'table_mutation', TableMutationPayload>(
  'table_mutation',
  (raw: unknown): TableMutationPayload | null => {
    if (!isRecord(raw) || typeof raw.tableKey !== 'string') {
      return null
    }
    const mutation = parseTableRowMutationDTO(raw.mutation)
    if (mutation === null) {
      return null
    }
    return { tableKey: raw.tableKey, mutation }
  },
)

/**
 * Signal: `table_action_error` — failure report for a table-level action.
 */
export interface TableActionErrorPayload {
  tableKey: string
  message?: string
}

export const tableActionError = new SignalDefinition<'table_action_error', TableActionErrorPayload>(
  'table_action_error',
  (raw: unknown): TableActionErrorPayload | null => {
    if (!isRecord(raw) || typeof raw.tableKey !== 'string') {
      return null
    }
    const message = typeof raw.message === 'string' ? raw.message : undefined
    return message !== undefined ? { tableKey: raw.tableKey, message } : { tableKey: raw.tableKey }
  },
)
