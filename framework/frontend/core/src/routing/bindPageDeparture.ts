// Preserve the page on screen at the instant of navigation until its answer,
// refusal, or skeleton replaces it. This binding belongs to core so every view
// layer reacts to the same router and signal moments.

import { copyPageInto } from '../dom/pageCopy.js'
import {
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import { type HilosRouter } from './HilosRouter.js'

/** The page slot and inert departure container owned by one routed view. */
export interface PageDepartureBinding {
  router: HilosRouter
  slot: Element
  departure: Element
  skeletonShown: ReadonlySignal<boolean>
}

/**
 * Keep a still copy in the empty frame between routed pages (HIL-1146).
 *
 * Navigation's onLeave hook is necessary: pageLoading also rises when access
 * is re-evaluated without navigation, where a copy would expose a denied page.
 * Clearing the copy also resets the nearest scrolled ancestor; the old empty
 * frame did that naturally, while the copy prevents the browser's reset.
 *
 * @param binding The router, slot, container, and skeleton signal to connect.
 * @returns A cleanup function that removes listeners and the copy.
 */
export function bindPageDeparture(binding: PageDepartureBinding): Unsubscribe {
  const { router, slot, departure, skeletonShown } = binding

  const clear = (): void => {
    if (departure.childElementCount === 0) {
      return
    }
    departure.replaceChildren()
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
    if (router.pageLoading.get()) {
      return
    }
    copyPageInto(slot, departure)
  })
  const offLoading = subscribeSignal(router.pageLoading, (loading) => {
    if (!loading) {
      clear()
    }
  })
  const offError = subscribeSignal(router.pageError, (error) => {
    if (error !== null) {
      clear()
    }
  })
  const offSkeleton = subscribeSignal(skeletonShown, (shown) => {
    if (shown) {
      clear()
    }
  })

  return () => {
    offLeave()
    offLoading()
    offError()
    offSkeleton()
    departure.replaceChildren()
  }
}
