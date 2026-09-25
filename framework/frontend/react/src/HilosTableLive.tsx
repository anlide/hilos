// HilosTableLive — the one room above a table for everything live it has to say:
// changes waiting for Apply, rows created that the window cannot show, a source that
// stopped being kept up to date, and work running on the set, plus bulk action
// progress and outcome report (Design D3). The room is exactly one line tall at every
// table and never changes height (styling-rules.md, "The room a live message takes"):
// an invisible twin of the very same row stands in the flow at all times and holds
// it, and the message is laid over that twin — over its OWN reserve, the way
// LoadingButton lays its spinner over its own text, and never over a row of the
// table. The twin stays in the flow rather than taking turns with the message under
// conditional rendering, because the rows are not one height: some rows have buttons,
// and a room swapping to a buttonless row would sit down.
// When several are live, the core decides which holds the line (tableLive.ts) and
// the others stand beside its text as their icons alone. Details of an untouched bulk
// report open in a dialog (HilosModal) mounted outside the live strip so that
// messages cycling underneath do not dismiss it.
// What a screen reader hears is one hidden region that stands before there is
// anything to say, never the row itself: the track of running work lives in the row
// and moves every second, and a region on the row would read it out each time.
// Internal to the React view layer on purpose: it is not exported from index.ts,
// for the reason the bar and the footer are not — outside a table it means nothing.
// The React port of the Vue reference (vue/src/HilosTableLive.vue), under the same
// names and words.
import { useState } from 'react'
import type { ReactNode } from 'react'
import {
  hilosTableStaleColumns,
  hilosTableStaleLabel,
  hilosTableStaleSources,
} from '@hilos/core'
import type {
  HilosTableBulkReport,
  HilosTableColumn,
  HilosTableLiveKind,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  TableViewportController,
} from '@hilos/core'

import { HilosModal } from './HilosModal.js'
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
  /** The project's own words about the work running on the table, on the line itself. */
  tableProgress?: (progress: TableProgressBar) => ReactNode
  /** The project's own control for that work, standing where a button stands. */
  tableProgressAction?: (progress: TableProgressBar) => ReactNode
  /**
   * The human name of one row a run left untouched; the key is printed where the
   * page fills nothing, because the row itself has left the window by then.
   */
  bulkUntouched?: (rowKey: string, reason: string) => ReactNode
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

/** The color each kind of message is drawn in (report is computed separately). */
const VARIANT: Record<Exclude<HilosTableLiveKind, 'report'>, string> = {
  bulk: 'alert-secondary',
  pending: 'alert-warning',
  announce: 'alert-secondary',
  stale: 'alert-info',
  progress: 'alert-secondary',
}

/** The icon each kind is drawn with — on the line when it holds it, and beside it when not. */
const ICON: Record<HilosTableLiveKind, string> = {
  report: 'bi-clipboard-check',
  bulk: 'bi-check2-square',
  pending: 'bi-pause-circle',
  announce: 'bi-arrow-down-circle',
  stale: 'bi-snow',
  progress: 'bi-arrow-repeat',
}

/** The data-id of the row while that kind holds it — the handles e2e reads each message by. */
const ROW_ID: Record<HilosTableLiveKind, string> = {
  report: 'hilos-table-bulk-report',
  bulk: 'hilos-table-progress-bulk',
  pending: 'hilos-table-pending-row',
  announce: 'hilos-table-announce',
  stale: 'hilos-table-stale',
  progress: 'hilos-table-progress',
}

/** How each kind is named when it is listed after the one holding the line. */
const REST_WORDS: Record<HilosTableLiveKind, string> = {
  report: 'a bulk report',
  bulk: 'work on the marked rows',
  pending: 'pending changes',
  announce: 'new rows',
  stale: 'a source is behind',
  progress: 'work running',
}

/** The accessible name of the track, and the caption of a bar we did not start. */
const BULK_BAR_NAME = 'Working on the marked rows'

/**
 * The room of live messages above one table.
 *
 * @param props The controller the room reads and drives, the columns the table is drawn from, and the project's places beside the track of running work.
 */
