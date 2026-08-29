// HilosToastHost — the framework toast stack: transient notices in a fixed
// bottom-end corner, newest at the bottom, each self-expiring on the store's
// timer. It renders the shared core store (hilosToasts), so anything in the SDK
// or in a project can report an outcome without threading a store through props —
// the shell mounts this once (HilosLayout) and every page is covered.
//
// The host owns no policy: it draws the cards, reports raw measurements (the
// window height and each card's occupied height) and reports the holds on the
// countdown. How long a notice lives, how much of the corner it may fill and
// what happens to the ones that do not fit is the store's business, in one copy
// for the three SDKs (core/src/state/toasts.ts).
//
// Markup is the Bootstrap toast component driven declaratively — `.toast.show`
// rendered by the store rather than Bootstrap's JS Toast (the SDK ships
// Bootstrap's CSS, not its JS; HilosModal does the same). Colored variants use
// the documented header-less form: a body plus a close button on a `text-bg-*`
// surface. Bootstrap classes only, no CSS of its own (styling-rules.md).
import { useEffect, useRef } from 'react'
import type { FocusEvent } from 'react'
import { hilosToasts } from '@hilos/core'
import type {
  HilosToastSeverity,
  HilosToastStore,
  HilosToastViewer,
} from '@hilos/core'

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
  warning: 'text-bg-warning',
  info: 'text-bg-secondary',
}

// Only a failure earns an interrupt; a success, a caveat or a plain notice waits
// its turn rather than cutting into what the screen-reader user is listening to.
const LIVE_REGION: Record<
  HilosToastSeverity,
  { role: string; ariaLive: 'assertive' | 'polite' }
> = {
  error: { role: 'alert', ariaLive: 'assertive' },
  success: { role: 'status', ariaLive: 'polite' },
  warning: { role: 'status', ariaLive: 'polite' },
  info: { role: 'status', ariaLive: 'polite' },
}

// A focus move inside the stack — tabbing from one close button to the next — is
// neither an arrival nor a leave, and relatedTarget is what tells those apart;
// without the check the holds would never balance out. React's onFocus/onBlur are
// the bubbling pair (focusin/focusout), which is what the container needs.
function movesWithin(event: FocusEvent<HTMLDivElement>): boolean {
  return event.currentTarget.contains(event.relatedTarget)
}

/**
 * How much of the stack one card takes: its own box plus the spacing under it.
 *
 * The store adds these up against a third of the window, so what it is given has
 * to be the room the card occupies, not the room it paints in.
 *
 * @param element The rendered card.
 */
function occupiedHeight(element: HTMLElement): number {
  const spacing = Number.parseFloat(getComputedStyle(element).marginBottom)

  return (
    element.getBoundingClientRect().height +
    (Number.isNaN(spacing) ? 0 : spacing)
  )
}

/**
 * The application's transient notice stack.
 *
 * @param props The store to render (defaults to the shared one).
 */
export function HilosToastHost({ store }: HilosToastHostProps) {
  const target = store ?? hilosToasts
  const toasts = useSignal(target.toasts)
  const viewer = useRef<HilosToastViewer | null>(null)
  const cards = useRef(new Map<number, HTMLDivElement>())
  // The cursor and keyboard focus are two independent holds on the countdown: the
  // host only reports them, the store counts them. It owns at most one hold of each
  // kind and knows whether it is holding it, because a toast that closes under the
  // pointer takes the release event with it: Chrome and WebKit fire no mouseleave
  // and no focusout for an element that leaves the DOM, and they never make it up
  // afterwards. So both holds are given back by hand in dismiss, and the cursor's
  // one is re-taken from onMouseOver rather than onMouseEnter — mouseover also
  // fires for the toast that slides under a cursor standing still, which is exactly
  // what happens to the notice below the one just closed.
  const cursorHeld = useRef(false)
  const focusHeld = useRef(false)

  // Attached for as long as this host is mounted. While no viewer is attached
  // the store's countdown does not run at all, which is what keeps a notice that
  // arrived before the first frame from burning down behind the splash screen.
  useEffect(() => {
    const attached = target.attach()
    viewer.current = attached
    attached.setViewportHeight(window.innerHeight)
    const onResize = (): void => {
      attached.setViewportHeight(window.innerHeight)
    }
    // A tab nobody is looking at is a hold like the cursor and the focus: walk
    // away and everything is still there when you come back. It is tracked
    // because visibilitychange fires on every switch and the host owes the store
    // exactly one hold of each kind.
    let tabHeld = false
    const onVisibility = (): void => {
      if (document.hidden === tabHeld) {
        return
      }
      tabHeld = document.hidden
      if (tabHeld) {
        attached.hold()
      } else {
        attached.release()
      }
    }
    onVisibility()
    window.addEventListener('resize', onResize)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.removeEventListener('resize', onResize)
      document.removeEventListener('visibilitychange', onVisibility)
      // detach gives back whatever holds this host was still holding, so the
      // bookkeeping of the two it owns has to start clean against the next one.
      attached.detach()
      viewer.current = null
      cursorHeld.current = false
      focusHeld.current = false
    }
  }, [target])

  // Measured after the browser has laid the cards out, and again after every
  // render: a card is only really on screen once the store knows how tall it is,
  // and every report after the first only updates the number.
  useEffect(() => {
    const attached = viewer.current
    if (attached === null) {
      return
    }
    for (const [id, element] of cards.current) {
      attached.reportHeight(id, occupiedHeight(element))
    }
  })

  const keep =
    (id: number) =>
    (element: HTMLDivElement | null): void => {
      if (element === null) {
        cards.current.delete(id)

        return
      }
      cards.current.set(id, element)
    }

  const holdOnCursor = (): void => {
    const attached = viewer.current
    if (attached !== null && !cursorHeld.current) {
      cursorHeld.current = true
      attached.hold()
    }
  }
  const releaseCursorHold = (): void => {
    if (cursorHeld.current) {
      cursorHeld.current = false
      viewer.current?.release()
    }
  }
  const releaseFocusHold = (): void => {
    if (focusHeld.current) {
      focusHeld.current = false
      viewer.current?.release()
    }
  }
  const holdOnFocus = (event: FocusEvent<HTMLDivElement>): void => {
    const attached = viewer.current
    if (attached !== null && !focusHeld.current && !movesWithin(event)) {
      focusHeld.current = true
      attached.hold()
    }
  }
  const releaseOnBlur = (event: FocusEvent<HTMLDivElement>): void => {
    if (!movesWithin(event)) {
      releaseFocusHold()
    }
  }
  const dismiss = (id: number): void => {
    releaseCursorHold()
    releaseFocusHold()
    target.dismiss(id)
  }

  return (
    <div
      className="toast-container position-fixed bottom-0 end-0 p-3"
      data-id="hilos-toasts"
      onMouseOver={holdOnCursor}
      onMouseLeave={releaseCursorHold}
      onFocus={holdOnFocus}
      onBlur={releaseOnBlur}
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          ref={keep(toast.id)}
          className={`toast show align-items-center border-0 d-flex ${SURFACE[toast.severity]}`}
          role={LIVE_REGION[toast.severity].role}
          aria-live={LIVE_REGION[toast.severity].ariaLive}
          aria-atomic="true"
          data-id={`hilos-toast-${toast.severity}`}
        >
          <div className="toast-body">{toast.message}</div>
          <button
            type="button"
            className="btn-close btn-close-white me-2 m-auto"
            aria-label="Close"
            data-id="hilos-toast-close"
            onClick={() => dismiss(toast.id)}
          ></button>
        </div>
      ))}
    </div>
  )
}
