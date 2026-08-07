// HilosMaintenance — the maintenance surface of the app shell. HilosLayout
// renders it over the routed content for as long as the connection reports
// protected mode, on every url, so a visitor who arrives mid-maintenance sees
// planned work rather than a generic outage. The words come from the backend
// registry and travel the wire (the Hilos i18n model); this component holds
// layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
// known but no sentence arrived with it. It is a state, not a page: no links,
// no retry button — the mode lifts on its own and the core reloads the document.
import { PROTECTED_MODE_FALLBACK_COPY } from '@hilos/core'
import type { ProtectedModeStatus } from '@hilos/core'

/** Props for {@link HilosMaintenance}. */
export interface HilosMaintenanceProps {
  /** The freeze to render, as the connection reports it. */
  status: ProtectedModeStatus
}

/**
 * The full-screen maintenance state of the shell.
 *
 * @param props The protected-mode state carrying the backend copy.
 */
export function HilosMaintenance({ status }: HilosMaintenanceProps) {
  const title = status.title ?? PROTECTED_MODE_FALLBACK_COPY.title
  const message = status.message ?? PROTECTED_MODE_FALLBACK_COPY.message

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
    </div>
  )
}
