import { HilosModal } from './HilosModal.js'
const ROW_CLASS = 'alert d-flex'
export function HilosTableLive({ top, announcement }) {
  return (
    <div
      className={`mb-2 position-relative${null !== top ? '' : ' rounded bg-body-tertiary'}`}
      data-id="hilos-table-live-slot"
    >
      <div
        className={`${ROW_CLASS} invisible alert-secondary`}
        aria-hidden="true"
        data-id="hilos-table-live-idle"
      >
        <i className="bi bi-info-circle" />
        <span className="btn btn-outline-secondary">&nbsp;</span>
      </div>
      {top === null ? null : (
        <div className="border spinner-border">
          <button>Details</button>
        </div>
      )}
      <HilosModal actions={() => <button className="border">Close</button>} />
      <span className="visually-hidden" role="status">
        {announcement}
      </span>
    </div>
  )
}
