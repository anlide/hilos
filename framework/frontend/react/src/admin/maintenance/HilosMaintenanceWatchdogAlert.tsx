// HilosMaintenanceWatchdogAlert — the current server alarm of the maintenance
// watchdog, in one reserved line above the Overview's state card. React
// counterpart of the Vue component (HIL-1680). The slot is the one polite
// status region and stays mounted; while the verdict is clear an inert,
// aria-hidden twin of the very same markup holds the room, so an alarm moves
// nothing the reader is looking at (styling-rules.md, "The room a live message
// takes"). The row truncates its plain-text summary and keeps Details beside it
// at every verdict. Details is the ordinary modal reading the complete, live
// facts: it follows the verdict, a clear closes it, and the next alarm does not
// reopen it. Closing it sends nothing. The verdict, its tone, words and facts
// are the core projection; this view only draws them. Internal to the section,
// not exported. Bootstrap classes only.
import { Fragment, useEffect, useState } from 'react'
import {
  HILOS_MAINTENANCE_WATCHDOG_COPY,
  type HilosMaintenanceWatchdogAlert as WatchdogAlert,
} from '@hilos/core/admin/maintenance'

import { HilosModal } from '../../HilosModal.js'

/** Props for {@link HilosMaintenanceWatchdogAlert}. */
export interface HilosMaintenanceWatchdogAlertProps {
  /** The core projection of the current server verdict, or null while clear. */
  alert: WatchdogAlert | null
}

/** The row and its twin share every metric; the tone changes no height. */
const ROW_CLASS = 'alert small py-1 px-2 my-2 d-flex align-items-center gap-2'

/** The permanent reading control and its non-interactive twin. */
const DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

/**
 * Draw the server's current watchdog alarm in its reserved row, with the
 * details dialog behind the row's permanent button.
 *
 * @param props The current alarm, or null while the watchdog is clear.
 */
export function HilosMaintenanceWatchdogAlert({
  alert,
}: HilosMaintenanceWatchdogAlertProps) {
  const [detailOpen, setDetailOpen] = useState(false)

  // A clear takes the details with it, and the next alarm finds them closed.
  useEffect(() => {
    if (alert === null) {
      setDetailOpen(false)
    }
  }, [alert])

  return (
    <>
      <div
        data-id="hilos-maintenance-watchdog-slot"
        role="status"
        aria-live="polite"
      >
        {alert ? (
          <div
            className={`${ROW_CLASS} alert-${alert.tone}`}
            data-id="hilos-maintenance-watchdog-alert"
          >
            <i
              className="bi bi-exclamation-triangle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">
              <strong>{alert.title}</strong> — {alert.summary}
            </span>
            <button
              type="button"
              className={DETAILS_CLASS}
              aria-label="Show details"
              title="Show details"
              data-id="hilos-maintenance-watchdog-details"
              onClick={() => setDetailOpen(true)}
            >
              {HILOS_MAINTENANCE_WATCHDOG_COPY.details}
            </button>
          </div>
        ) : (
          <div
            className={`${ROW_CLASS} alert-warning invisible`}
            aria-hidden="true"
            inert
            data-id="hilos-maintenance-watchdog-idle"
          >
            <i
              className="bi bi-exclamation-triangle flex-shrink-0"
              aria-hidden="true"
            ></i>
            <span className="flex-grow-1 text-truncate">&nbsp;</span>
            {/* A span, not a button: the twin holds room, it does not take focus. */}
            <span className={DETAILS_CLASS}>
              {HILOS_MAINTENANCE_WATCHDOG_COPY.details}
            </span>
          </div>
        )}
      </div>

      <HilosModal
        open={detailOpen && alert !== null}
        title={HILOS_MAINTENANCE_WATCHDOG_COPY.details}
        initialFocus="dialog"
        onClose={() => setDetailOpen(false)}
        actions={({ requestClose }) => (
          <button
            type="button"
            className="btn btn-secondary"
            data-id="hilos-maintenance-watchdog-close"
            onClick={requestClose}
          >
            {HILOS_MAINTENANCE_WATCHDOG_COPY.close}
          </button>
        )}
      >
        {alert ? (
          <div data-id="hilos-maintenance-watchdog-full">
            <h3 className="h6 text-break">{alert.title}</h3>
            <p className="text-break">{alert.summary}</p>
            <dl className="row mb-0">
              {alert.facts.map((fact) => (
                <Fragment key={fact.label}>
                  <dt className="col-sm-4">{fact.label}</dt>
                  <dd className="col-sm-8 text-break">{fact.value}</dd>
                </Fragment>
              ))}
            </dl>
          </div>
        ) : null}
      </HilosModal>
    </>
  )
}
