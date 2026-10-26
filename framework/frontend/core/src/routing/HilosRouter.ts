// The client-side navigator: it turns a path change into a no-refresh page
// transition. It owns the current-route signal, pushes history entries, and
// drives the page subscription over the LIVE socket — the connection is never
// torn down, only the page subscription is atomically replaced
// (subscription/PageSubscription). The browser binding (history and the
// popstate event) is injected as a NavigationEnvironment, so the navigator
// stays testable with no DOM and core keeps its no-browser-environment test
// rule; browserNavigationEnvironment is the binding a project passes in.
// onLeave announces the departure while the view still shows the old page.

import {
  type HilosDashboardSection,
  type HilosPageIdentity,
} from '../admin/identity/hilosPageIdentity.js'
import { isAuthReturnPath } from '../auth/authRoutes.js'
import { type PageSubscriptionError } from '../protocol/pageError.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import { HilosPages } from './hilosPages.js'
import { type PageError, ROUTE_NOT_FOUND_ERROR } from './pageError.js'
import { type PageRouteMatch, type PageRouter } from './PageRouter.js'

/** The page-subscription slice the navigator drives. */
export interface NavigablePages {
  /**
   * Subscribe the page, atomically replacing the previous subscription.
   *
   * @param pageKey The page to subscribe.
   * @param params Route params for the subscription.
   */
  subscribe(pageKey: string, params?: Record<string, string>): unknown
  /**
   * Leave the current page without subscribing another. The navigator calls it
   * for a route miss or PUBLIC_STATUS, so the subscription that was live is told
   * `page_unsubscribe` and nothing is subscribed in its place.
   */
  unsubscribe(): void
  /** Refuse a page the view layer has not built, leaving any live subscription. */
  refuseUnbuilt(pageKey: string): void
  /** The current page's subscription error, or null while it loads cleanly. */
  readonly pageError: ReadonlySignal<PageSubscriptionError | null>
  /** True while the subscribed page has not answered yet. */
  readonly pageLoading: ReadonlySignal<boolean>
  /** The current page's catalog identity, or undefined while it is on the wire. */
  readonly pageIdentity: ReadonlySignal<HilosPageIdentity | undefined>
  /** The dashboard's section grouping; undefined on every other page. */
  readonly dashboardSections: ReadonlySignal<
    HilosDashboardSection[] | undefined
  >
  /** Clear the current page's subscription error without leaving the page. */
  clearPageError(): void
  /** Draw a 403 on the current page and drop what it was showing. */
  denyCurrentPage(): void
  /** Return the current page to its just-navigated state: no error, waiting. */
  awaitPageAnswer(): void
}

/**
 * The browser binding the navigator needs, injected so the navigator stays
 * DOM-free in tests. {@link browserNavigationEnvironment} is the `window`-backed
 * default; a test passes a fake.
 */
export interface NavigationEnvironment {
  /** The current location pathname. */
  pathname(): string
  /** Push a history entry for `pathname` without reloading the document. */
  pushState(pathname: string): void
  /**
   * Replace the current history entry with `pathname`, without reloading the
   * document and without adding an entry to go back through.
   */
  replaceState(pathname: string): void
  /**
   * Subscribe to back/forward navigations (the popstate event).
   *
   * @param listener Called when the user moves through history.
   */
  onPopState(listener: () => void): Unsubscribe
}

