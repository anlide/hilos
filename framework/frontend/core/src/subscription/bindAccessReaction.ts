// The client's half of "a rights change reaches the open tab" (HIL-621): the
// admin marker on the session changes while a page is open, and the page that is
// on screen is no longer the page this visitor should be looking at. The server
// re-decides every open subscription of that person and answers it, but its
// answer is a round trip away — and for a revoke that round trip is exactly the
// window in which privileged rows must not still be readable. So the client
// reacts AHEAD and the server's answer rules: a page_response for the current
// page clears whatever was drawn here (PageSubscription.ingestPageResponse).
//
// The client never rules on access. It reads one fact it was told — the admin
// marker on the handshake response — and one thing it knows about itself: the
// surface type of the route it is standing on.

import { type HilosRouter } from '../routing/HilosRouter.js'
import {
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'

/** The HTTP status a refused page carries; the one this reaction draws and undoes. */
const FORBIDDEN = 403

/**
 * React to the session's admin marker changing while a page is open.
 *
 * Losing it on an administrative route draws the 403 and drops the page data.
 * Gaining it while a 403 is displayed returns the page to its just-navigated
 * state. Every other combination is silence — a visitor standing on /profile or
 * on a project page sees nothing at all when their flag flips.
 *
 * The surface test is the route's own `admin` marker (HIL-615), which states
 * surface TYPE rather than required rights, so it is deliberately not the only
 * defense: a page marked administrative but served at a softer level answers
 * with data, and that answer clears the error this drew.
 *
 * @param router The navigator, for the current route and the two page controls.
 * @param isAdmin Whether the session holds the admin privilege; the trigger.
 * @returns Stops the reaction.
 */
export function bindAccessReaction(
  router: HilosRouter,
  isAdmin: ReadonlySignal<boolean>,
): Unsubscribe {
  return subscribeSignal(isAdmin, (admin) => {
    if (!admin) {
      if (router.currentRoute.get().admin) {
        router.denyCurrentPage()
      }

      return
    }
    if (router.pageError.get()?.httpCode === FORBIDDEN) {
      router.awaitPageAnswer()
    }
  })
}
