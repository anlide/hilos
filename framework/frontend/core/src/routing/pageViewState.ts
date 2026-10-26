// Code readiness and the server's verdict meet here; importing a component
// never grants permission to mount it. Cache lifetime is one outlet instance.

import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
  type Unsubscribe,
} from '../state/signal.js'
import { HilosPages } from './hilosPages.js'
import { type HilosRouter } from './HilosRouter.js'
import { PAGE_LOAD_FAILED_ERROR, type PageError } from './pageError.js'
import { type LazyPageView, type PageView } from './pageView.js'

/** HTTP status of a resource the server no longer finds. */
const RESOURCE_NOT_FOUND_STATUS = 404

/** The server's code for that missing resource; not a route or unbuilt miss. */
const RESOURCE_NOT_FOUND_CODE = 'not_found'

/** The routed outlet's shared code, data, and refusal state. */
export interface PageViewState<T> {
  /** Ready code after the server answer; PUBLIC_STATUS needs only its code. */
  readonly view: ReadonlySignal<T | undefined>
  /** Code or the server answer is pending, with no final error. */
  readonly loading: ReadonlySignal<boolean>
  /** The server refusal takes precedence over a local import failure. */
  readonly error: ReadonlySignal<PageError | null>
  /**
   * The registered page draws the current refusal itself: `view` keeps the
   * component already mounted for this route, so the windows it owns stay.
   */
  readonly retainedOnError: ReadonlySignal<boolean>
  /**
   * Replace the page map, noticing a replaced descriptor at the current key.
   *
   * @param pages The outlet's eager and deferred page entries.
   */
  setPages(pages: Readonly<Record<string, PageView<T>>>): void
  /**
   * Register the mounted page as the shell that draws a deletion of its own
   * resource. Only a live 404 naming that transition starts the hold, on the
   * same page, route, and mounted component; it never mounts a page.
   *
   * @param page The page key the registering shell serves.
   * @returns A function that removes this registration, never a later one.
   */
  retainForResourceDeletion(page: string): Unsubscribe
  /** Detach listeners and prevent late imports from publishing state. */
  dispose(): void
}

type LoadedView<T> = { status: 'ready'; view: T }
type PendingView<T> = { status: 'pending'; promise: Promise<T> }
type ViewLoad<T> =
  LoadedView<T> | { status: 'empty' | 'idle' | 'pending' | 'failed' }

interface ViewSnapshot<T> {
  view: T | undefined
  loading: boolean
  error: PageError | null
  retainedOnError: boolean
}

/** One shell's claim on the component mounted for one route generation. */
interface Retention<T> {
  readonly page: string
  readonly generation: number
  readonly view: T | undefined
}

/** Identify the descriptor by its tag, never by a component being callable. */
function isLazyPageView<T>(view: PageView<T>): view is LazyPageView<T> {
  return (
    view !== null &&
    (typeof view === 'object' || typeof view === 'function') &&
    'kind' in view &&
    view.kind === 'lazy-page'
  )
}

/**
 * Compose page code readiness with the existing server subscription signals.
 * Repeated true loading values do not notify, so a bound deferred skeleton flag
 * keeps its original timer throughout a continuous wait.
 *
 * @param router The navigator whose selected route and server verdict to follow.
 */
