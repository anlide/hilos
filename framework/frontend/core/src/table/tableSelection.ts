// What a table holds MARKED — the rows a bulk action will run over — and the
// state a view draws the selection panel from. Like tableFrame.ts this file
// holds declaration types only, no window logic: the marks themselves live on
// the TableViewportController, because every rule about them is a rule about
// the window (table-subscription.md, "Selection and bulk actions").
//
// Marks live INSIDE the window: paging, filtering or re-sorting clears them, the
// header checkbox takes only what is visible, and an action over rows that are
// not on screen is "all matching the filter" — a condition, called by its own
// name rather than reached by paging.

import { type ReadonlySignal } from '../state/signal.js'

/**
 * What a bulk action runs over: the rows named one by one, or the condition that
 * describes them — exactly one of the two, because that is the shape the request
 * carries on the wire (table-subscription.md, "Selection and bulk actions").
 *
 * There is no third shape "condition minus these rows": a condition carries no
 * exceptions, so such a choice would have no way to travel and no answer the
 * backend could give it.
 */
export type HilosTableSelectionTarget =
  | { readonly kind: 'rows'; readonly rowKeys: readonly string[] }
  | { readonly kind: 'filter'; readonly filter: Record<string, unknown> }

/**
 * What the checkbox in the header shows over the live rows of the window: none
 * of them marked, some of them, or all of them. A window with no live rows in it
 * reads as `none` — there is nothing there to have marked.
 */
export type HilosTableSelectionHeader = 'none' | 'some' | 'all'

/**
 * The readable state of a table's marks — what a view draws the selection panel,
 * the header checkbox and the row checkboxes from, and what a bulk action reads
 * to learn what it runs over.
 *
 * `enabled` is not wrapped in a signal, for the reason the frame's `declaration`
 * is not: a page declares its bulk operations once and never changes them, and a
 * signal would only make the view subscribe to a constant.
 */
export interface HilosTableSelectionState {
  /** Whether the table declared bulk operations at all — the one sign that it has marks. */
  readonly enabled: boolean
  /** What a bulk action would run over, or null while nothing is chosen. */
  readonly target: ReadonlySignal<HilosTableSelectionTarget | null>
  /**
   * How many rows are marked ON THIS PAGE — the number the panel says.
   *
   * It stays a count of the page even where the choice is the condition: the size
   * of a large set is a ceiling rather than a number (`totalExact`), so "12 480
   * marked" would be a lie, while "everything on this page" is the truth about
   * what is on screen.
   */
  readonly count: ReadonlySignal<number>
  /** What the header checkbox shows. */
  readonly header: ReadonlySignal<HilosTableSelectionHeader>
}
