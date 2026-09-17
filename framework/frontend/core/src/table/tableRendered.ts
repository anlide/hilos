// The fields of a row the screen draws, derived from the very columns the page already
// declared — sent with the window so the server compares a changed row by what a reader
// would see and not by the whole payload (table-subscription.md, "What applies at once
// and what waits"). Like tableCard.ts beside it, the file holds the derivation itself: a
// pure function that reads a column list and nothing else.

import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from './hilosTableColumn.js'

/**
 * Derives the fields of a row the declared columns draw — one pass over them:
 *
 * - a column contributes its key, except the column keyed
 *   {@link HILOS_TABLE_ACTIONS_KEY}, which names no field of the row;
 * - every column contributes the fields it declares it `reads`, the actions column
 *   included — for that one they are the whole contribution.
 *
 * Each field is named once, in the order it was first declared. The order carries no
 * meaning for the server, which keeps a row's fields in the row's own order, so a
 * column moved in the declaration changes nothing it compares.
 *
 * A column marked `detail` or kept out of the card still counts: it is drawn, only in
 * another place.
 *
 * @param columns The columns as the page declared them, in display order.
 */
export function hilosTableRenderedKeys(
  columns: readonly HilosTableColumn[],
): string[] {
  const keys = new Set<string>()
  for (const column of columns) {
    if (column.key !== HILOS_TABLE_ACTIONS_KEY) {
      keys.add(column.key)
    }
    for (const field of column.reads ?? []) {
      keys.add(field)
    }
  }

  return [...keys]
}
