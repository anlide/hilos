// HilosMaintenance — the maintenance surface of the app shell. HilosLayout
// renders it over the routed content for as long as the connection reports
// protected mode, on every url, so a visitor who arrives mid-maintenance sees
// planned work rather than a generic outage. The words come from the backend
// registry and travel the wire (the Hilos i18n model); this component holds
// layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
// known but no sentence arrived with it. It is a state, not a page: no links,
// no retry button — the mode lifts on its own and the core reloads the document.
// The one exception is the code field, shown only while the freeze says it
// accepts a pass AND the shell hands over an administrative surface AND at least
// one code has been minted: that phase is the verification window, and a verifier
// admitted by the code sees the whole product rather than this screen, while a
// visitor on a public url is not invited to fill in a key he was never given. The
// window opens before any code exists, and until one does the same spot carries a
// sentence saying so — the field would otherwise be a box that can take nothing.
// The rule lives here, in the component that owns the field, rather than in the
// shell. Submitting reconnects with the key on the socket url (the core does
// that), because a client refused every outbound frame can only ask to be let in
// on the 101.
//
// The second exception is the restore panel, and it appears for one visitor only:
// the admin whose own restore is what shuttered the node. Its frames are addressed
// to that browser's session (HIL-655), so every other tab receives none and keeps
// this screen exactly as it was. What it says is the phase, a bar with the share of
// the work behind it and an estimate of what is left, and finally the outcome — not
// the backup list, since under the freeze there is nobody left to serve a list. The
// bar is here because this screen is now the operator's only view of their own
// restore: the freeze holds every one of their tabs, the backups page included.
import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import {
  backupProgressPercent,
  createBackupProgressClock,
  createHilosRestoreProgress,
  formatBackupProgressLabel,
  formatRestoreOutcomeLine,
  formatRestorePhaseLine,
  PROTECTED_MODE_FALLBACK_COPY,
  PROTECTED_MODE_PASS_COPY,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosProgressAnchors,
  ProtectedModeStatus,
} from '@hilos/core'

import { useSignal } from './useSignal.js'

/**
 * The alert variant the restore panel wears: the colour follows the outcome, and
 * the sentence inside says the same thing in words — the colour is never the only
 * carrier (WCAG 1.4.1).
 *
 * @param outcome The terminal outcome of the run, or null while it is still going.
 */
function restoreOutcomeVariant(outcome: string | null): string {
  if (outcome === 'error') {
    return 'alert-danger'
  }

  return outcome === 'success' ? 'alert-success' : 'alert-info'
}

/**
 * The bar under the phase line: determinate once the run can be estimated, and the
 * indeterminate striped one until then, with a caption naming the phase, the
 * percentage and the time left.
 *
 * The same shape the backups page draws, under data ids of its own: two nodes
 * carrying one id on two surfaces would answer an e2e selector as a single node.
 *
 * @param anchors The progress anchors of the restore this browser asked for.
 * @param nowMs The current epoch milliseconds the percentage is measured against.
 */
function restoreProgressBar(anchors: HilosProgressAnchors, nowMs: number) {
  const percent = backupProgressPercent(anchors, nowMs)

  return (
    <>
      <div
        className="progress mt-2"
        role="progressbar"
        aria-label="Restore progress"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent ?? undefined}
        data-id="maintenance-restore-bar"
      >
        <div
          className={
            percent === null
              ? 'progress-bar progress-bar-striped progress-bar-animated'
              : 'progress-bar'
          }
          style={{ width: `${percent ?? 100}%` }}
        />
      </div>
      <div className="small" data-id="maintenance-restore-progress">
        {formatBackupProgressLabel(anchors, nowMs)}
      </div>
    </>
  )
}

/** Props for {@link HilosMaintenance}. */
export interface HilosMaintenanceProps {
  /** The freeze to render, as the connection reports it. */
  status: ProtectedModeStatus
  /** The connection a presented code is carried back in on. */
  connection: HilosConnection
  /**
   * Whether the url under the freeze names an administrative surface, as the
   * shell reads it off the current route. Required, so a shell cannot forget to
   * answer and silently hide the field from the verifier who needs it.
   */
  adminSurface: boolean
}

