import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from './hilosTableColumn.js'

/** The two layouts of one server window. */
export type HilosTableView = 'table' | 'tiles'

/** A table opts into both layouts; the first is its opening view. */
export interface HilosTableViews {
  /** Stable application table identity, independent of rows, routes and instances. */
  readonly tableKey: string
  readonly order: readonly ['table', 'tiles'] | readonly ['tiles', 'table']
  /** An ordinary declared column whose cell supplies the tile picture. */
  readonly imageColumn: string
}

// TODO(i18n): resolve the shared view labels through the translation catalog.
export const TABLE_VIEW_COPY = {
  table: 'Table',
  tiles: 'Tiles',
  group: 'Table view',
  noImage: 'No image',
} as const

/**
 * Validate a local declaration against the current columns and keep a stable copy.
 * Dynamic columns pass through the same check before being exposed by the frame.
 *
 * @param value The declaration, including untyped runtime input.
 * @param columns The currently resolved columns.
 * @throws TypeError If the table key, view order or image column is invalid.
 */
export function hilosTableViews(
  value: unknown,
  columns: readonly HilosTableColumn[],
): HilosTableViews | undefined {
  if (value === undefined) {
    return undefined
  }
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const input = value as Record<string, unknown>
    const { tableKey, order, imageColumn } = input
    if (
      typeof tableKey === 'string' &&
      tableKey.trim().length > 0 &&
      Array.isArray(order) &&
      order.length === 2 &&
      ((order[0] === 'table' && order[1] === 'tiles') ||
        (order[0] === 'tiles' && order[1] === 'table')) &&
      typeof imageColumn === 'string' &&
      imageColumn.trim().length > 0 &&
      imageColumn !== HILOS_TABLE_ACTIONS_KEY &&
      columns.some(({ key, detail }) => key === imageColumn && detail !== true)
    ) {
      return Object.freeze({
        tableKey,
        order: Object.freeze([...order]) as HilosTableViews['order'],
        imageColumn,
      })
    }
  }

  throw new TypeError('Invalid table views declaration')
}
