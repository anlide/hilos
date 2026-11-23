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

import {
  type PageMoveRouter,
  replaceMovedPath,
} from '../routing/HilosRouter.js'
import { type Unsubscribe } from '../state/signal.js'
import {
  followCurrentPageMove,
  type PageSubscription,
} from './PageSubscription.js'

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
  router: PageMoveRouter,
): Unsubscribe {
  return pages[followCurrentPageMove]((target, isCurrent) => {
    if (!isCurrent()) return
    const path = router.resolvePath(target.page, target.params)
    if (!isCurrent()) return
    if (path === undefined) {
      pages.refuseUnbuilt(target.page)
      return
    }
    const inPlace = router[replaceMovedPath]?.(path, isCurrent)
    if (inPlace?.page === target.page && isCurrent()) {
      pages.followMove(inPlace.params)
    }
  })
}
