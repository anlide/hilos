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
// Bootstrap's CSS, not its JS; HilosModal does the same). The card keeps the
// stock surface — the width, the translucent body background, the border, the
// shadow and the z-index that puts the stack over a modal — and names its
// severity with a colored rail and an icon instead of a solid `text-bg-*` fill:
// a fill carries neither a readable link nor a long line, and in the dark theme
// a thin border in its place all but disappears (mockups/components/toast).
// Bootstrap classes only; what stock utilities cannot express lives in
// hilos-styles.scss (styling-rules.md).
import { useEffect, useRef, useState } from 'react'
import type { FocusEvent, MouseEvent } from 'react'
import { hilosToasts } from '@hilos/core'
import type {
  HilosToast,
  HilosToastHoldReason,
  HilosToastSeverity,
  HilosToastStore,
  HilosToastViewer,
} from '@hilos/core'

import { HilosLink } from './HilosLink.js'
import type { HilosToastCorner } from './hilosToastCorner.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosToastHost}. */
export interface HilosToastHostProps {
  /** The stack to render; defaults to the application-wide store. */
  store?: HilosToastStore
  /** Which corner the stack sits in; defaults to the bottom end. */
  corner?: HilosToastCorner
}

// What names a severity on the light card: the color of the rail down its left
// edge, the color of its icon, and the icon itself. One table of the three
// rather than three lookups or class strings glued together in the markup —
// glued classes read badly and cannot be grepped (CONN_VISUAL in HilosLayout is
// the same shape).
type ToastVisual = { rail: string; accent: string; icon: string }
const TOAST_VISUAL: Record<HilosToastSeverity, ToastVisual> = {
  error: {
    rail: 'border-danger',
    accent: 'text-danger',
    icon: 'bi-x-circle-fill',
  },
  success: {
    rail: 'border-success',
    accent: 'text-success',
    icon: 'bi-check-circle-fill',
  },
  warning: {
    rail: 'border-warning',
    accent: 'text-warning',
    icon: 'bi-exclamation-triangle-fill',
  },
  info: {
    rail: 'border-primary',
    accent: 'text-primary',
    icon: 'bi-info-circle-fill',
  },
}

/**
 * Which of the three holds on the countdown this host owns is changing.
 *
 * The store's own name for it since HIL-768: it freezes on all three alike but
 * reports only the two a person is behind, so the kind has to travel with the
 * hold rather than being flattened into a count here.
 */
type HoldKind = HilosToastHoldReason

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
 * The line a screen reader reads out for one notice.
 *
 * A background notice names its sender: the listener would otherwise get news
 * with no idea who sent it, while the reader of the card sees the signature.
 *
 * @param toast The notice being announced.
 */
function spoken(toast: HilosToast): string {
  return toast.source === null
    ? toast.message
    : `${toast.source}: ${toast.message}`
}

/**
 * The application's transient notice stack.
 *
 * @param props The store to render (defaults to the shared one) and the corner
 *   it sits in.
 */
