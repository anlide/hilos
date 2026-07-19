// The client-side navigator: it turns a path change into a no-refresh page
// transition. It owns the current-route signal, pushes history entries, and
// drives the page subscription over the LIVE socket — the connection is never
// torn down, only the page subscription is atomically replaced
// (subscription/PageSubscription). The browser binding (history and the
// popstate event) is injected as a NavigationEnvironment, so the navigator
// stays testable with no DOM and core keeps its no-browser-environment test
// rule; browserNavigationEnvironment is the binding a project passes in.

import { type PageSubscriptionError } from '../protocol/pageError.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
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
  /** The current page's subscription error, or null while it loads cleanly. */
  readonly pageError: ReadonlySignal<PageSubscriptionError | null>
  /** Clear the current page's subscription error without leaving the page. */
  clearPageError(): void
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
   * The current page's subscription error, or null while it loads cleanly. The
   * routed outlet (HilosView) shows an error surface for the page while it is
   * set; it is cleared the moment navigation changes the page.
   */
  readonly pageError: ReadonlySignal<PageSubscriptionError | null>
  /**
   * Clear the current page's subscription error without navigating. The auth
   * gate calls it on a successful session upgrade so a 401'd page un-gates and
   * its preserved subscription resumes in place (HIL-165); a no-op when no error
   * is set.
   */
  clearPageError(): void
  /**
   * Navigate to `pathname` in place: push a history entry, swap the route
   * signal, and re-subscribe the page over the live socket.
   *
   * @param pathname The target location pathname.
   */
  navigate(pathname: string): void
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
 *   to no title (an empty string). `bootHilos` supplies the project resolver.
 */
export function createHilosRouter(
  router: PageRouter,
  pages: NavigablePages,
  env: NavigationEnvironment,
  resolveTitle: (pageKey: string) => string = () => '',
): HilosRouter {
  const currentRoute = createSignal<PageRouteMatch>(
    router.match(env.pathname()),
  )
  const currentPath = createSignal<string>(env.pathname())
  // The title is derived, not set in apply(): it recomputes from the route on
  // every navigation, so a single source feeds both document.title and the
  // page-change live region in the shell.
  const currentTitle = computedSignal<string>(() =>
    resolveTitle(currentRoute.get().page),
  )
  let detachPopState: Unsubscribe | null = null

  // Resolve a pathname, publish it as the current route and path, and
  // re-subscribe its page — shared by start, navigate, and the popstate listener.
  const apply = (pathname: string): void => {
    const match = router.match(pathname)
    currentRoute.set(match)
    currentPath.set(pathname)
    pages.subscribe(match.page, match.params)
  }

  return {
    currentRoute,
    currentPath,
    currentTitle,
    pageError: pages.pageError,
    clearPageError: () => pages.clearPageError(),
    navigate: (pathname) => {
      env.pushState(pathname)
      apply(pathname)
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
 * `history.pushState` for in-place navigation and the `popstate` event for
 * back/forward. A project passes this when creating the navigator.
 */
export function browserNavigationEnvironment(): NavigationEnvironment {
  return {
    pathname: () => window.location.pathname,
    pushState: (pathname) => {
      window.history.pushState(null, '', pathname)
    },
    onPopState: (listener) => {
      window.addEventListener('popstate', listener)

      return () => {
        window.removeEventListener('popstate', listener)
      }
    },
  }
}
