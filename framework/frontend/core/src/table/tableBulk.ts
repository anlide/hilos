// What a bulk action ANSWERS WITH — the acceptance it gives at once and the
// report it ends with. Like tableProgress.ts and tableSelection.ts this file
// holds declaration types only, no logic: the report itself lives on the
// TableViewportController, because it arrives on its frames and is read off it
// the same way the bars and the marks are (table-subscription.md, "Selection and
// bulk actions").
//
// The outcome is NOT the action's reply. A run over a condition outlives the
// client's action timeout — every row goes to its owner and waits — so the reply
// answers acceptance, and the outcome arrives later on a frame of its own
// (wire-protocol.md, "When the work outlives the reply"). One `progressKey` ties
// the three together: the acceptance, the bar, and the report.

import { type ReadonlySignal } from '../state/signal.js'

/**
 * One row a bulk run reached and did not change, and why.
 *
 * The reason is text the server wrote, not a code the view translates: it is
 * composed where both facts are known — which row, and what stopped it — and a
 * view that recomposed it out of flags would be guessing at one of the two.
 */
export interface HilosTableBulkUntouched {
  /** The row that was left alone. */
  readonly rowKey: string
  /** Why it was left, in words meant for the person who asked. */
  readonly reason: string
}

/**
 * How one bulk run ended: how many rows it changed, and every row it did not,
 * by name.
 *
 * `touched` is a count and never a list. Rows that changed have already reached
 * the screen as ordinary live deltas, so naming them again would hand the reader
 * a second copy of what is already in front of them. The untouched are the
 * opposite — nothing else on the wire ever mentions them, and "39 of 40 deleted"
 * without their names is a message after which the reader has to go looking.
 *
 * `untouchedOmitted` is how many names did not fit under the server's ceiling. It
 * is 0 when every name fit, which is the ordinary case; a run that omitted names
 * says so rather than quietly showing a shorter list.
 */
export interface HilosTableBulkReport {
  /** Key of the run this is the outcome of — the one its acceptance and its bar carried. */
  readonly progressKey: string
  /** How many rows the run changed. */
  readonly touched: number
  /** The rows it reached and left alone, each with its reason. */
  readonly untouched: readonly HilosTableBulkUntouched[]
  /** How many untouched names did not fit under the server's ceiling; 0 when all of them did. */
  readonly untouchedOmitted: number
}

/**
 * What a bulk action answers with the moment it is taken: the run was accepted,
 * and here is what to watch for it under.
 *
 * `total` is how many rows the run will judge, and it is null when that number is
 * not honestly known — the size of a large set is a ceiling rather than a count,
 * and a bar with no estimate is a real state. "500 of 500+" would be the same lie
 * as "12 480 marked".
 */
export interface HilosTableBulkAccepted {
  /** Key naming the accepted run. */
  readonly progressKey: string
  /** Rows the run will judge, or null when the set has no honest count. */
  readonly total: number | null
}

/**
 * The run the reader started from this table, remembered so the live message can
 * name the operation while that run is live.
 */
export interface HilosTableBulkStarted {
  /** Key of the run the reader started from this table. */
  readonly progressKey: string
  /** Label of the declared operation that started it. */
  readonly label: string
}

/**
 * The readable state of a table's bulk work — what a view draws the outcome from.
 *
 * The report stands until the next run on this table replaces it or the reader
 * dismisses it, and changing the page, the filter or the order leaves it alone,
 * for the reason the bars are left alone: it is about the work, not about the
 * window.
 */
export interface HilosTableBulkState {
  /** The outcome of the last run on this table, or null while none has ended. */
  readonly report: ReadonlySignal<HilosTableBulkReport | null>
  /** The run the reader started from this table, or null while none is running. */
  readonly started: ReadonlySignal<HilosTableBulkStarted | null>
}
