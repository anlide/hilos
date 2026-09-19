// HilosTableSelection — the panel that stands in place of the bar's controls
// while rows are marked: how many are marked, the way to take the whole filtered
// set, the operations the page declared, and the way to drop the marks. Under them
// stands the bar of the run those operations start and the report it ends with —
// the third place work is drawn in, and the one that belongs to the framework
// entirely (table-subscription.md, "Selection and bulk actions"). It holds NO rule
// about the marks: which rows they are, what the header checkbox shows and how a
// deleted row leaves the selection are all the controller's, and every control here
// is a call into it (multiframework-core.md). What it does hold is what only the
// view can know — which operation a confirmation is about, and which run the bar
// above the track is named after. Internal to the React view layer on purpose: it
// is not exported from index.ts, for the reason the bar and the footer are not.
// It is MOUNTED for the whole life of a table that declared bulk operations and only
// SHOWS itself while one of the three counts holds. Its confirmation is a HilosModal,
// and a dialog owns the page's scroll lock: it takes the lock when it opens, and a
// closed one releases it the moment it mounts, whosever lock that was. A panel that
// came and went with what the server sends — a report arriving, a window arriving with
// none of the marked rows left — would pass that lock around on nobody's behalf, and
// would take an open dialog off the screen with it. The React port of the Vue
// reference (vue/src/HilosTableSelection.vue), under the same names and words.
import { useState } from 'react'
import type { ReactNode } from 'react'
import type {
  HilosTableBulkAction,
  HilosTableBulkReport,
  TableViewportController,
} from '@hilos/core'

import { HilosActionError } from './HilosActionError.js'
import { HilosModal } from './HilosModal.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import { LoadingButton } from './LoadingButton.js'
import { useSignal } from './useSignal.js'
import { useTrackedAction } from './useTrackedAction.js'

/** Props for {@link HilosTableSelection}. */
export interface HilosTableSelectionProps<R> {
  /** The headless server-windowed controller the panel reads and drives. */
  controller: TableViewportController<R>
  /**
   * Whether the panel stands in place of the bar's controls. The bar works it out,
   * because the same answer decides which of the two strips it draws.
   */
  shown: boolean
  /**
   * The report to draw, or null when none stands. The bar above resolves what a
   * reader dismissed, because the same answer decides whether this panel is on
   * screen at all — one rule, read in one place.
   */
  report: HilosTableBulkReport | null
  /**
   * The reader dismissed the report of this run.
   *
   * @param progressKey Key of the run whose report was dismissed.
   */
  onDismiss: (progressKey: string) => void
  /**
   * The human name of one row a run left untouched; the key is printed where the
   * page gives nothing, because the row itself has left the window by then.
   */
  bulkUntouched?: (rowKey: string, reason: string) => ReactNode
}

/** The accessible name of the track, and the caption of a bar we did not start. */
const BAR_NAME = 'Working on the marked rows'

/**
 * The selection panel of a table that declared bulk operations.
 *
 * @param props The controller, whether the panel shows, the report to draw, and
 *   what a dismissal and an untouched row's name are handed to.
 */
