// The live messages a table says above its rows, and which of them holds the room
// when several are live at once (docs/agents/frontend/styling-rules.md, "The room a
// live message takes"). Like tableFrame.ts and tableProgress.ts this file holds
// declarations and one pure function, no window logic: the seven facts it orders are
// read off the TableViewportController, which exposes the result as its `live` signal.
//
// The order is decided here rather than in each view for the reason the core computes
// a bar's fraction (tableProgress.ts): three views ranking the same six messages each
// in its own way are three different tables on one screen of one product. Seven and not
// six since HIL-1139: a table whose live changes stopped arriving says so here too.

/**
 * One kind of live message over a table: a bulk run report, bulk run progress,
 * changes waiting for Apply, rows created that the window cannot show, a table whose
 * live changes stopped arriving, a source that stopped being kept up to date, and work
 * running on the table as a whole.
 */
export type HilosTableLiveKind =
  | 'report'
  | 'bulk'
  | 'pending'
  | 'announce'
  | 'frozen'
  | 'stale'
  | 'progress'

/**
 * The kinds in order of precedence, the first holding the room.
 *
 * The reader's own work comes first — the report, then the run itself: the reader
 * has just initiated it and expects to watch it; hidden behind someone else's Apply
 * it would vanish from the screen entirely. The report ranks above the run because
 * the server sends the report before taking the progress bar down, so the report
 * replaces the run immediately. Next come the two with a button ('pending' and
 * 'announce') because a message hidden in the room takes its action with it: the
 * reader could neither apply the changes nor show the new rows. The ones without one
 * lose nothing by yielding — each keeps speaking by its icon on the same line. Of those,
 * the frozen table comes first because it speaks about the whole table: every row on the
 * screen may be out of date, where a stale source speaks about some columns and a bar
 * about work beside the rows.
 */
export const HILOS_TABLE_LIVE_ORDER: readonly HilosTableLiveKind[] = [
  'report',
  'bulk',
  'pending',
  'announce',
  'frozen',
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
 * A frozen table silences a stale source entirely, in the room and among the icons:
 * the stale phrase ends in "The other columns are live", and while the table is frozen
 * that is not true. The source's own marks in the header and the rows stay.
 *
 * @param live Whether each of the seven kinds has something to say.
 */
export function hilosTableLive(live: {
  readonly report: boolean
  readonly bulk: boolean
  readonly pending: boolean
  readonly announce: boolean
  readonly frozen: boolean
  readonly stale: boolean
  readonly progress: boolean
}): HilosTableLive {
  const kinds = HILOS_TABLE_LIVE_ORDER.filter(
    (kind) => live[kind] && !(kind === 'stale' && live.frozen),
  )

  return { top: kinds[0] ?? null, rest: kinds.slice(1) }
}