export function HilosToastHost({ store, corner }: HilosToastHostProps) {
  const target = store ?? hilosToasts
  const toasts = useSignal(target.toasts)
  const viewer = useRef<HilosToastViewer | null>(null)
  const cards = useRef(new Map<number, HTMLDivElement>())
  const stack = useRef<HTMLDivElement | null>(null)
  // The cursor, the keyboard focus and a tab nobody is looking at are three
  // independent holds on the countdown: the host only reports them, the store
  // counts them. The ref is the source of truth for the "at most one hold of
  // each kind" rule — a toast that closes under the pointer takes the release
  // event with it (Chrome and WebKit fire no mouseleave and no focusout for an
  // element that leaves the DOM, and they never make it up afterwards), so both
  // holds are given back by hand in dismiss and the cursor's one is re-taken
  // from onMouseOver rather than onMouseEnter — mouseover also fires for the
  // toast that slides under a cursor standing still, which is exactly what
  // happens to the notice below the one just closed. The state next to it is
  // what the life bar draws its freeze from.
  const held = useRef({ cursor: false, focus: false, tab: false })
  const [frozen, setFrozen] = useState(false)

  /**
   * Take one of the three holds, or give it back.
   *
   * Both records are written here, in the one place that also tells the store,
   * so the bar cannot drift apart from what the store was told. The ref carries
   * the rule because handlers read it synchronously while state in a closure
   * arrives stale, and the store would get a second hold of the same kind; a
   * side effect inside a useState updater is out for the other reason — under
   * StrictMode it is called twice and the hold would reach the store twice.
   *
   * @param kind Which hold is changing.
   * @param taken Whether the host is holding it from now on.
   */
  const setHold = (kind: HoldKind, taken: boolean): void => {
    const attached = viewer.current
    if (held.current[kind] === taken || (taken && attached === null)) {
      return
    }
    held.current[kind] = taken
    const { cursor, focus, tab } = held.current
    setFrozen(cursor || focus || tab)
    if (taken) {
      attached?.hold(kind)
    } else {
      attached?.release(kind)
    }
  }

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
    // away and everything is still there when you come back. It goes through
    // the same writer as the other two, because the life bar owes its freeze to
    // all three.
    const onVisibility = (): void => {
      setHold('tab', document.hidden)
    }
    onVisibility()
    window.addEventListener('resize', onResize)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.removeEventListener('resize', onResize)
      document.removeEventListener('visibilitychange', onVisibility)
      // detach gives back whatever holds this host was still holding, so the
      // bookkeeping of the three it owns has to start clean against the next one.
      attached.detach()
      viewer.current = null
      held.current.cursor = false
      held.current.focus = false
      held.current.tab = false
      setFrozen(false)
    }
  }, [target])

  // Measured after the browser has laid the cards out, and again after every
  // render: a card is only really on screen once the store knows how tall it is,
  // and every report after the first only updates the number. The cursor is
  // re-checked in the same place, and one way only: it takes the hold if the
  // stack turns out to be under the pointer and never gives it back. Chromium
  // does not re-check what is under the cursor when an element is ADDED, so a
  // stack that grew under a still pointer would otherwise never learn of it;
  // removal both engines re-check honestly, and releasing here instead would
  // need an environment that answers `:hover`, which jsdom is not. The first
  // call of this effect is the mount, so there is no separate branch for it.
  useEffect(() => {
    const attached = viewer.current
    if (attached === null) {
      return
    }
    for (const [id, element] of cards.current) {
      attached.reportHeight(id, occupiedHeight(element))
    }
    if (stack.current !== null && stack.current.matches(':hover')) {
      setHold('cursor', true)
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

  const holdOnFocus = (event: FocusEvent<HTMLDivElement>): void => {
    if (!movesWithin(event)) {
      setHold('focus', true)
    }
  }
  const releaseOnBlur = (event: FocusEvent<HTMLDivElement>): void => {
    if (!movesWithin(event)) {
      setHold('focus', false)
    }
  }
  const dismiss = (id: number): void => {
    setHold('cursor', false)
    setHold('focus', false)
    target.dismiss(id)
  }

  /**
   * Close the card whose link just took the reader somewhere else.
   *
   * Which clicks those are is not decided here a second time: HilosLink swallows
   * the event exactly when it navigated in place, while a modified click, a
   * non-primary button and a missing router leave it alone — and in those the
   * reader never left this page, so the notice stays. The handler sits on the
   * card's positioned box rather than on the link itself, because HilosLink
   * calls a handler given to it as a prop BEFORE its own preventDefault: such a
   * handler would see defaultPrevented false every time and the card would never
   * close. Bubbling puts it right — React hands one synthetic event up the tree,
   * and the parent gets it with the flag already set. The close button bubbles
   * here too and is harmless: it swallows nothing.
   *
   * @param event The click that reached the card.
   * @param id The toast's id.
   */
  const dismissIfNavigated = (
    event: MouseEvent<HTMLDivElement>,
    id: number,
  ): void => {
    if (event.defaultPrevented) {
      dismiss(id)
    }
  }

  // Where the stack sits: the horizontal edge is a stock Bootstrap utility, the
  // vertical one is not, because the narrow screen overrides it and the stock
  // .bottom-0 carries !important that a media query cannot outrank.
  const chosen = corner ?? 'bottom-end'
  const cornerClasses = [
    chosen.endsWith('-end') ? 'end-0' : 'start-0',
    chosen.startsWith('bottom-')
      ? 'hilos-toast-stack-bottom'
      : 'hilos-toast-stack-top',
  ].join(' ')

  // What the live regions say. A notice reaches them only once it is measured:
  // until then the store may still take it into the queue or into the missed
  // count, and announcing a card that will not appear promises something that
  // can be neither read nor dismissed. A repeat does not change the text, so
  // twenty identical failures are read once — the merge, carried over to
  // hearing. Plain filtering, no useMemo: the list is tiny and memoizing it
  // would buy nothing.
  const announced = toasts.filter((toast) => toast.measured)

  return (
    <>
      {/* The two live regions, declared in advance and on the stack rather than
      on the card that just appeared: a role attached to a freshly inserted node
      leaves part of the screen readers silent. Two of them, because only a
      failure is allowed to interrupt what the listener is hearing. They sit
      OUTSIDE the stack container: inside, they would double every visible line
      for a search by text, which is how the demo suites find a notice. */}
      <div
        className="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="hilos-toast-live-assertive"
      >
        {announced
          .filter((toast) => toast.severity === 'error')
          .map((toast) => (
            <div key={toast.id}>{spoken(toast)}</div>
          ))}
      </div>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="hilos-toast-live-polite"
      >
        {announced
          .filter((toast) => toast.severity !== 'error')
          .map((toast) => (
            <div key={toast.id}>{spoken(toast)}</div>
          ))}
      </div>
      <div
        ref={stack}
        className={`toast-container position-fixed p-3 ${cornerClasses}`}
        data-id="hilos-toasts"
        onMouseOver={() => setHold('cursor', true)}
        onMouseLeave={() => setHold('cursor', false)}
        onFocus={holdOnFocus}
        onBlur={releaseOnBlur}
      >
        {toasts.map((toast) => (
          <div
            key={toast.id}
            ref={keep(toast.id)}
            className="toast fade show overflow-hidden"
            data-id={`hilos-toast-${toast.severity}`}
          >
            {/* The card's one positioned box: the rail, the icon and the text
            sit in it, and it is what the message's stretched link covers, so the
            whole card leads where the notice points while the close button stays
            clickable over it (.z-2). */}
            <div
              className={`border-start border-4 d-flex align-items-start gap-2 p-2 position-relative ${TOAST_VISUAL[toast.severity].rail}`}
              onClick={(event) => dismissIfNavigated(event, toast.id)}
            >
              <i
                className={`bi ms-1 ${TOAST_VISUAL[toast.severity].icon} ${TOAST_VISUAL[toast.severity].accent}`}
                aria-hidden="true"
              ></i>
              <div className="flex-grow-1 min-w-0">
                {toast.source !== null && (
                  <div
                    className="hilos-toast-source text-body-secondary mb-1"
                    data-id="hilos-toast-source"
                  >
                    {toast.source}
                  </div>
                )}
                <div className="d-flex align-items-start gap-2">
                  <div className="hilos-toast-clamp flex-grow-1 min-w-0">
                    {toast.destination === null ? (
                      toast.message
                    ) : (
                      <HilosLink
                        to={toast.destination}
                        className="stretched-link link-body-emphasis text-decoration-none"
                      >
                        {toast.message}
                      </HilosLink>
                    )}
                  </div>
                  {toast.repeats > 1 && (
                    <span
                      className="badge text-bg-light border"
                      data-id="hilos-toast-repeats"
                    >
                      ×{toast.repeats}
                    </span>
                  )}
                </div>
              </div>
              <button
                type="button"
                className="btn-close position-relative z-2"
                aria-label="Close"
                data-id="hilos-toast-close"
                onClick={() => dismiss(toast.id)}
              ></button>
            </div>
            {/* The life bar, and only where a countdown actually runs: an error
            never expires, and a card the host has not reported yet has no
            countdown started. It is keyed by the repeat count because a merge
            gives the notice its full time back — without a fresh node the
            animation would finish the old round and show a time that is not the
            one running. */}
            {toast.measured && toast.severity !== 'error' && (
              <div
                key={toast.repeats}
                className={`hilos-toast-life ${TOAST_VISUAL[toast.severity].accent}${frozen ? ' hilos-toast-life-paused' : ''}`}
                data-id="hilos-toast-life"
              ></div>
            )}
          </div>
        ))}
      </div>
    </>
  )
}