/**
 * The full-screen maintenance state of the shell.
 *
 * @param props The protected-mode state carrying the backend copy, the
 *   connection a verifier's code is presented through, and whether the current
 *   url is an administrative surface.
 */
export function HilosMaintenance({
  status,
  connection,
  adminSurface,
}: HilosMaintenanceProps) {
  const title = status.title ?? PROTECTED_MODE_FALLBACK_COPY.title
  const message = status.message ?? PROTECTED_MODE_FALLBACK_COPY.message
  const [code, setCode] = useState('')

  const restoreProgress = useMemo(
    () => createHilosRestoreProgress(connection),
    [connection],
  )
  const restoreStatus = useSignal(restoreProgress.status)
  // A percentage moves with wall time while the socket only speaks on a change of
  // phase, so the bar redraws from this ticker rather than from the frames.
  const progressClock = useMemo(() => createBackupProgressClock(), [])
  const progressNow = useSignal(progressClock.now)
  useEffect(() => {
    restoreProgress.start()

    return () => {
      restoreProgress.dispose()
      progressClock.dispose()
    }
  }, [restoreProgress, progressClock])

  // The typed code is deliberately kept after a submit: a rejection is most often
  // a typo, and clearing the field would make the visitor retype the whole key.
  function present(event: FormEvent): void {
    event.preventDefault()
    connection.presentProtectedModePass(code)
  }

  return (
    <div
      className="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center"
      data-id="maintenance"
      data-operation={status.operation}
      role="status"
      aria-live="polite"
    >
      <i
        className="bi bi-tools display-4 text-body-secondary mb-3"
        aria-hidden="true"
      />
      <h1 className="h3 mb-2" data-id="maintenance-title">
        {title}
      </h1>
      <p className="text-body-secondary mb-0" data-id="maintenance-message">
        {message}
      </p>
      {restoreStatus !== null && (
        <div
          className={`alert mt-4 mb-0 ${restoreOutcomeVariant(restoreStatus.outcome)}`}
          data-id="maintenance-restore"
        >
          <div className="fw-semibold" data-id="maintenance-restore-phase">
            {formatRestorePhaseLine(restoreStatus)}
          </div>
          {formatRestoreOutcomeLine(restoreStatus) !== '' && (
            <div className="small" data-id="maintenance-restore-outcome">
              {formatRestoreOutcomeLine(restoreStatus)}
            </div>
          )}
          {formatRestoreOutcomeLine(restoreStatus) === '' &&
            restoreProgressBar(restoreStatus, progressNow)}
        </div>
      )}
      {status.acceptsPass && adminSurface && !status.passIssued && (
        <p
          className="text-body-secondary small mt-4 mb-0"
          data-id="maintenance-pass-pending"
        >
          {PROTECTED_MODE_PASS_COPY.pending}
        </p>
      )}
      {status.acceptsPass && adminSurface && status.passIssued && (
        <form
          className="row justify-content-center w-100 mt-4 px-3"
          data-id="maintenance-pass-form"
          onSubmit={present}
        >
          <div className="col-12 col-sm-8 col-md-5">
            <label
              className="form-label small text-body-secondary"
              htmlFor="maintenance-pass"
            >
              {PROTECTED_MODE_PASS_COPY.prompt}
            </label>
            <div className="input-group">
              <input
                id="maintenance-pass"
                className={
                  status.passRejected
                    ? 'form-control is-invalid'
                    : 'form-control'
                }
                data-id="maintenance-pass"
                type="text"
                autoComplete="off"
                value={code}
                aria-invalid={status.passRejected}
                aria-describedby={
                  status.passRejected ? 'maintenance-pass-error' : undefined
                }
                onChange={(event) => setCode(event.target.value)}
              />
              <button
                className="btn btn-primary"
                data-id="maintenance-pass-submit"
                type="submit"
                disabled={code.trim() === ''}
              >
                {PROTECTED_MODE_PASS_COPY.submit}
              </button>
            </div>
            {status.passRejected && (
              <p
                id="maintenance-pass-error"
                className="text-danger small mt-2 mb-0"
                data-id="maintenance-pass-error"
              >
                {PROTECTED_MODE_PASS_COPY.rejected}
              </p>
            )}
          </div>
        </form>
      )}
    </div>
  )
}
