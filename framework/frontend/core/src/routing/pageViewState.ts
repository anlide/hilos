// Code readiness and the server's verdict meet here; importing a component
// never grants permission to mount it. Cache lifetime is one outlet instance.

import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import { type HilosRouter } from './HilosRouter.js'
import { PAGE_LOAD_FAILED_ERROR, type PageError } from './pageError.js'
import { type LazyPageView, type PageView } from './pageView.js'

/** The routed outlet's shared code, data, and refusal state. */
export interface PageViewState<T> {
  /** The component only while both code and the server answer are ready. */
  readonly view: ReadonlySignal<T | undefined>
  /** Code or the server answer is pending, with no final error. */
  readonly loading: ReadonlySignal<boolean>
  /** The server refusal takes precedence over a local import failure. */
  readonly error: ReadonlySignal<PageError | null>
  /**
   * Replace the page map, noticing a replaced descriptor at the current key.
   *
   * @param pages The outlet's eager and deferred page entries.
   */
  setPages(pages: Readonly<Record<string, PageView<T>>>): void
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
  })
  const cache = new Map<LazyPageView<T>, LoadedView<T> | PendingView<T>>()
  let pages: Readonly<Record<string, PageView<T>>> = {}
  let selected: PageView<T> | undefined
  let generation = 0
  let disposed = false
  let departing = false
  let awaitingSubscription = false

  const view = computedSignal(() => snapshot.get().view)
  const loading = computedSignal(() => snapshot.get().loading)
  const error = computedSignal(() => snapshot.get().error)

  function publish(): void {
    if (disposed) {
      return
    }
    const failure =
      router.pageError.get() ??
      (load.status === 'failed' ? PAGE_LOAD_FAILED_ERROR : null)
    const waiting =
      failure === null &&
      router.currentRoute.get().page !== null &&
      (awaitingSubscription ||
        router.pageLoading.get() ||
        load.status === 'pending')
    snapshot.set({
      view:
        !waiting && failure === null && load.status === 'ready'
          ? load.view
          : undefined,
      loading: waiting,
      error: failure,
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
    setPages: (next) => {
      if (!disposed) {
        pages = next
        select(false)
      }
    },
    dispose: () => {
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
