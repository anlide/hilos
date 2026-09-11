// The work a table has RUNNING — the bars a view draws and nothing else. Like
// tableFrame.ts and tableSelection.ts this file holds declaration types only, no
// logic: the bars themselves live on the TableViewportController, because they
// arrive on its frames and are read off it the same way the frame and the marks
// are (table-subscription.md, "Showing work in progress").
//
// A bar does NOT belong to the window. Paging, filtering and re-sorting leave it
// standing, unlike a mark, because the work goes on whichever page is being
// looked at. A row bar whose key is not in the current window is simply not
// drawn, and comes back with its row without the server sending anything again.

import { type ReadonlySignal } from '../state/signal.js'

/**
 * Where a bar is drawn, which is also the place it replaces a bar at: under its
 * own row, above the table, or inside the selection panel.
 *
 * The three are one frame with a tag rather than three frames, because every
 * rule about them is the same rule — one place holds one bar, the numbers are
 * the server's, and the end takes the bar down.
 */
export type HilosTableProgressScope = 'row' | 'table' | 'bulk'

/**
 * One bar as a view reads it: whose work it is, how far along, and the project's
 * own payload for whatever stands beside it.
 *
 * `fraction` is computed by the core and not by each view, for the reason the
 * core sums the announced rows: three views dividing the same two numbers each
 * in its own way are three different bars on one screen of one product. It is
 * `null` when the work named no total — an estimate is a thing work can honestly
 * not have, and a bar without one still says that something is running.
 */
export interface HilosTableProgress {
  /** Key of the work the bar is about; a different key means a different run. */
  readonly progressKey: string
  /** Units done so far, as the server counts them. */
  readonly current: number
  /** Units the work adds up to, or null when it named none. */
  readonly total: number | null
  /** How far along, 0..1, or null when there is no total to divide by. */
  readonly fraction: number | null
  /** The project's own payload for the content beside the bar; empty when it sent none. */
  readonly detail: Record<string, unknown>
}

/**
 * One bar as it arrives — live on `table_progress`, or with the first window in
 * the `progress` key of the page answer's `windows` section. The two roads carry
 * the same body, so they are taken in by one method and judged by one rule.
 *
 * `ended` is what takes the bar down, and it carries `progressKey` for a reason:
 * a late end of the previous run must not clear the bar of the run that has
 * already started.
 */
export interface HilosTableProgressFrame {
  /** Where the bar stands. */
  readonly scope: HilosTableProgressScope
  /** Key of the work the bar is about. */
  readonly progressKey: string
  /** Row the bar is tied to; present for a row bar and meaningless on the other two. */
  readonly rowKey?: string
  /** Units done so far. */
  readonly current: number
  /** Units the work adds up to; absent when it has no estimate. */
  readonly total?: number
  /** Whether the work has stopped and the bar comes down; absent reads as still running. */
  readonly ended?: boolean
  /** The project's own payload for the content beside the bar. */
  readonly detail?: Record<string, unknown>
}

/**
 * The readable state of a table's bars — what a view draws them from.
 *
 * Three places, three readers: the bar above the table, the bar in the selection
 * panel, and the row bars by row key. A row bar is read out of the map by key
 * rather than off the row's own projection, because putting it in the projection
 * would tie the bar back to the row's life — recomputed with it, cleared with
 * it, and swept up by everything else done "per row", which is the very tie this
 * channel exists to cut.
 */
export interface HilosTableProgressState {
  /** Work running on the table as a whole, or null when there is none. */
  readonly table: ReadonlySignal<HilosTableProgress | null>
  /** Work running for a bulk action over the marked rows, or null when there is none. */
  readonly bulk: ReadonlySignal<HilosTableProgress | null>
  /** Work running over single rows, by row key; keys absent from the map have no bar. */
  readonly rows: ReadonlySignal<ReadonlyMap<string, HilosTableProgress>>
}
