// Preserve the page on screen at the instant of navigation until its answer,
// refusal, or skeleton replaces it, and dim it with Bootstrap classes while it
// waits. This binding belongs to core so every view layer reacts to the same
// router and signal moments.

import { copyPageInto } from '../dom/pageCopy.js'
import { bindReducedMotion } from '../dom/reducedMotion.js'
import {
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import { HilosPages } from './hilosPages.js'
import { type HilosRouter } from './HilosRouter.js'
import { type PageError } from './pageError.js'

// The three HilosView templates keep the container's permanent class (fade show
// and its placement); the binding toggles only these two and leaves the rest.
const HIDDEN_CLASS = 'd-none'
const DIMMED_CLASS = 'opacity-25'

/** The page slot and inert departure container owned by one routed view. */
export interface PageDepartureBinding {
  router: HilosRouter
  slot: Element
  departure: Element
  skeletonShown: ReadonlySignal<boolean>
  /** Composed code/data wait; defaults to the router's server-only wait. */
  loading?: ReadonlySignal<boolean>
  /** Composed server/import error; defaults to the router's error. */
  error?: ReadonlySignal<PageError | null>
  /** The shared live-loss latch clears and suppresses departure copies. */
  accessLost?: ReadonlySignal<boolean>
}

/**
 * Keep a still copy in the empty frame between routed pages (HIL-1146).
 *
 * Navigation's onLeave hook is necessary: pageLoading also rises when access
 * is re-evaluated without navigation, where a copy would expose a denied page.
 * Clearing the copy also resets the nearest scrolled ancestor; the old empty
 * frame did that naturally, while the copy prevents the browser's reset.
 *
 * The binding owns the copy's visibility and dimming through Bootstrap
 * classes: d-none while empty, and opacity-25 over the template's permanent
 * fade show while it waits, never for a reader requesting less movement. It
 * also clears the copy on entering PUBLIC_STATUS, whose reader has no server
 * wait to clear it. d-none leaves before copying because an undrawn container
 * drops the restored scrollTop; opacity-25 waits two frames because a
 * container leaving display:none has no previous style to transition from.
 *
 * @param binding The router, slot, container, and skeleton signal to connect.
 * @returns A cleanup function that removes listeners and the copy.
 */
export function bindPageDeparture(binding: PageDepartureBinding): Unsubscribe {
  const { router, slot, departure, skeletonShown } = binding
  const loading = binding.loading ?? router.pageLoading
  const error = binding.error ?? router.pageError
  let reduced = false
  const offMotion = bindReducedMotion((value) => {
    reduced = value
  })
  let frame = 0

  // Holds the outer and then the inner frame: the outer callback rewrites it.
  const cancelDim = (): void => {
    if (frame !== 0) {
      cancelAnimationFrame(frame)
      frame = 0
    }
  }

  const clear = (): void => {
    cancelDim()
    if (departure.childElementCount === 0) {
      return
    }
    departure.replaceChildren()
    departure.classList.add(HIDDEN_CLASS)
    departure.classList.remove(DIMMED_CLASS)
    let ancestor = departure.parentElement
    while (ancestor !== null) {
      if (ancestor.scrollTop > 0) {
        ancestor.scrollTop = 0
        break
      }
      ancestor = ancestor.parentElement
    }
  }

  const offLeave = router.onLeave(() => {
    if (binding.accessLost?.get() || loading.get()) {
      return
    }
    cancelDim()
    departure.classList.remove(HIDDEN_CLASS)
    copyPageInto(slot, departure)
    if (departure.childElementCount === 0) {
      departure.classList.add(HIDDEN_CLASS)
      return
    }
    if (reduced) {
      return
    }
    frame = requestAnimationFrame(() => {
      frame = requestAnimationFrame(() => {
        frame = 0
        if (!reduced && departure.childElementCount > 0) {
          departure.classList.add(DIMMED_CLASS)
        }
      })
    })
  })
  const offLoading = subscribeSignal(loading, (waiting) => {
    if (!waiting) {
      clear()
    }
  })
  const offRoute = subscribeSignal(router.currentRoute, (route) => {
    if (route.page === HilosPages.PUBLIC_STATUS) {
      clear()
    }
  })
  const offError = subscribeSignal(error, (failure) => {
    if (failure !== null) {
      clear()
    }
  })
  const offSkeleton = subscribeSignal(skeletonShown, (shown) => {
    if (shown) {
      clear()
    }
  })
  const offAccessLost = binding.accessLost
    ? subscribeSignal(binding.accessLost, (lost) => {
        if (lost) clear()
      })
    : () => {}
  if (binding.accessLost?.get()) clear()

  return () => {
    offLeave()
    offLoading()
    offRoute()
    offError()
    offSkeleton()
    offAccessLost()
    offMotion()
    cancelDim()
    departure.replaceChildren()
    departure.classList.add(HIDDEN_CLASS)
    departure.classList.remove(DIMMED_CLASS)
  }
}