/** A no-refresh client-side navigator. */
export interface HilosRouter {
  /** The matched route; updates on every navigation, including back/forward. */
  readonly currentRoute: ReadonlySignal<PageRouteMatch>
  /** The current location pathname; updates with the route on every navigation. */
  readonly currentPath: ReadonlySignal<string>
  /**
   * The current page's document title; updates with the route on every
   * navigation. The view binds it to `document.title` and announces it in a live
   * region, so the browser tab title and the screen-reader page-change
   * announcement track the no-refresh navigation (WCAG 2.4.2). Empty when the
   * project configured no titles.
   */
  readonly currentTitle: ReadonlySignal<string>
  /**
   * The current page's error, or null while it loads cleanly. A route miss is
   * the local route-404, already set before the socket opens and left in place
   * by a cleared, denied, or awaited subscription. Any other page forwards the
   * subscription error, except PUBLIC_STATUS, whose nginx reader has no server
   * subscription. The routed outlet (HilosView) shows an error surface
   * while it is set.
   */
  readonly pageError: ReadonlySignal<PageError | null>
  /**
   * True from a navigation until that page's subscription answers, either with
   * its payload or with an error. A route miss and a built PUBLIC_STATUS never
   * wait for a server answer. The routed
   * outlet holds the page back while it is set, so a page is never shown and
   * then taken away again by a denial that was still on the wire, and a test
   * waits on this state instead of racing the round trip with a visibility
   * assertion.
   */
  readonly pageLoading: ReadonlySignal<boolean>
  /**
   * The current page's catalog identity: its own heading and lead, its breadcrumb
   * chain, subsection cards and optional shared head owner. Bound instance names
   * project onto the matching breadcrumb keys and owner. `undefined` while the
   * page's answer is still on the wire — which is what the admin shell
   * draws its skeleton for — and `undefined` for a page the catalog carries no
   * entry for, which the shell draws as no heading rather than as a raw page key.
   * {@link HilosRouter.pageLoading} tells the two apart.
   */
  readonly pageIdentity: ReadonlySignal<HilosPageIdentity | undefined>
  /**
   * The admin dashboard's section grouping, cards and all, or `undefined` until
   * its answer lands. Only the dashboard is sent it, so every other page reads
   * `undefined` for the whole of its life.
   */
  readonly dashboardSections: ReadonlySignal<
    HilosDashboardSection[] | undefined
  >
  /**
   * The path a page key names, with each `{name}` slot filled from `params`, or
   * `undefined` when the page has no route or a required slot is uncovered. It
   * reads the application's whole route map — the framework's pages and the
   * project's alike — so a link out of a breadcrumb or a card reaches a project
   * screen as readily as a framework one. A page the view layer has not built
   * has no address either, so dashboard and section cards cannot link to it.
   *
   * @param page The page key to resolve.
   * @param params The route params to fill the slots from, defaulting to none.
   */
  resolvePath(page: string, params?: Record<string, string>): string | undefined
  /**
   * Clear the current page's subscription error without navigating. The auth
   * gate calls it on a successful session upgrade so a 401'd page un-gates and
   * its preserved subscription resumes in place (HIL-165); a no-op when no error
   * is set.
   */
  clearPageError(): void
  /**
   * Draw a 403 on the current page and drop what it was showing, without
   * navigating and without asking the server anything. The access reaction calls
   * it when the visitor loses the admin marker on an administrative surface, so
   * privileged rows do not survive one frame while the server's own verdict is
   * still on the wire (HIL-621); that verdict rules, and a page_response for the
   * current page clears what was drawn here.
   */
  denyCurrentPage(): void
  /**
   * Return the current page to the state a navigation leaves it in: error
   * cleared, page data dropped, waiting for the subscription's answer. The access
   * reaction calls it when the visitor gains the admin marker while a 403 is
   * displayed, and when the visitor loses their identity on an administrative
   * surface, so the page shows what it shows on navigation rather than a verdict
   * that no longer applies (HIL-621, HIL-652). No frame is sent - the server
   * re-decides on its own.
   */
  awaitPageAnswer(): void
  /**
   * Navigate to `pathname` in place: push a history entry, swap the route
   * signal, and re-subscribe the page over the live socket.
   *
   * @param pathname The target location pathname.
   */
  navigate(pathname: string): void
  /**
   * Rewrite the current address to `pathname`, replacing the history entry
   * rather than pushing one. The same found page only publishes the new params
   * and leaves the subscription: choosing another file of the log viewer is a
   * different address of the same page, and re-subscribing would drop what the
   * subscription had already delivered. A different page, or a move into or out
   * of a miss, runs the ordinary leave and subscribe cycle so the old
   * subscription does not stay under the new address. Use
   * {@link HilosRouter.navigate} when the new address should be a history entry.
   *
   * @param pathname The address to rewrite the current one to.
   */
  replacePath(pathname: string): void
  /**
   * Listen for the moment navigation leaves the current page. Called
   * synchronously at the start of navigate, start, and back/forward, before
   * the next route is published or its page subscribed, so HilosView can copy
   * the page still on screen. Not called by a replacePath that stays on the
   * same found page, nor by awaitPageAnswer, denyCurrentPage, clearPageError,
   * or a reconnect's re-subscribe. A replacePath that changes page, or moves
   * into or out of a miss, does call it.
   *
   * @param listener Called before the current page changes.
   * @returns A function that removes this listener.
   */
  onLeave(listener: () => void): Unsubscribe
  /** Apply the current location and begin tracking history. */
  start(): void
  /** Stop tracking history. */
  stop(): void
}

/**
 * Create a no-refresh navigator over a page router and a page subscription.
 *
 * The navigator does not connect anything: it reads the current location to
 * seed {@link HilosRouter.currentRoute}, and {@link HilosRouter.start} applies
 * that location (subscribing its page) and attaches the popstate listener.
 *
 * @param router Resolves a pathname to its page key and route params.
 * @param pages The page subscription the navigator drives.
 * @param env The browser binding; see {@link browserNavigationEnvironment}.
 * @param resolveTitle Maps the current page key to its document title; defaults
 *   to no title for a page key and to the local route-404 title for a miss.
 *   `bootHilos` supplies the project resolver.
 * @param unbuilt Pages the view layer has not built; bootHilos supplies hilosUnbuiltPages().
 */
