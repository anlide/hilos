// HilosTableLive — the one room above a table for everything live it has to say:
// changes waiting for Apply, rows created above the window, a source that stopped
// being kept up to date, and work running on the set. The room is exactly one line
// tall at every table and never changes height (styling-rules.md, "The room a live
// message takes"): an invisible twin of the very same row stands in the flow at all
// times and holds it, and the message is laid over that twin — over its OWN
// reserve, the way LoadingButton lays its spinner over its own text, and never over
// a row of the table. The twin stays in the flow rather than taking turns with the
// message, because the four rows are not one height: the freshness row has no
// button, and a room swapping to it would sit down.
// When several are live, the core decides which holds the line (tableLive.ts) and
// the others stand beside its text as their icons alone; a fifth kind of message
// arrives into this same room rather than a room of its own.
// What a screen reader hears is one hidden region that stands before there is
// anything to say, never the row itself: the track of running work lives in the row
// and moves every second, and a region on the row would read it out each time.
// Internal to the React view layer on purpose: it is not exported from index.ts,
// for the reason the bar and the footer are not — outside a table it means nothing.
// The React port of the Vue reference (vue/src/HilosTableLive.vue), under the same
// names and words.
import {
  hilosTableStaleColumns,
  hilosTableStaleLabel,
  hilosTableStaleSources,
} from '@hilos/core'
import type {
  HilosTableColumn,
  HilosTableLiveKind,
  TableViewportController,
} from '@hilos/core'

import { HilosTableProgress } from './HilosTableProgress.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosTableLive}. */
export interface HilosTableLiveProps<R> {
  /** The headless server-windowed controller the room reads and drives. */
  controller: TableViewportController<R>
  /**
   * The columns the table is drawn from — read to name the columns built from a
   * source that went quiet, so the room cannot name a column the table does not have.
   */
  columns: readonly HilosTableColumn[]
}

/**
 * The message row and its idle twin, to the character — only `invisible` on the
 * twin and the color and the placement on the message differ. The room equals the
 * true height of the row exactly while the markup matches. The row never wraps: the
 * text truncates and the icons and the button keep their size, so a narrow screen
 * cannot fold the button onto a second line.
 */
const ROW_CLASS =
  'alert py-2 px-3 mb-0 d-flex flex-nowrap align-items-center gap-2'

/** The color each kind of message is drawn in. */
const VARIANT: Record<HilosTableLiveKind, string> = {
  pending: 'alert-warning',
  announce: 'alert-secondary',
  stale: 'alert-info',
  progress: 'alert-secondary',
}

/** The icon each kind is drawn with — on the line when it holds it, and beside it when not. */
const ICON: Record<HilosTableLiveKind, string> = {
  pending: 'bi-pause-circle',
  announce: 'bi-arrow-down-circle',
  stale: 'bi-snow',
  progress: 'bi-arrow-repeat',
}

/** The data-id of the row while that kind holds it — the handles e2e reads each message by. */
const ROW_ID: Record<HilosTableLiveKind, string> = {
  pending: 'hilos-table-pending-row',
  announce: 'hilos-table-announce',
  stale: 'hilos-table-stale',
  progress: 'hilos-table-progress',
}

/** How each kind is named when it is listed after the one holding the line. */
const REST_WORDS: Record<HilosTableLiveKind, string> = {
  pending: 'pending changes',
  announce: 'new rows above the window',
  stale: 'a source is behind',
  progress: 'work running',
}

/**
 * The room of live messages above one table.
 *
 * @param props The controller the room reads and drives, and the columns the table is drawn from.
 */