export function createPageViewState<T>(router: HilosRouter): PageViewState<T> {
  let load: ViewLoad<T> = { status: 'empty' }
  const snapshot = createSignal<ViewSnapshot<T>>({
    view: undefined,
    loading: router.pageError.get() === null && router.pageLoading.get(),
    error: router.pageError.get(),
    retainedOnError: false,
  })
  const cache = new Map<LazyPageView<T>, LoadedView<T> | PendingView<T>>()
  let pages: Readonly<Record<string, PageView<T>>> = {}
  let selected: PageView<T> | undefined
  let generation = 0
  let disposed = false
  let departing = false
  let awaitingSubscription = false
  let retention: Retention<T> | null = null

  const view = computedSignal(() => snapshot.get().view)
  const loading = computedSignal(() => snapshot.get().loading)
  const error = computedSignal(() => snapshot.get().error)
  const retainedOnError = computedSignal(() => snapshot.get().retainedOnError)

  // A live 404 that names the deletion starts the hold; while held, a later
  // plain 404 of the same subscription continues it. Only the component the
  // shell registered from after mounting on this route generation is kept.
  function retains(serverError: PageError | null, loaded: T): boolean {
    return (
      serverError !== null &&
      serverError.httpCode === RESOURCE_NOT_FOUND_STATUS &&
      serverError.errorCode === RESOURCE_NOT_FOUND_CODE &&
      (serverError.transitionMessage !== undefined ||
        snapshot.get().retainedOnError) &&
      retention !== null &&
      retention.generation === generation &&
      retention.page === router.currentRoute.get().page &&
      retention.view === loaded
    )
  }

  function publish(): void {
    if (disposed) {
      return
    }
    const serverError = router.pageError.get()
    const failure =
      serverError ?? (load.status === 'failed' ? PAGE_LOAD_FAILED_ERROR : null)
    const waiting =
      failure === null &&
      router.currentRoute.get().page !== null &&
      (awaitingSubscription ||
        router.pageLoading.get() ||
        load.status === 'pending')
    const retained = load.status === 'ready' && retains(serverError, load.view)
    snapshot.set({
      view:
        load.status === 'ready' && (retained || (!waiting && failure === null))
          ? load.view
          : undefined,
      loading: waiting,
      error: failure,
      retainedOnError: retained,
    })
  }

  function setLoad(next: ViewLoad<T>): void {
    load = next
    publish()
  }

  function follow(descriptor: LazyPageView<T>, pending: PendingView<T>): void {
    const attempt = generation
    setLoad({ status: 'pending' })
    void pending.promise.then(
      (component) => {
        if (!disposed && generation === attempt && selected === descriptor) {
          setLoad({ status: 'ready', view: component })
        }
      },
      () => {
        if (!disposed && generation === attempt && selected === descriptor) {
          setLoad({ status: 'failed' })
        }
      },
    )
  }

  function select(revisit: boolean): void {
    if (disposed) {
      return
    }
    const route = router.currentRoute.get()
    const next =
      route.page !== null &&
      router.resolvePath(route.page, route.params) !== undefined &&
      Object.hasOwn(pages, route.page)
        ? pages[route.page]
        : undefined
    if (!revisit && next === selected) {
      return
    }
    selected = next
    generation += 1
    if (next === undefined) {
      setLoad({ status: 'empty' })
      return
    }
    if (!isLazyPageView(next)) {
      setLoad({ status: 'ready', view: next })
      return
    }
    const cached = cache.get(next)
    if (cached?.status === 'ready') {
      setLoad(cached)
      return
    }
    if (cached?.status === 'pending') {
      follow(next, cached)
      return
    }
    if (router.pageError.get() !== null) {
      setLoad({ status: 'idle' })
      return
    }

    let promise: Promise<T>
    try {
      promise = Promise.resolve(next.load())
    } catch {
      promise = Promise.reject()
    }
    cache.set(next, { status: 'pending', promise })
    void promise.then(
      (component) => {
        if (!disposed) {
          cache.set(next, { status: 'ready', view: component })
        }
      },
      () => {
        if (!disposed) {
          cache.delete(next)
        }
      },
    )
    follow(next, { status: 'pending', promise })
  }

  // Only remember departure here: the old view must stay settled until every
  // onLeave listener has copied it. Route publication then bridges the moment
  // before subscribe raises pageLoading, even for an already cached component.
  const offLeave = router.onLeave(() => {
    departing = true
  })
  const offRoute = subscribeSignal(router.currentRoute, (route) => {
    if (departing) {
      awaitingSubscription =
        route.page !== null &&
        // Only the public nginx reader has no first server answer to await.
        route.page !== HilosPages.PUBLIC_STATUS &&
        router.resolvePath(route.page, route.params) !== undefined
      departing = false
    }
    select(true)
  })
  const offError = subscribeSignal(router.pageError, (serverError) => {
    if (serverError !== null) {
      awaitingSubscription = false
    }
    if (serverError === null && load.status === 'idle') {
      select(true)
    } else {
      publish()
    }
  })
  const offLoading = subscribeSignal(router.pageLoading, () => {
    awaitingSubscription = false
    publish()
  })

  return {
    view,
    loading,
    error,
    retainedOnError,
    setPages: (next) => {
      if (!disposed) {
        pages = next
        select(false)
      }
    },
    retainForResourceDeletion: (page) => {
      const own: Retention<T> = { page, generation, view: snapshot.get().view }
      retention = own
      return () => {
        if (retention === own) {
          retention = null
          publish()
        }
      }
    },
    dispose: () => {
      retention = null
      if (snapshot.get().retainedOnError) {
        publish()
      }
      disposed = true
      generation += 1
      offLeave()
      offRoute()
      offError()
      offLoading()
      cache.clear()
    },
  }
}
