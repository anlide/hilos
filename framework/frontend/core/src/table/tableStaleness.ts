// What a table says when one of the sources it is assembled from stops being kept up
// to date (mockups/components/table, section "part of the data is behind"). Like
// tableCard.ts and tableSortOrder.ts this file holds pure functions over a window and
// a column list plus the words that go with them — no window logic, which lives in
// the TableViewportController, and no markup, which belongs to each view package.
//
// The words live here rather than in the three view packages for the reason
// RT_STALENESS_COPY next door already carries (protocol/rtStaleness.ts): three shells
// wording the same fact each in its own way is three different products, and the
// React and Angular views of this (HIL-818) get the sentences ready-made.
//
// The mark the mockup draws INSIDE the cells of the lagging column is not here and
// cannot be: the body of a row is drawn by the page through the `#row` slot, so the
// framework owns no cell to put an icon in. Design debt D-051 records that, and the
// framework marks the three places it does own — the bar, the column header, and the
// narrow row-state cell.

import { type HilosTableColumn } from './hilosTableColumn.js'
import { type TableViewportRow } from './TableViewportController.js'

/**
 * The words the freeze is spoken with — the bar over the table, the refusal the sort
 * control leaves behind, and the hidden text of the two snowflakes.
 *
 * The bar is three parts, as the mockup draws it: what froze, why, and that the rest
 * is live. `{columns}` is replaced with the labels of the frozen columns in
 * declaration order. Why it says no more than "the link to its source was lost": the
 * wire carries a list of slot keys and nothing else — no reason and no moment
 * (HIL-800) — so the sentence borrows the words the page-wide mark already uses
 * (`RT_STALENESS_COPY.frozen`).
 */
export const TABLE_STALENESS_COPY = {
  /** The bar when exactly one declared column is frozen. */
  barOne:
    '{columns} is not updating: the link to its source was lost. The other columns are live.',
  /** The bar when two or more declared columns are frozen. */
  barMany:
    '{columns} are not updating: the link to their source was lost. The other columns are live.',
  /** The bar when a source froze but no column said it is built from it. */
  barUnnamed:
    'Some values here are not updating: the link to their source was lost. The other columns are live.',
  /** Why the header of a frozen column offers no sort control. */
  sortRefusal:
    'Sorting by this column is unavailable: its source is lagging, and an order over stale values would be a lie. Sort by another column.',
  /** The hidden words of the snowflake in the header of a frozen, unsortable column. */
  columnMark: "This column's source is lagging: its values may be out of date.",
  /** The hidden words of the snowflake in the row-state cell of a frozen row. */
  rowMark: 'Some values in this row are out of date.',
} as const

/**
 * The sources that froze anywhere in the shown window, as one set.
 *
 * A source counts as frozen for the window when at least ONE shown row names it: a
 * column holding a mix of fresh and stale values is no more trustworthy than one
 * holding stale values throughout, and the bar and the header both speak about the
 * window rather than about a row.
 *
 * A placeholder left by a removed row takes no part — it carries no values whose
 * freshness could be spoken of.
 *
 * @param rows The window as the controller built it, placeholders included.
 */
export function hilosTableStaleSources(
  rows: readonly TableViewportRow<unknown>[],
): ReadonlySet<string> {
  const sources = new Set<string>()

  for (const row of rows) {
    if (row.placeholder) {
      continue
    }
    for (const source of row.staleSources) {
      sources.add(source)
    }
  }

  return sources
}

/**
 * The declared columns whose source is in that set, in declaration order.
 *
 * A column that declared no source is never here: the framework does not know what
 * it is assembled from, and guessing would put a snowflake on a live column.
 *
 * @param columns The columns as the page declared them, in display order.
 * @param staleSources The sources that froze in the window.
 */
export function hilosTableStaleColumns(
  columns: readonly HilosTableColumn[],
  staleSources: ReadonlySet<string>,
): readonly HilosTableColumn[] {
  return columns.filter(
    (column) => column.source !== undefined && staleSources.has(column.source),
  )
}

/**
 * The sentence of the bar over the table, or undefined when nothing froze.
 *
 * With a frozen source but no column naming it the bar still appears, in the generic
 * wording: a table whose columns declared no source would otherwise show yesterday's
 * number next to today's in silence — the very thing the mockup's node is written
 * against.
 *
 * @param staleColumns The frozen columns, in declaration order.
 * @param anyStale Whether any source froze in the window at all.
 */
export function hilosTableStaleLabel(
  staleColumns: readonly HilosTableColumn[],
  anyStale: boolean,
): string | undefined {
  if (!anyStale) {
    return undefined
  }
  if (staleColumns.length === 0) {
    return TABLE_STALENESS_COPY.barUnnamed
  }

  const labels = staleColumns.map((column) => column.label)
  if (labels.length === 1) {
    return TABLE_STALENESS_COPY.barOne.replace('{columns}', labels[0])
  }
  const listed = `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`

  return TABLE_STALENESS_COPY.barMany.replace('{columns}', listed)
}
