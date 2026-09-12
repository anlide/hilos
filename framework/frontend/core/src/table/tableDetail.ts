// The panel a row expands into, derived — like the card next door in tableCard.ts —
// from the very columns the page already declared (mockups/components/table section 4).
// A field that did not fit a column of its own is marked where the columns are
// declared, not listed a second time somewhere else: a second register of the fields
// of one table would be two places to keep in step, and nothing would catch them
// drifting apart.
//
// Pure functions over a column list and the words that go with them, and nothing else:
// there is no window logic here — that lives in the TableViewportController, which
// holds which rows are expanded — and no cell value either. The panel carries the
// LAYOUT of the marked columns, while the page draws the content of every field of it,
// in the panel exactly as in a row.

import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from './hilosTableColumn.js'

/**
 * The words the expansion is spoken with — the hidden name of the control and what
 * stands in a field the page left undrawn.
 *
 * They live here rather than in the three view packages for the reason
 * TABLE_STALENESS_COPY next door already carries: three shells wording one fact each
 * in its own way is three different products, and the React and Angular views of this
 * (HIL-816) get the sentences ready-made.
 */
export const TABLE_DETAIL_COPY = {
  /** The hidden words of the control on a collapsed row. */
  show: 'Show details',
  /** The hidden words of the control on an expanded row. */
  hide: 'Hide details',
  /**
   * What stands in a field the page declared but drew nothing into. A silent empty
   * line would read as "there is no value", when in truth it was simply not drawn —
   * so the panel says the same dash the cells of a table already say.
   */
  empty: '—',
} as const

/**
 * The columns that live in the panel rather than in the row, in declaration order.
 *
 * The order is the order they were declared in, and there is no second answer to
 * that question: neither alphabetical nor rearranged to the width. What the page
 * author wrote is what the reader gets, on every view layer alike.
 *
 * A column keyed {@link HILOS_TABLE_ACTIONS_KEY} is skipped whatever it is marked —
 * it has no value of its own in the row, so a panel field built off it would carry
 * nothing. The same rule the card is built by, for the same reason.
 *
 * @param columns The columns as the page declared them, in display order.
 */
export function hilosTableDetailFields(
  columns: readonly HilosTableColumn[],
): readonly HilosTableColumn[] {
  return columns.filter(
    (column) =>
      column.detail === true && column.key !== HILOS_TABLE_ACTIONS_KEY,
  )
}
