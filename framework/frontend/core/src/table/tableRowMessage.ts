// Pure projection of table columns and an optional row message into wide table
// cells and card labels (mockups/admin/maintenance). Core defines the composition
// and boundaries, SDK views only render.

import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from './hilosTableColumn.js'

/**
 * A message replacing the visible tail of a table row with a single merged text
 * cell starting from a declared column.
 */
export interface HilosTableRowMessage {
  /**
   * The visible column key from which the tail of the row is merged.
   */
  readonly fromColumn: string
  /**
   * The text message displayed across the merged tail.
   */
  readonly text: string
}

/**
 * One cell in the row message projection: either an ordinary single-column cell
 * before the tail, or the merged cell spanning the rest of the visible columns.
 */
export interface HilosTableRowMessageCell {
  /**
   * Column key identifying this cell (matches the column's key or fromColumn).
   */
  readonly key: string
  /**
   * How many visible columns this cell spans (1 for ordinary cells, >= 1 for the merged tail).
   */
  readonly span: number
  /**
   * The text content of the cell: null for ordinary cells, non-null for the merged tail.
   */
  readonly text: string | null
}

/**
 * Projects declared table columns and an optional row message into a sequence of
 * cells with keys, column spans, and optional text.
 *
 * Without a message, all visible columns produce ordinary cells (span 1, text null).
 * With a message, columns before `fromColumn` remain ordinary, and the tail starting
 * from `fromColumn` is merged into a single cell spanning the remaining visible columns.
 *
 * Boundaries are calculated over visible columns, ignoring detail columns.
 * Throws TypeError when `fromColumn` is unknown, or when the merged tail contains an
 * actions column or a detail column, preventing controls and expanded fields from being
 * swallowed.
 *
 * @param columns The columns as declared by the table.
 * @param message The optional row message, or null/undefined for an ordinary row.
 * @returns Sequence of projected cells for the wide table row and card view.
 * @throws TypeError If fromColumn is unknown or the tail contains actions or detail columns.
 */
export function hilosTableRowMessageCells(
  columns: readonly HilosTableColumn[],
  message: HilosTableRowMessage | null | undefined,
): readonly HilosTableRowMessageCell[] {
  const visibleColumns = columns.filter((col) => col.detail !== true)

  if (message === null || message === undefined) {
    return visibleColumns.map((col) => ({
      key: col.key,
      span: 1,
      text: null,
    }))
  }

  const fromIndex = columns.findIndex((col) => col.key === message.fromColumn)
  if (fromIndex === -1) {
    throw new TypeError(`Unknown row message column: ${message.fromColumn}`)
  }

  const tailColumns = columns.slice(fromIndex)
  for (const col of tailColumns) {
    if (col.key === HILOS_TABLE_ACTIONS_KEY) {
      throw new TypeError('Row message tail cannot contain actions column')
    }
    if (col.detail === true) {
      throw new TypeError(
        `Row message tail cannot contain detail column: ${col.key}`,
      )
    }
  }

  const visibleFromIndex = visibleColumns.findIndex(
    (col) => col.key === message.fromColumn,
  )
  if (visibleFromIndex === -1) {
    throw new TypeError(
      `Unknown visible row message column: ${message.fromColumn}`,
    )
  }

  const cells: HilosTableRowMessageCell[] = []
  for (let i = 0; i < visibleFromIndex; i++) {
    cells.push({
      key: visibleColumns[i].key,
      span: 1,
      text: null,
    })
  }

  cells.push({
    key: message.fromColumn,
    span: visibleColumns.length - visibleFromIndex,
    text: message.text,
  })

  return cells
}
