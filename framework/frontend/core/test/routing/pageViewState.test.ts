import { afterEach, describe, expect, it, vi } from 'vitest'

import { createDeferredFlagState } from '../../src/primitives/deferredFlag.js'
import { DEFAULT_SKELETON_DELAY_MS } from '../../src/primitives/skeleton.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import {
  createHilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { hilosUnbuiltPages } from '../../src/routing/hilosUnbuiltPages.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { lazyPage } from '../../src/routing/pageView.js'
import { createPageViewState } from '../../src/routing/pageViewState.js'
import { createSignal, subscribeSignal } from '../../src/state/signal.js'

type Component = () => string
const main: Component = () => 'main'
const componentA: Component = () => 'A'
const componentB: Component = () => 'B'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

function harness(initial = '/main', unbuilt: ReadonlySet<string> = new Set()) {
  let pathname = initial
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const awaitPageAnswer = () => {
    pageError.set(null)
    pageLoading.set(true)
  }
  const pages: NavigablePages = {
    pageLoading,
    pageError,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    subscribe: vi.fn(awaitPageAnswer),
    unsubscribe: () => {
      pageLoading.set(false)
      pageError.set(null)
    },
    refuseUnbuilt: (page) => {
      pageError.set({
        page,
        httpCode: 501,
        errorCode: 'not_built',
        title: 'Not built',
        message: 'This page is not built.',
      })
      pageLoading.set(false)
    },
    clearPageError: () => pageError.set(null),
    denyCurrentPage: () => {},
    awaitPageAnswer,
  }
  const env: NavigationEnvironment = {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    replaceState: (next) => {
      pathname = next
    },
    onPopState: () => () => {},
  }
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/main', admin: false },
      a: { path: '/a/{id?}', admin: true },
      b: { path: '/b', admin: true },
      alias: { path: '/alias', admin: true },
      [HilosPages.PUBLIC_STATUS]: { path: '/status', admin: false },
    }),
    pages,
    env,
    undefined,
    unbuilt,
  )
  router.start()
  const state = createPageViewState<Component>(router)
  const answer = () => {
    pageError.set(null)
    pageLoading.set(false)
  }
  return { router, state, pages, pageLoading, pageError, answer }
}

afterEach(() => vi.useRealTimers())

