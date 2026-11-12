// External router and project-slot inputs for the routed outlet scenarios.
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  type AuthGate,
  type HilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
  type PageRouteMatch,
  type PageSubscriptionError,
} from '@hilos/core/base'

/** Project inputs translated into real components by each SDK adapter. */
export interface HilosViewOutletInput {
  router: HilosRouter
  withSkeletons?: boolean
  authSurface?: { heading?: string }
  authGate?: AuthGate
}

/** Only lifecycle operations; all outlet decisions remain in the real SDK. */
export interface HilosViewOutlet {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
}

/** Creation and steps share one cleanup lifetime, including a failed mount. */
export type WithHilosViewOutlet = (
  input: HilosViewOutletInput,
  steps: (view: HilosViewOutlet) => Promise<void>,
) => Promise<void>

/** Controlled server inputs and the navigator's external departure notification. */
export function outletRouter(page = 'main', loading = true) {
  const pageLoading = createSignal(loading)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const leaveListeners = new Set<() => void>()
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page,
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageLoading,
    pageError,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (key) => `/${key}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaveListeners.add(listener)
      return () => leaveListeners.delete(listener)
    },
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return {
    router,
    pageLoading,
    pageError,
    depart() {
      for (const listener of [...leaveListeners]) listener()
      pageLoading.set(true)
    },
  }
}

/** Real navigation with a permitting answer on each subscribed page. */
export function outletNavigation(): HilosRouter {
  let pathname = '/known'
  const environment: NavigationEnvironment = {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    replaceState: (next) => {
      pathname = next
    },
    onPopState: () => () => {},
  }
  const pageLoading = createSignal(false)
  const pages: NavigablePages = {
    subscribe: () => {
      pageLoading.set(true)
      pageLoading.set(false)
      return null
    },
    unsubscribe: () => {},
    refuseUnbuilt: () => {},
    pageError: createSignal<PageSubscriptionError | null>(null),
    pageLoading,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
  }
  return createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      known: { path: '/known', admin: false },
    }),
    pages,
    environment,
  )
}
