// HilosToastHost — the framework toast stack: transient notices in a fixed
// bottom-end corner, newest at the bottom, each self-expiring on the store's
// timer. It renders the shared core store (hilosToasts), so anything in the SDK
// or in a project can report an outcome without threading a store through props —
// the shell mounts this once (HilosLayout) and every page is covered.
//
// Markup is the Bootstrap toast component driven declaratively — `.toast.show`
// rendered by the store rather than Bootstrap's JS Toast (the SDK ships
// Bootstrap's CSS, not its JS; HilosModal does the same). Colored variants use
// the documented header-less form: a body plus a close button on a `text-bg-*`
// surface. Bootstrap classes only, no CSS of its own (styling-rules.md).
import { hilosToasts } from '@hilos/core'
import type { HilosToastSeverity, HilosToastStore } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosToastHost}. */
export interface HilosToastHostProps {
  /** The stack to render; defaults to the application-wide store. */
  store?: HilosToastStore
}

// Each severity maps to a Bootstrap color surface; the close button flips to its
// light variant on the dark surfaces so it stays visible.
const SURFACE: Record<HilosToastSeverity, string> = {
  error: 'text-bg-danger',
  success: 'text-bg-success',
  info: 'text-bg-secondary',
}

/**
 * The application's transient notice stack.
 *
 * @param props The store to render (defaults to the shared one).
 */
export function HilosToastHost({ store }: HilosToastHostProps) {
  const target = store ?? hilosToasts
  const toasts = useSignal(target.toasts)

  return (
    <div
      className="toast-container position-fixed bottom-0 end-0 p-3"
      data-id="hilos-toasts"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={`toast show align-items-center border-0 d-flex ${SURFACE[toast.severity]}`}
          role="alert"
          aria-live="assertive"
          aria-atomic="true"
          data-id={`hilos-toast-${toast.severity}`}
        >
          <div className="toast-body">{toast.message}</div>
          <button
            type="button"
            className="btn-close btn-close-white me-2 m-auto"
            aria-label="Close"
            data-id="hilos-toast-close"
            onClick={() => target.dismiss(toast.id)}
          ></button>
        </div>
      ))}
    </div>
  )
}
