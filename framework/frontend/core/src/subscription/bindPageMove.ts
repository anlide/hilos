// The client's half of "the address moved" (HIL-2190): a page subscription is
// answered with page_moved instead of page_response when the record its
// address names is reached by another address now — the page was opened at an
// old one, or the record was renamed while the tab stood on it. The server
// names the target as a page key and route params; the navigator turns them
// into an address and rewrites the current one, adding no history entry, so
// going back does not land on the address the server has just said is gone.
//
// The same page is followed in place — the page subscription is repeated under
// the new params while the page on screen, its loading state and its tables'
// windows stay — because the server asked for it, as on page_resubscribe.
// Another page is an ordinary leave and subscribe, which rewriting the address
// to another page already does. A target with no address in this application
// — no route, an uncovered slot, a page its view layer has not built — is
// refused the way an unbuilt page is, and the address the tab stands on stays.

import { type HilosRouter } from '../routing/HilosRouter.js'
import { type Unsubscribe } from '../state/signal.js'
import { type PageSubscription } from './PageSubscription.js'

/**
 * Follow every accepted page_moved to the address it names, without a history
 * entry.
 *
 * @param pages The page subscription the move is reported to and followed by.
 * @param router The navigator that builds the target address and rewrites the
 *   current one.
 * @returns Stops following moves.
 */
export function bindPageMove(
  pages: PageSubscription,
  router: HilosRouter,
): Unsubscribe {
  return pages.onPageMoved((target) => {
    const path = router.resolvePath(target.page, target.params)
    if (path === undefined) {
      pages.refuseUnbuilt(target.page)
      return
    }
    const before = router.currentRoute.get().page
    // Rewriting to the same found page only publishes the new address; another
    // page leaves and subscribes on its own.
    router.replacePath(path)
    const after = router.currentRoute.get()
    if (after.page === before) {
      pages.followMove(after.params)
    }
  })
}
