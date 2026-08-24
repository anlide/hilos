// The client's half of "a rights change reaches the open tab" (HIL-621): the
// admin marker on the session changes while a page is open, and the page that is
// on screen is no longer the page this visitor should be looking at. The server
// re-decides every open subscription of that person and answers it, but its
// answer is a round trip away — and for a revoke that round trip is exactly the
// window in which privileged rows must not still be readable. So the client
// reacts AHEAD and the server's answer rules: a page_response for the current
// page clears whatever was drawn here (PageSubscription.ingestPageResponse).
//
// Signing out is the same window with a different true answer (HIL-652). The
// marker falls there too, but drawing a 403 would state "not for you" while what
// the server is already sending is the 401 invitation a guest gets — a verdict
// known to be wrong, drawn while the right one is in flight. So identity loss
// waits for the answer instead of guessing at it, and only the rows go.
//
// The client never rules on access. It reads two facts it was told — the admin
// marker and the person on the handshake response — and one thing it knows about
// itself: the surface type of the route it is standing on.

import { type HilosRouter } from '../routing/HilosRouter.js'
import {
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'

/** The HTTP status a refused page carries; the one this reaction draws and undoes. */
const FORBIDDEN = 403

/**
 * React to the session's admin marker or its person changing while a page is open.
 *
 * Losing the admin marker with the identity intact draws the 403 and drops the
 * page data. Gaining it while a 403 is displayed returns the page to its
 * just-navigated state. Losing the IDENTITY on an administrative route drops the
 * page data and waits for the answer without drawing anything. Every other
 * combination is silence — a visitor standing on /profile or on a project page
 * sees nothing at all when their flag flips, and is closed by the server's answer
 * alone.
 *
 * The surface test is the route's own `admin` marker (HIL-615), which states
 * surface TYPE rather than required rights, so it is deliberately not the only
 * defense: a page marked administrative but served at a softer level answers
 * with data, and that answer clears whatever this drew.
 *
 * @param router The navigator, for the current route and the two page controls.
 * @param isAdmin Whether the session holds the admin privilege; one trigger.
 * @param userId The person behind the session, or `null` for a guest; the other.
 * @returns Stops the reaction.
 */
export function bindAccessReaction(
  router: HilosRouter,
  isAdmin: ReadonlySignal<boolean>,
  userId: ReadonlySignal<number | null>,
): Unsubscribe {
  const stopAdmin = subscribeSignal(isAdmin, (admin) => {
    if (!admin) {
      // Signing out drops both, and both listeners run off the one write. The
      // 403 belongs to the visitor who is still here and may no longer look;
      // for the one who has gone, the identity listener below has the answer.
      if (userId.get() !== null && router.currentRoute.get().admin) {
        router.denyCurrentPage()
      }

      return
    }
    if (router.pageError.get()?.httpCode === FORBIDDEN) {
      router.awaitPageAnswer()
    }
  })
  const stopIdentity = subscribeSignal(userId, (id) => {
    if (id === null && router.currentRoute.get().admin) {
      router.awaitPageAnswer()
    }
  })

  return () => {
    stopAdmin()
    stopIdentity()
  }
}