describe('createPageViewState', () => {
  it('mounts cold public Status without a server answer', () => {
    const { state, pages } = harness('/status')
    state.setPages({ [HilosPages.PUBLIC_STATUS]: componentB })
    expect(pages.subscribe).not.toHaveBeenCalled()
    expect(state.view.get()).toBe(componentB)
    expect(state.loading.get()).toBe(false)
    expect(state.error.get()).toBeNull()
    state.dispose()
  })

  it.each(['pending', 'denied', 'ready'] as const)(
    'leaves a %s page for Status and gates every synchronous window on return',
    (previous) => {
      const { state, router, pageError, pageLoading, answer } = harness()
      state.setPages({ main, [HilosPages.PUBLIC_STATUS]: componentB })
      if (previous === 'ready') answer()
      if (previous === 'denied') {
        pageError.set({
          page: 'main',
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Forbidden',
          message: 'Access was removed.',
        })
        pageLoading.set(false)
      }
      const entering: Component[] = []
      const offEntry = subscribeSignal(state.view, (view) => {
        if (view) entering.push(view)
      })
      router.navigate('/status')
      offEntry()
      expect(entering).toEqual([componentB])
      expect(state.view.get()).toBe(componentB)
      expect(state.loading.get()).toBe(false)
      expect(state.error.get()).toBeNull()
      router.denyCurrentPage()
      router.awaitPageAnswer()
      expect(state.view.get()).toBe(componentB)

      const seen: Component[] = []
      const off = subscribeSignal(state.view, (view) => {
        if (view) seen.push(view)
      })
      const offRoute = subscribeSignal(router.currentRoute, () => {
        expect(state.view.get()).toBeUndefined()
        expect(state.loading.get()).toBe(true)
      })
      router.navigate('/main')
      offRoute()
      expect(seen).toEqual([])
      expect(state.view.get()).toBeUndefined()
      expect(state.loading.get()).toBe(true)
      answer()
      expect(seen).toEqual([main])
      off()
      state.dispose()
    },
  )

  it('waits for Status code, ignores stale server state, and reports a lazy import failure', async () => {
    const first = deferred<Component>()
    const { state, router, pageError, pageLoading } = harness()
    state.setPages({
      main,
      [HilosPages.PUBLIC_STATUS]: lazyPage(() => first.promise),
    })
    router.navigate('/status')
    expect(state.loading.get()).toBe(true)
    expect(state.view.get()).toBeUndefined()
    pageError.set({
      page: 'main',
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Sign in',
      message: 'Please sign in.',
    })
    pageLoading.set(true)
    expect(state.error.get()).toBeNull()
    first.reject(new Error('chunk unavailable'))
    await Promise.resolve()
    expect(state.error.get()?.errorCode).toBe('page_load_failed')
    expect(state.loading.get()).toBe(false)
    state.dispose()
  })

  it('returns to cached Status code without a wait but gates cached ordinary code', async () => {
    const status = deferred<Component>()
    const ordinary = deferred<Component>()
    const { state, router, answer } = harness()
    const loader = vi.fn(() => status.promise)
    state.setPages({
      main: lazyPage(() => ordinary.promise),
      [HilosPages.PUBLIC_STATUS]: lazyPage(loader),
    })
    ordinary.resolve(main)
    await ordinary.promise
    answer()
    expect(state.view.get()).toBe(main)
    router.navigate('/status')
    status.resolve(componentB)
    await status.promise
    expect(state.view.get()).toBe(componentB)
    router.navigate('/main')
    expect(state.view.get()).toBeUndefined()
    router.navigate('/status')
    expect(state.view.get()).toBe(componentB)
    expect(state.loading.get()).toBe(false)
    expect(loader).toHaveBeenCalledTimes(1)
    router.navigate('/main')
    expect(state.view.get()).toBeUndefined()
    answer()
    expect(state.view.get()).toBe(main)
    state.dispose()
  })

  it.each(['react', 'angular'] as const)(
    'does not import or mount a ghost Status view in %s',
    (layer) => {
      const { state } = harness('/status', hilosUnbuiltPages(layer))
      const loader = vi.fn(() => Promise.resolve(componentB))
      state.setPages({ [HilosPages.PUBLIC_STATUS]: lazyPage(loader) })
      expect(loader).not.toHaveBeenCalled()
      expect(state.view.get()).toBeUndefined()
      expect(state.loading.get()).toBe(false)
      expect(state.error.get()?.errorCode).toBe('not_built')
      state.dispose()
    },
  )

  it('keeps eager function components opaque and waits for the server', () => {
    const eager = vi.fn(main)
    const { state, answer } = harness()
    state.setPages({ main: eager })

    expect(state.loading.get()).toBe(true)
    expect(state.view.get()).toBeUndefined()
    answer()
    expect(state.view.get()).toBe(eager)
    expect(state.loading.get()).toBe(false)
    expect(state.error.get()).toBeNull()
    expect(eager).not.toHaveBeenCalled()
  })

  it('describes loaders without invoking them and starts only the selected one', () => {
    const a = vi.fn(() => deferred<Component>().promise)
    const b = vi.fn(() => deferred<Component>().promise)
    const entries = Object.freeze({ main, a: lazyPage(a), b: lazyPage(b) })
    expect(a).not.toHaveBeenCalled()
    expect(b).not.toHaveBeenCalled()
    const { router, state, answer } = harness()
    state.setPages(entries)
    answer()
    expect(a).not.toHaveBeenCalled()

    router.navigate('/a')

    expect(a).toHaveBeenCalledTimes(1)
    expect(b).not.toHaveBeenCalled()
    expect(state.loading.get()).toBe(true)
    expect(entries.a.kind).toBe('lazy-page')
  })

  it.each(['module-first', 'server-first'])(
    'waits for both arrivals: %s',
    async (order) => {
      const pending = deferred<Component>()
      const { router, state, answer } = harness()
      state.setPages({ a: lazyPage(() => pending.promise) })
      router.navigate('/a')

      if (order === 'module-first') {
        pending.resolve(componentA)
        await pending.promise
      } else {
        answer()
      }
      expect(state.loading.get()).toBe(true)
      expect(state.view.get()).toBeUndefined()
      expect(state.error.get()).toBeNull()

      if (order === 'module-first') {
        answer()
      } else {
        pending.resolve(componentA)
        await pending.promise
      }
      expect(state.view.get()).toBe(componentA)
      expect(state.loading.get()).toBe(false)
    },
  )

  it('shares an in-flight descriptor between keys and caches its success', async () => {
    const pending = deferred<Component>()
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, state, answer, pageLoading } = harness()
    state.setPages({ main, a: descriptor, alias: descriptor })
    router.navigate('/a')
    router.navigate('/alias')
    state.setPages({ main, a: descriptor, alias: descriptor })
    expect(loader).toHaveBeenCalledTimes(1)

    pending.resolve(componentA)
    await pending.promise
    answer()
    expect(state.view.get()).toBe(componentA)
    router.navigate('/main')
    answer()
    router.navigate('/a/42')
    expect(state.loading.get()).toBe(true)
    expect(state.view.get()).toBeUndefined()
    answer()
    expect(state.view.get()).toBe(componentA)

    // A reconnect or a fresh answer for the same route never imports again.
    pageLoading.set(true)
    expect(state.loading.get()).toBe(true)
    answer()
    router.navigate('/a/43')
    answer()
    expect(state.view.get()).toBe(componentA)
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it('keeps caches private to each view state', () => {
    const loader = vi.fn(() => deferred<Component>().promise)
    const descriptor = lazyPage(loader)
    const first = harness('/a')
    const second = createPageViewState<Component>(first.router)
    first.state.setPages({ a: descriptor })
    second.setPages({ a: descriptor })
    expect(loader).toHaveBeenCalledTimes(2)
    first.state.dispose()
    second.dispose()
  })

  it('reports a safe rejection once and retries only on a revisit', async () => {
    const first = deferred<Component>()
    const second = deferred<Component>()
    const loader = vi
      .fn(() => first.promise)
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)
    const descriptor = lazyPage(loader)
    const { router, state, answer, pageLoading } = harness('/a')
    state.setPages({ main, a: descriptor })
    first.reject(new Error('secret path /private/chunk.js'))
    await Promise.resolve()

    expect(state.loading.get()).toBe(false)
    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toEqual({
      httpCode: 503,
      errorCode: 'page_load_failed',
      title: 'Page could not be loaded',
      message: 'Please try opening this page again.',
    })
    answer()
    pageLoading.set(true)
    answer()
    state.setPages({ main, a: descriptor })
    expect(loader).toHaveBeenCalledTimes(1)
    expect(state.error.get()?.errorCode).toBe('page_load_failed')

    router.navigate('/main')
    answer()
    router.navigate('/a')
    expect(loader).toHaveBeenCalledTimes(2)
    expect(state.error.get()).toBeNull()
    second.resolve(componentA)
    await second.promise
    answer()
    expect(state.view.get()).toBe(componentA)
  })

  it('handles a loader throwing synchronously as the same local failure', async () => {
    const { state } = harness('/a')
    state.setPages({
      a: lazyPage(() => {
        throw new Error('private diagnostic')
      }),
    })
    await Promise.resolve()
    expect(state.error.get()?.errorCode).toBe('page_load_failed')
    expect(state.loading.get()).toBe(false)
  })

  it.each(['resolve', 'reject'])(
    "ignores a replaced descriptor's late %s at the same key",
    async (outcome) => {
      const old = deferred<Component>()
      const replacement = deferred<Component>()
      const loader = vi.fn(() => replacement.promise)
      const { state, answer } = harness('/a')
      state.setPages({ a: lazyPage(() => old.promise) })
      state.setPages({ a: lazyPage(loader) })
      answer()
      if (outcome === 'resolve') old.resolve(componentA)
      else old.reject(new Error('old failure'))
      await Promise.resolve()

      expect(loader).toHaveBeenCalledTimes(1)
      expect(state.loading.get()).toBe(true)
      expect(state.error.get()).toBeNull()
      expect(state.view.get()).toBeUndefined()
      replacement.resolve(componentB)
      await replacement.promise
      expect(state.view.get()).toBe(componentB)
    },
  )

  it.each(['resolve', 'reject'])(
    "ignores A's late %s on B and retains successful stale cache entries",
    async (outcome) => {
      const a = deferred<Component>()
      const b = deferred<Component>()
      const aLoader = vi.fn(() => a.promise)
      const { router, state, answer } = harness('/a')
      state.setPages({ a: lazyPage(aLoader), b: lazyPage(() => b.promise) })
      router.navigate('/b')
      answer()
      if (outcome === 'resolve') a.resolve(componentA)
      else a.reject(new Error('A failed late'))
      await Promise.resolve()
      expect(state.loading.get()).toBe(true)
      expect(state.error.get()).toBeNull()
      expect(state.view.get()).toBeUndefined()
      b.resolve(componentB)
      await b.promise
      expect(state.view.get()).toBe(componentB)
      router.navigate('/a')
      answer()
      expect(aLoader).toHaveBeenCalledTimes(outcome === 'resolve' ? 1 : 2)
      if (outcome === 'resolve') expect(state.view.get()).toBe(componentA)
      await Promise.resolve()
    },
  )

  it("returns A → B → A to A's original in-flight import", async () => {
    const a = deferred<Component>()
    const b = deferred<Component>()
    const loader = vi.fn(() => a.promise)
    const { router, state, answer } = harness('/a')
    state.setPages({ a: lazyPage(loader), b: lazyPage(() => b.promise) })
    router.navigate('/b')
    router.navigate('/a')
    answer()
    b.reject(new Error('B is no longer selected'))
    await Promise.resolve()
    expect(state.error.get()).toBeNull()
    expect(state.loading.get()).toBe(true)
    a.resolve(componentA)
    await a.promise
    expect(state.view.get()).toBe(componentA)
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it.each(['resolve', 'reject'])(
    'preserves a 401 server refusal after import %s',
    async (outcome) => {
      const pending = deferred<Component>()
      const { state, pageError, answer } = harness('/a')
      state.setPages({ a: lazyPage(() => pending.promise) })
      const refusal: PageSubscriptionError = {
        page: 'a',
        httpCode: 401,
        errorCode: 'unauthorized',
        title: 'Sign in required',
        message: 'Please sign in.',
      }
      pageError.set(refusal)
      expect(state.error.get()).toBe(refusal)
      expect(state.loading.get()).toBe(false)
      if (outcome === 'resolve') pending.resolve(componentA)
      else pending.reject(new Error('import failed'))
      await Promise.resolve()
      expect(state.error.get()).toBe(refusal)
      expect(state.view.get()).toBeUndefined()
      expect(state.loading.get()).toBe(false)
      answer()
      if (outcome === 'resolve') expect(state.view.get()).toBe(componentA)
      else expect(state.error.get()?.errorCode).toBe('page_load_failed')
    },
  )

  it('does not start an import under a known refusal and loads once it clears', async () => {
    const pending = deferred<Component>()
    const loader = vi.fn(() => pending.promise)
    const { router, state, pageError, answer } = harness('/a')
    pageError.set({
      page: 'a',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'No access.',
    })
    state.setPages({ a: lazyPage(loader) })
    expect(loader).not.toHaveBeenCalled()
    expect(state.loading.get()).toBe(false)
    router.awaitPageAnswer()
    expect(loader).toHaveBeenCalledTimes(1)
    pending.resolve(componentA)
    await pending.promise
    expect(state.view.get()).toBeUndefined()
    answer()
    expect(state.view.get()).toBe(componentA)
  })

  it('gives a subsequent server error precedence over a local error', async () => {
    const pending = deferred<Component>()
    const { state, pageError } = harness('/a')
    state.setPages({ a: lazyPage(() => pending.promise) })
    pending.reject(new Error('diagnostic'))
    await Promise.resolve()
    expect(state.error.get()?.errorCode).toBe('page_load_failed')
    const refusal: PageSubscriptionError = {
      page: 'a',
      httpCode: 500,
      errorCode: 'server_error',
      title: 'Server error',
      message: 'Unavailable.',
    }
    pageError.set(refusal)
    expect(state.error.get()).toBe(refusal)
  })

  it('does not invent an import wait for an unmapped, unknown, or unbuilt route', () => {
    const loader = vi.fn(() => deferred<Component>().promise)
    const { router, state, answer } = harness('/main', new Set(['b']))
    state.setPages({ b: lazyPage(loader) })
    answer()
    expect(state.loading.get()).toBe(false)
    expect(state.view.get()).toBeUndefined()
    router.navigate('/missing')
    expect(state.error.get()?.errorCode).toBe('route_not_found')
    expect(state.loading.get()).toBe(false)
    router.navigate('/b')
    expect(state.error.get()?.errorCode).toBe('not_built')
    expect(state.loading.get()).toBe(false)
    expect(loader).not.toHaveBeenCalled()
  })

  it.each(['resolve', 'reject'])(
    'ignores imports, map updates, and router events after dispose: %s',
    async (outcome) => {
      const pending = deferred<Component>()
      const loader = vi.fn(() => deferred<Component>().promise)
      const { router, state, answer } = harness('/a')
      state.setPages({
        a: lazyPage(() => pending.promise),
        b: lazyPage(loader),
      })
      const changes = vi.fn()
      const off = subscribeSignal(state.loading, changes)
      state.dispose()
      router.navigate('/b')
      answer()
      state.setPages({ b: lazyPage(loader) })
      if (outcome === 'resolve') pending.resolve(componentA)
      else pending.reject(new Error('after disposal'))
      await Promise.resolve()
      expect(changes).not.toHaveBeenCalled()
      expect(loader).not.toHaveBeenCalled()
      expect(state.view.get()).toBeUndefined()
      expect(state.loading.get()).toBe(true)
      expect(state.error.get()).toBeNull()
      off()
    },
  )

  it('keeps one skeleton deadline through server arrival and another navigation', () => {
    vi.useFakeTimers()
    const { router, state, answer } = harness()
    state.setPages({
      main,
      a: lazyPage(() => deferred<Component>().promise),
      b: lazyPage(() => deferred<Component>().promise),
    })
    answer()
    const flag = createDeferredFlagState(DEFAULT_SKELETON_DELAY_MS)
    const changes: boolean[] = []
    const off = subscribeSignal(state.loading, (loading) => {
      changes.push(loading)
      flag.set(loading)
    })
    router.navigate('/a')
    vi.advanceTimersByTime(150)
    answer()
    router.navigate('/b')
    answer()
    vi.advanceTimersByTime(149)
    expect(flag.shown.get()).toBe(false)
    vi.advanceTimersByTime(1)
    expect(flag.shown.get()).toBe(true)
    expect(changes).toEqual([true])
    off()
    flag.dispose()
    state.dispose()
  })

  it('does not lower loading between a pending import and an eager destination', () => {
    const { router, state, answer } = harness()
    state.setPages({
      main,
      a: lazyPage(() => deferred<Component>().promise),
      b: componentB,
    })
    answer()
    router.navigate('/a')
    answer()
    const changes: boolean[] = []
    const off = subscribeSignal(state.loading, (loading) =>
      changes.push(loading),
    )

    router.navigate('/b')

    expect(changes).toEqual([])
    expect(state.loading.get()).toBe(true)
    expect(state.view.get()).toBeUndefined()
    answer()
    expect(changes).toEqual([false])
    expect(state.view.get()).toBe(componentB)
    off()
  })

  it('does not wait for a server answer when replacePath keeps the same page', () => {
    const { router, state, answer } = harness('/a/1')
    state.setPages({ a: componentA })
    answer()

    router.replacePath('/a/2')

    expect(state.view.get()).toBe(componentA)
    expect(state.loading.get()).toBe(false)
  })
})

const deleted: PageSubscriptionError = {
  page: 'a',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
  transitionMessage: 'It was deleted just now.',
}

const missing: PageSubscriptionError = {
  page: 'a',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** Page `a` answered and mounted, its shell registered, then deleted live. */
function retainedHarness() {
  const opened = harness('/a/7')
  opened.state.setPages({ a: componentA, b: componentB })
  opened.answer()
  const release = opened.state.retainForResourceDeletion('a')
  opened.pageError.set(deleted)
  return { ...opened, release }
}

describe('PageViewState.retainForResourceDeletion', () => {
  it.each(['eager', 'lazy'])(
    'keeps the mounted %s page under a live deletion until a full answer',
    async (kind) => {
      const pending = deferred<Component>()
      const { state, pageError, answer } = harness('/a/7')
      state.setPages({
        a: kind === 'eager' ? componentA : lazyPage(() => pending.promise),
      })
      pending.resolve(componentA)
      await pending.promise
      answer()
      expect(state.view.get()).toBe(componentA)
      state.retainForResourceDeletion('a')

      pageError.set(deleted)

      expect(state.view.get()).toBe(componentA)
      expect(state.error.get()).toBe(deleted)
      expect(state.loading.get()).toBe(false)
      expect(state.retainedOnError.get()).toBe(true)
      answer()
      expect(state.retainedOnError.get()).toBe(false)
      expect(state.view.get()).toBe(componentA)
      expect(state.error.get()).toBeNull()
    },
  )

  it.each([
    ['nobody', null],
    ['another page', 'b'],
  ])('drops the page when %s registered', (_label, page) => {
    const { state, pageError, answer } = harness('/a/7')
    state.setPages({ a: componentA })
    answer()
    if (page !== null) state.retainForResourceDeletion(page)

    pageError.set(deleted)

    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toBe(deleted)
    expect(state.loading.get()).toBe(false)
    expect(state.retainedOnError.get()).toBe(false)
  })

  it('drops the page under a plain 404 that names no deletion', () => {
    const { state, pageError, answer } = harness('/a/7')
    state.setPages({ a: componentA })
    answer()
    state.retainForResourceDeletion('a')

    pageError.set(missing)

    expect(state.view.get()).toBeUndefined()
    expect(state.retainedOnError.get()).toBe(false)
  })

  it('never mounts an eager page whose first answer is the deletion', () => {
    const { state, pageError } = harness('/a/7')
    state.setPages({ a: componentA })
    state.retainForResourceDeletion('a')

    pageError.set(deleted)

    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toBe(deleted)
    expect(state.retainedOnError.get()).toBe(false)
  })

  it('never mounts a lazy page whose code lands after the deletion', async () => {
    const pending = deferred<Component>()
    const { state, pageError, answer } = harness('/a/7')
    state.setPages({ a: lazyPage(() => pending.promise) })
    answer()
    state.retainForResourceDeletion('a')
    pageError.set(deleted)

    pending.resolve(componentA)
    await pending.promise

    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toBe(deleted)
    expect(state.retainedOnError.get()).toBe(false)
  })

  it('never mounts a page whose import failed before the deletion', async () => {
    const pending = deferred<Component>()
    const { state, pageError, answer } = harness('/a/7')
    state.setPages({ a: lazyPage(() => pending.promise) })
    answer()
    state.retainForResourceDeletion('a')
    pending.reject(new Error('chunk failed'))
    await Promise.resolve()

    pageError.set(deleted)

    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toBe(deleted)
    expect(state.retainedOnError.get()).toBe(false)
  })

  it('keeps the shell through a later plain 404 and reads the line from it', () => {
    const { state, pageError } = retainedHarness()

    pageError.set(missing)

    expect(state.retainedOnError.get()).toBe(true)
    expect(state.view.get()).toBe(componentA)
    expect(state.error.get()).toBe(missing)
    expect(state.error.get()?.transitionMessage).toBeUndefined()
  })

  it.each([
    [403, 'forbidden'],
    [401, 'unauthorized'],
    [503, 'unavailable'],
    [404, 'not_served'],
  ])('releases the shell on a %i %s refusal', (httpCode, errorCode) => {
    const { state, pageError } = retainedHarness()
    const refusal: PageSubscriptionError = {
      page: 'a',
      httpCode,
      errorCode,
      title: 'Refused',
      message: 'The page is refused.',
    }

    pageError.set(refusal)

    expect(state.retainedOnError.get()).toBe(false)
    expect(state.view.get()).toBeUndefined()
    expect(state.error.get()).toBe(refusal)
    pageError.set(missing)
    expect(state.retainedOnError.get()).toBe(false)
  })

  it.each(['navigation', 'params', 'descriptor', 'dispose'])(
    'releases the shell on %s',
    (cause) => {
      const { router, state } = retainedHarness()

      if (cause === 'navigation') router.navigate('/b')
      else if (cause === 'params') router.replacePath('/a/8')
      else if (cause === 'descriptor') state.setPages({ a: componentB })
      else state.dispose()

      expect(state.retainedOnError.get()).toBe(false)
      expect(state.view.get()).toBeUndefined()
    },
  )

  it("lets an old generation's late release leave the new owner", () => {
    const { router, state, pageError, answer } = harness('/a/7')
    state.setPages({ a: componentA })
    answer()
    const releaseOld = state.retainForResourceDeletion('a')
    router.navigate('/a/8')
    answer()
    const releaseNew = state.retainForResourceDeletion('a')

    releaseOld()
    pageError.set(deleted)

    expect(state.retainedOnError.get()).toBe(true)
    expect(state.view.get()).toBe(componentA)
    releaseNew()
    expect(state.retainedOnError.get()).toBe(false)
    expect(state.view.get()).toBeUndefined()
  })

  it('keeps the second owner of a setup, cleanup, setup registration', () => {
    const { state, pageError, answer } = harness('/a/7')
    state.setPages({ a: componentA })
    answer()
    const first = state.retainForResourceDeletion('a')
    first()
    state.retainForResourceDeletion('a')
    first()

    pageError.set(deleted)

    expect(state.retainedOnError.get()).toBe(true)
    expect(state.view.get()).toBe(componentA)
  })
})