export function HilosTableLive<R>({
  controller,
  columns,
}: HilosTableLiveProps<R>) {
  const live = useSignal(controller.live)
  const rows = useSignal(controller.rows)
  const pendingCount = useSignal(controller.pendingCount)
  const announced = useSignal(controller.announced)
  const tableProgress = useSignal(controller.progress.table)

  // The sentence about the columns that went quiet, read out of the very list the
  // table is drawn from.
  const sources = hilosTableStaleSources(rows)
  const staleLabel = hilosTableStaleLabel(
    hilosTableStaleColumns(columns, sources),
    sources.size > 0,
  )

  // The numeral of each message is chosen here rather than in the markup: '1 rows'
  // would stand in the most visible place of the screen.
  const announceLabel =
    announced.above === 1
      ? '1 new row above the window'
      : `${announced.above} new rows above the window`

  // Only the tail of the waiting sentence is composed here, because the count itself
  // stays a node of its own under `hilos-table-pending` — the handle the outside reads
  // the number by.
  const pendingSuffix =
    pendingCount === 1 ? 'row will move or leave' : 'rows will move or leave'

  const top = live.top

  // What the hidden region says: the sentence of the message on the line, then the
  // others by name. The track of running work is not in it — it moves every second.
  const sentences: Record<HilosTableLiveKind, string> = {
    pending: `${pendingCount} ${pendingSuffix}.`,
    announce: `${announceLabel}.`,
    stale: staleLabel ?? '',
    progress: 'Work is running on this table.',
  }
  const restWords = live.rest.map((kind) => REST_WORDS[kind])
  const announcement =
    top === null
      ? ''
      : restWords.length === 0
        ? sentences[top]
        : `${sentences[top]} Also: ${restWords.join(', ')}.`

  return (
    <div className="position-relative mb-2" data-id="hilos-table-live-slot">
      <div
        className={`${ROW_CLASS} alert-secondary invisible`}
        aria-hidden="true"
        data-id="hilos-table-live-idle"
      >
        <i className="bi bi-info-circle flex-shrink-0" />
        <span className="small flex-grow-1 text-truncate">&nbsp;</span>
        {/* A span, not a button: the twin holds room, it does not take focus. */}
        <span className="btn btn-sm btn-outline-secondary flex-shrink-0">
          &nbsp;
        </span>
      </div>

      {top !== null ? (
        <div
          className={`${ROW_CLASS} ${VARIANT[top]} position-absolute top-0 start-0 w-100`}
          data-id={ROW_ID[top]}
        >
          <i className={`bi flex-shrink-0 ${ICON[top]}`} aria-hidden="true" />
          <span className="small flex-grow-1 text-truncate">
            {top === 'pending' ? (
              <>
                <span data-id="hilos-table-pending">{pendingCount}</span>{' '}
                {pendingSuffix}
              </>
            ) : null}
            {top === 'announce' ? announceLabel : null}
            {top === 'stale' ? staleLabel : null}
          </span>
          {live.rest.length > 0 ? (
            <span
              className="d-flex gap-1 flex-shrink-0"
              data-id="hilos-table-live-rest"
            >
              {live.rest.map((kind) => (
                <i
                  key={kind}
                  className={`bi flex-shrink-0 ${ICON[kind]}`}
                  aria-hidden="true"
                />
              ))}
            </span>
          ) : null}
          {top === 'pending' ? (
            <button
              type="button"
              className="btn btn-sm btn-warning flex-shrink-0"
              data-id="hilos-table-apply"
              onClick={() => controller.apply()}
            >
              Apply
            </button>
          ) : null}
          {top === 'announce' ? (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary flex-shrink-0"
              data-id="hilos-table-announce-show"
              onClick={() => controller.show()}
            >
              Show
            </button>
          ) : null}
          {/* The track runs along the bottom edge of the line and adds no height;
              the line is positioned itself, so it is the box the track sits in. */}
          {top === 'progress' && tableProgress ? (
            <HilosTableProgress
              className="position-absolute bottom-0 start-0 w-100 rounded-0"
              progress={tableProgress}
              label="Work on this table"
            />
          ) : null}
        </div>
      ) : null}

      <span
        className="visually-hidden"
        role="status"
        data-id="hilos-table-live-status"
      >
        {announcement}
      </span>
    </div>
  )
}
