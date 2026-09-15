// The card a row projects to on a narrow screen, derived from the very columns the
// page already declared — the framework builds it so no project writes a second
// markup for its table (mockups/components/table section 9). Unlike hilosTableColumn.ts
// and tableFrame.ts, which hold declaration types only, this file also holds the
// derivation itself: a pure function that reads a column list and nothing else. There
// is no window logic in it — that lives in the TableViewportController — and no cell
// value either: the card carries the LAYOUT of the declared columns, while the page
// keeps drawing the content of every cell, on a card exactly as in a row.

import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from './hilosTableColumn.js'

/**
 * Which declared column takes which place of a card. The four places are the four
 * the mockup draws, and there is no fifth. Drawing them is a view's job: HIL-806
 * (Vue) and HIL-815 (React, Angular).
 */
export interface HilosTableCard {
  /** The head of the card, drawn without a label; null when there is no column for it. */
  readonly title: HilosTableColumn | null
  /** The right of the head, drawn without a label; null unless a column asked for it. */
  readonly badge: HilosTableColumn | null
  /** The labelled lines of the card body, in declaration order. */
  readonly fields: readonly HilosTableColumn[]
  /** The row controls, drawn full width at the foot of the card. */
  readonly actions: HilosTableColumn | null
}

/**
 * Derives the card layout of a row from the declared columns — one pass over them,
 * then the default title:
 *
 * - a column marked `hidden` is not in the card at all, and neither is one marked
 *   `detail` — the ticket that asked for the panel says that on a narrow screen such
 *   a field is shown by expanding the card, not inside its body (tableDetail.ts);
 * - a column keyed {@link HILOS_TABLE_ACTIONS_KEY} takes the actions place, whatever
 *   it is marked — it has no value of its own in the row, so making it the title
 *   would title the card with nothing;
 * - a column marked `title`, `badge` or `field` takes that place;
 * - an unmarked column is a field; the head falls to the first unmarked column
 *   only when no column asked for it.
 *
 * The title, the badge and the actions hold one column each. A second claimant to a
 * taken place becomes a field instead — quietly, without refusing to build the
 * layout: the declaration is read by the developer of the page, not by its user, and
 * dropping a whole table over two marks costs more than a mark drawn as a field.
 *
 * @param columns The columns as the page declared them, in display order.
 */
export function hilosTableCard(
  columns: readonly HilosTableColumn[],
): HilosTableCard {
  let title: HilosTableColumn | null = null
  let badge: HilosTableColumn | null = null
  let actions: HilosTableColumn | null = null
  let defaultTitleIndex: number | null = null
  const fields: HilosTableColumn[] = []

  for (const column of columns) {
    if (column.card === 'hidden' || column.detail === true) {
      continue
    }

    if (column.key === HILOS_TABLE_ACTIONS_KEY) {
      if (actions === null) {
        actions = column
      } else {
        fields.push(column)
      }

      continue
    }

    if (column.card === 'title') {
      if (title === null) {
        title = column
      } else {
        fields.push(column)
      }

      continue
    }

    if (column.card === 'badge') {
      if (badge === null) {
        badge = column
      } else {
        fields.push(column)
      }

      continue
    }

    if (column.card === 'field') {
      fields.push(column)

      continue
    }

    if (defaultTitleIndex === null) {
      defaultTitleIndex = fields.length
    }

    fields.push(column)
  }

  if (title === null && defaultTitleIndex !== null) {
    const [defaultTitle] = fields.splice(defaultTitleIndex, 1)

    title = defaultTitle
  }

  return { title, badge, fields, actions }
}