export function createHilosRouter(
  router: PageRouter,
  pages: NavigablePages,
  env: NavigationEnvironment,
  resolveTitle: (pageKey: string | null) => string = (pageKey) =>
    pageKey === null ? ROUTE_NOT_FOUND_ERROR.title : '',
  unbuilt: ReadonlySet<string> = new Set(),
): HilosRouter {
  const currentRoute = createSignal<PageRouteMatch>(
    router.match(env.pathname()),
  )
  const currentPath = createSignal<string>(env.pathname())
  // The title is derived, not set in apply(): it recomputes from the route on
  // every navigation, so a single source feeds both document.title and the
  // page-change live region in the shell.
  // An auth return is a miss the visitor is not shown as one: the shell draws
  // the relay from the path. Naming that miss Page Not Found would put the
  // route-404 title on a sign-in that is in flight. The empty string is the
  // shell's own "do not replace the document title", so the tab keeps the
  // title the document already has.
  const currentTitle = computedSignal<string>(() =>
    isAuthReturnPath(currentPath.get())
      ? ''
      : resolveTitle(currentRoute.get().page),
  )
  let detachPopState: Unsubscribe | null = null
  const leaveListeners = new Set<() => void>()

  // Resolve a pathname and publish it as the current route and path, leaving the
  // page subscription alone — shared by apply and replacePath.
  const publish = (pathname: string): PageRouteMatch => {
    const match = router.match(pathname)
    currentRoute.set(match)
    currentPath.set(pathname)
    return match
  }

  // First tell listeners we are leaving, then publish and re-subscribe — shared
  // by start, navigate, and the popstate listener. A miss drops the real
  // subscription and never subscribes a stand-in page.
  const apply = (pathname: string): void => {
    for (const listener of [...leaveListeners]) {
      listener()
    }
    const match = publish(pathname)
    if (match.page === null) {
      pages.unsubscribe()
    } else if (unbuilt.has(match.page)) {
      pages.refuseUnbuilt(match.page)
    } else if (match.page === HilosPages.PUBLIC_STATUS) {
      // This one page reads public nginx files without a backend counterpart.
      // Unbuilt must win first so an unavailable view cannot become a ghost.
      pages.unsubscribe()
    } else {
      pages.subscribe(match.page, match.params)
    }
  }

  // Neither a miss nor the built file reader has a server subscription for
  // generic access reactions to clear, deny or await. Unbuilt keeps its refusal.
  const onPublicStatus = (): boolean =>
    currentRoute.get().page === HilosPages.PUBLIC_STATUS &&
    !unbuilt.has(HilosPages.PUBLIC_STATUS)
  const withoutSubscription = (): boolean =>
    currentRoute.get().page === null || onPublicStatus()

  return {
    currentRoute,
    currentPath,
    currentTitle,
    pageError: computedSignal<PageError | null>(() =>
      currentRoute.get().page === null
        ? ROUTE_NOT_FOUND_ERROR
        : onPublicStatus()
          ? null
          : pages.pageError.get(),
    ),
    pageLoading: computedSignal<boolean>(() =>
      withoutSubscription() ? false : pages.pageLoading.get(),
    ),
    pageIdentity: pages.pageIdentity,
    dashboardSections: pages.dashboardSections,
    resolvePath: (page, params) =>
      unbuilt.has(page) ? undefined : router.path(page, params),
    clearPageError: () => {
      if (withoutSubscription()) {
        return
      }
      pages.clearPageError()
    },
    denyCurrentPage: () => {
      if (withoutSubscription()) {
        return
      }
      pages.denyCurrentPage()
    },
    awaitPageAnswer: () => {
      if (withoutSubscription()) {
        return
      }
      pages.awaitPageAnswer()
    },
    navigate: (pathname) => {
      env.pushState(pathname)
      apply(pathname)
    },
    replacePath: (pathname) => {
      const next = router.match(pathname)
      env.replaceState(pathname)
      // Staying on the same found page, including staying on a miss, only
      // publishes. Entering or leaving a miss is a different page key.
      if (next.page === currentRoute.get().page) {
        publish(pathname)
        return
      }
      apply(pathname)
    },
    onLeave: (listener) => {
      leaveListeners.add(listener)
      return () => {
        leaveListeners.delete(listener)
      }
    },
    start: () => {
      apply(env.pathname())
      detachPopState = env.onPopState(() => apply(env.pathname()))
    },
    stop: () => {
      detachPopState?.()
      detachPopState = null
    },
  }
}

/**
 * The default {@link NavigationEnvironment}, bound to the browser `window`:
 * `history.pushState` for in-place navigation, `history.replaceState` for
 * rewriting the current address, and the `popstate` event for back/forward. A
 * project passes this when creating the navigator.
 */
export function browserNavigationEnvironment(): NavigationEnvironment {
  return {
    pathname: () => window.location.pathname,
    pushState: (pathname) => {
      window.history.pushState(null, '', pathname)
    },
    replaceState: (pathname) => {
      window.history.replaceState(null, '', pathname)
    },
    onPopState: (listener) => {
      window.addEventListener('popstate', listener)

      return () => {
        window.removeEventListener('popstate', listener)
      }
    },
  }
}
