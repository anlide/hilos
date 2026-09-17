// The live messages a table says above its rows, and which of them holds the room
// when several are live at once (docs/agents/frontend/styling-rules.md, "The room a
// live message takes"). Like tableFrame.ts and tableProgress.ts this file holds
// declarations and one pure function, no window logic: the four facts it orders are
// read off the TableViewportController, which exposes the result as its `live` signal.
//
// The order is decided here rather than in each view for the reason the core computes
// a bar's fraction (tableProgress.ts): three views ranking the same four messages each
// in its own way are three different tables on one screen of one product.

/**
 * One kind of live message over a table: changes waiting for Apply, rows created
 * above the window, a source that stopped being kept up to date, and work running on
 * the table as a whole.
 */
export type HilosTableLiveKind = 'pending' | 'announce' | 'stale' | 'progress'

/**
 * The kinds in order of precedence, the first holding the room.
 *
 * The two with a button come first because a message hidden in the room takes its
 * action with it: the reader could neither apply the changes nor show the new rows.
 * The two without one lose nothing by yielding — each keeps speaking by its icon on
 * the same line. The first two also clear by the reader's own press, so the queue
 * drains by itself; the last two clear when the server says so.
 */
export const HILOS_TABLE_LIVE_ORDER: readonly HilosTableLiveKind[] = [
  'pending',
  'announce',
  'stale',
  'progress',
]

/** What the room above a table holds right now. */
export interface HilosTableLive {
  /** The kind that holds the line; null when there is nothing to say. */
  readonly top: HilosTableLiveKind | null
  /** The other live kinds, in precedence order; each is drawn as its icon only. */
  readonly rest: readonly HilosTableLiveKind[]
}

/**
 * Orders the live messages of a table: the first live kind by precedence holds the
 * room, the others follow it in the same order.
 *
 * @param live Whether each of the four kinds has something to say.
 */
export function hilosTableLive(live: {
  readonly pending: boolean
  readonly announce: boolean
  readonly stale: boolean
  readonly progress: boolean
}): HilosTableLive {
  const kinds = HILOS_TABLE_LIVE_ORDER.filter((kind) => live[kind])

  return { top: kinds[0] ?? null, rest: kinds.slice(1) }
}