export function HilosTableSelection<R>({
  controller,
  shown,
  report,
  onDismiss,
  bulkUntouched,
}: HilosTableSelectionProps<R>) {
  // The declaration does not change over the life of a table, so its operations are
  // read once rather than wrapped in a signal (tableFrame.ts, HilosTableFrameState).
  const bulkActions = controller.frame.declaration?.bulkActions ?? []

  const target = useSignal(controller.selection.target)
  const count = useSignal(controller.selection.count)
  const bulkProgress = useSignal(controller.progress.bulk)

  // The run this panel started, remembered as the pair the bar is named by. Naming
  // it from the declaration is honest only while the bar is the very run we asked
  // for: a bar under another key is somebody else's work, and this panel knows
  // nothing about what it is (Flow F9).
  const [started, setStarted] = useState<{
    progressKey: string
    label: string
  } | null>(null)

  // Which operation the open confirmation is about. The dialog draws its title and
  // its confirming button from it, so the two cannot say different things.
  const [confirmAction, setConfirmAction] =
    useState<HilosTableBulkAction | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const bulkTracked = useTrackedAction()

  // The choice by condition says words and not a number: the size of a large set is
  // a ceiling rather than a count, so "12 480 marked" would be a lie, while what is
  // on this page is the truth about what is on screen (Flow F3).
  const byFilter = target?.kind === 'filter'
  const countLabel = byFilter
    ? 'All rows matching the filter'
    : `${count} marked on this page`

  // The button the mockup pins to the right, away from the two that only narrow or
  // drop the choice: the destructive operation where the page declared one, and the
  // first operation otherwise — the shape of the row is the same either way.
  const pinnedKey = (
    bulkActions.find((action) => action.danger === true) ?? bulkActions[0]
  )?.key

  let barCaption = ''
  if (bulkProgress !== null) {
    barCaption =
      started === null || started.progressKey !== bulkProgress.progressKey
        ? BAR_NAME
        : bulkProgress.total === null
          ? started.label
          : `${started.label}: ${bulkProgress.current} of ${bulkProgress.total}`
  }

  // The untouched a report speaks of: the ones it named, and the ones that did not
  // fit under the server's ceiling. Both count towards the headline, because a run
  // that omitted names still left those rows alone (Flow F10).
  const untouchedCount =
    report === null ? 0 : report.untouched.length + report.untouchedOmitted

  let reportTitle = ''
  if (report !== null) {
    const changed = `Changed ${report.touched} rows`
    reportTitle =
      untouchedCount === 0 ? changed : `${changed}, ${untouchedCount} untouched`
  }

  const confirmBody = byFilter
    ? 'Every row matching the current filter will be affected, including rows that are not on this page'
    : `${count} ${count === 1 ? 'row' : 'rows'} on this page will be affected`

  function openConfirm(action: HilosTableBulkAction): void {
    bulkTracked.clearError()
    setConfirmAction(action)
    setConfirmOpen(true)
  }

  function closeConfirm(): void {
    setConfirmOpen(false)
  }

  // Authoritative-backend: the reply answers ACCEPTANCE and nothing more — the work
  // itself is watched through the bar and ends in the report — so a success closes
  // the dialog, while a refusal keeps it open with the sentence the page sent back.
  // The target is read HERE rather than when the button was pressed: between the two
  // the reader may have cleared a checkbox (Flow F7). It is read off the core rather
  // than off this render, because a render is a snapshot of an earlier moment.
  async function submitBulk(): Promise<void> {
    const action = confirmAction
    const runTarget = controller.selection.target.get()
    if (action === null || runTarget === null || bulkTracked.busy) {
      return
    }
    const handle = action.run(runTarget)
    if (!(await bulkTracked.run(handle))) {
      return
    }
    const accepted = (await handle.done).reply
    if (accepted !== undefined) {
      setStarted({ progressKey: accepted.progressKey, label: action.label })
    }
    closeConfirm()
  }

  return (
    <>
      {shown ? (
        <div
          className="d-flex flex-column gap-2 mb-2 p-2 rounded border bg-body-tertiary"
          role="group"
          aria-label="Selection"
          data-id="hilos-table-selection"
        >
          <div className="d-flex align-items-center flex-wrap gap-2">
            <span
              className="small fw-medium"
              data-id="hilos-table-selection-count"
            >
              {countLabel}
            </span>

            {/* Gone in the choice by condition: there it is already pressed, and
                a second press would change nothing (Flow F3). */}
            {!byFilter ? (
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                data-id="hilos-table-select-all-filtered"
                onClick={() => controller.selectAllByFilter()}
              >
                Select all matching the filter
              </button>
            ) : null}

            {/* While a run is going the server refuses a second one on this table,
                and a button whose only outcome is a refusal is not a button. It is
                a courtesy and not a guarantee: a refusal that arrives anyway is
                still shown (Flow F5). */}
            {bulkActions.map((action) => (
              <button
                key={action.key}
                type="button"
                className={[
                  'btn btn-sm',
                  action.danger === true
                    ? 'btn-outline-danger'
                    : 'btn-outline-secondary',
                  action.key === pinnedKey ? 'ms-auto' : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
                disabled={bulkProgress !== null}
                data-id={`hilos-table-bulk-${action.key}`}
                onClick={() => openConfirm(action)}
              >
                {action.label}
              </button>
            ))}

            <button
              type="button"
              className="btn btn-sm btn-link text-decoration-none"
              data-id="hilos-table-selection-clear"
              onClick={() => controller.clearSelection()}
            >
              Clear
            </button>
          </div>

          {/* The bar of the run: the framework gives the room, the track comes
              ready from the one component both other bars are drawn with, and the
              line above it names the operation while that is honest (Flow F9). */}
          {bulkProgress !== null ? (
            <div data-id="hilos-table-progress-bulk">
              <div className="small mb-1">{barCaption}</div>
              <HilosTableProgress progress={bulkProgress} label={BAR_NAME} />
            </div>
          ) : null}

          {/* The outcome, announced as it appears: it comes from the server rather
              than from a press, so waiting for the reader to find it with their
              eyes would be waiting for nothing (Flow F13). */}
          {report !== null ? (
            <div
              className={`alert py-2 px-3 small mb-0 ${
                untouchedCount > 0 ? 'alert-warning' : 'alert-success'
              }`}
              role="status"
              data-id="hilos-table-bulk-report"
            >
              <div className="d-flex align-items-start gap-2">
                <span className="fw-semibold flex-grow-1">{reportTitle}</span>
                <button
                  type="button"
                  className="btn-close"
                  aria-label="Dismiss"
                  data-id="hilos-table-bulk-report-close"
                  onClick={() => onDismiss(report.progressKey)}
                />
              </div>
              {untouchedCount > 0 ? (
                <ul className="list-unstyled mb-0 mt-1">
                  {report.untouched.map((row) => (
                    <li key={row.rowKey}>
                      <strong>
                        {bulkUntouched?.(row.rowKey, row.reason) ?? row.rowKey}
                      </strong>{' '}
                      — {row.reason}
                    </li>
                  ))}
                  {/* The names that did not fit under the server's ceiling, as a
                      number: a run that omitted names says so rather than quietly
                      showing a shorter list (Flow F11). */}
                  {report.untouchedOmitted > 0 ? (
                    <li>and {report.untouchedOmitted} more</li>
                  ) : null}
                </ul>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {/* Outside the div above on purpose: the dialog outlives the strip, so that a
          panel going away under the reader cannot take an open dialog — and the
          page's scroll lock — with it. */}
      <HilosModal
        open={confirmOpen}
        title={confirmAction?.label}
        initialFocus="dialog"
        onClose={closeConfirm}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={bulkTracked.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className={
                confirmAction?.danger === true ? 'btn-danger' : 'btn-primary'
              }
              loading={bulkTracked.loading}
              disabled={bulkTracked.busy}
              data-id="hilos-table-bulk-confirm"
              onClick={() => void submitBulk()}
            >
              {confirmAction?.label}
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError action={bulkTracked} />
        <p className="mb-0">{confirmBody}</p>
      </HilosModal>
    </>
  )
}