export function HilosTableLive<R>({
  controller,
  columns,
  tableProgress,
  tableProgressAction,
  bulkUntouched,
}: HilosTableLiveProps<R>) {
  const live = useSignal(controller.live)
  const rows = useSignal(controller.rows)
  const pendingCount = useSignal(controller.pendingCount)
  const announced = useSignal(controller.announced)
  const tableBar = useSignal(controller.progress.table)
  const bulkProgress = useSignal(controller.progress.bulk)
  const bulkStarted = useSignal(controller.bulk.started)
  const bulkReport = useSignal(controller.bulk.report)

  let bulkBarCaption = ''
  if (bulkProgress !== null) {
    if (
      bulkStarted === null ||
      bulkStarted.progressKey !== bulkProgress.progressKey
    ) {
      bulkBarCaption = BULK_BAR_NAME
    } else {
      bulkBarCaption =
        bulkProgress.total === null
          ? bulkStarted.label
          : `${bulkStarted.label}: ${bulkProgress.current} of ${bulkProgress.total}`
    }
  }

  const untouchedCount =
    bulkReport === null
      ? 0
      : bulkReport.untouched.length + bulkReport.untouchedOmitted

  let reportTitle = ''
  if (bulkReport !== null) {
    const changed = `Changed ${bulkReport.touched} ${bulkReport.touched === 1 ? 'row' : 'rows'}`
    reportTitle =
      untouchedCount === 0 ? changed : `${changed}, ${untouchedCount} untouched`
  }

  function variantFor(kind: HilosTableLiveKind): string {
    if (kind === 'report') {
      return untouchedCount > 0 ? 'alert-warning' : 'alert-success'
    }
    return VARIANT[kind]
  }

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
    announced.total === 1 ? '1 new row' : `${announced.total} new rows`

  // Only the tail of the waiting sentence is composed here, because the count itself
  // stays a node of its own under `hilos-table-pending` — the handle the outside reads
  // the number by.
  const pendingSuffix =
    pendingCount === 1 ? 'row will move or leave' : 'rows will move or leave'

  const top = live.top

  // What the hidden region says: the sentence of the message on the line, then the
  // others by name. The track of running work is not in it — it moves every second.
  const sentences: Record<HilosTableLiveKind, string> = {
    report: `${reportTitle}.`,
    bulk: `${bulkBarCaption}.`,
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

  const [detailsOpen, setDetailsOpen] = useState(false)
  const [detailsReport, setDetailsReport] =
    useState<HilosTableBulkReport | null>(null)

  function titleForReport(report: HilosTableBulkReport | null): string {
    if (report === null) {
      return ''
    }
    const count = report.untouched.length + report.untouchedOmitted
    const changed = `Changed ${report.touched} ${report.touched === 1 ? 'row' : 'rows'}`
    return count === 0 ? changed : `${changed}, ${count} untouched`
  }

  function openDetails(): void {
    if (bulkReport !== null) {
      setDetailsReport(bulkReport)
      setDetailsOpen(true)
    }
  }

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
          className={`${ROW_CLASS} ${variantFor(top)} position-absolute top-0 start-0 w-100`}
          data-id={ROW_ID[top]}
        >
          <i className={`bi flex-shrink-0 ${ICON[top]}`} aria-hidden="true" />
          <span className="small flex-grow-1 text-truncate">
            {top === 'report' ? reportTitle : null}
            {top === 'bulk' ? bulkBarCaption : null}
            {top === 'pending' ? (
              <>
                <span data-id="hilos-table-pending">{pendingCount}</span>{' '}
                {pendingSuffix}
              </>
            ) : null}
            {top === 'announce' ? announceLabel : null}
            {top === 'stale' ? staleLabel : null}
            {/* No check for whether the page filled the place: an empty one draws
                nothing, and the twin holds the height either way. */}
            {top === 'progress' && tableBar ? tableProgress?.(tableBar) : null}
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
          {top === 'report' && bulkReport !== null ? (
            <>
              {untouchedCount > 0 ? (
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary flex-shrink-0"
                  data-id="hilos-table-bulk-report-details"
                  onClick={openDetails}
                >
                  Details
                </button>
              ) : null}
              <button
                type="button"
                className="btn-close flex-shrink-0"
                aria-label="Dismiss"
                data-id="hilos-table-bulk-report-close"
                onClick={() =>
                  controller.dismissBulkReport(bulkReport.progressKey)
                }
              />
            </>
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
          {top === 'progress' && tableBar ? (
            <>
              {tableProgressAction?.(tableBar)}
              {/* The track runs along the bottom edge of the line and adds no
                  height; the line is positioned itself, so it is the box the track
                  sits in. */}
              <HilosTableProgress
                className="position-absolute bottom-0 start-0 w-100 rounded-0"
                progress={tableBar}
                label="Work on this table"
              />
            </>
          ) : null}
          {top === 'bulk' && bulkProgress !== null ? (
            <HilosTableProgress
              className="position-absolute bottom-0 start-0 w-100 rounded-0"
              progress={bulkProgress}
              label="Working on the marked rows"
            />
          ) : null}
        </div>
      ) : null}

      <HilosModal
        open={detailsOpen}
        title={titleForReport(detailsReport)}
        initialFocus="dialog"
        onClose={() => setDetailsOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            onClick={requestClose}
          >
            Close
          </button>
        )}
      >
        {detailsReport !== null ? (
          <ul
            className="list-unstyled mb-0"
            data-id="hilos-table-bulk-report-list"
          >
            {detailsReport.untouched.map((row) => (
              <li key={row.rowKey}>
                <strong>
                  {bulkUntouched?.(row.rowKey, row.reason) ?? row.rowKey}
                </strong>{' '}
                — {row.reason}
              </li>
            ))}
            {detailsReport.untouchedOmitted > 0 ? (
              <li>and {detailsReport.untouchedOmitted} more</li>
            ) : null}
          </ul>
        ) : null}
      </HilosModal>

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
