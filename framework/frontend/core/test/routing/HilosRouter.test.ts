import { describe, expect, it } from 'vitest'

import {
  createHilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { createSignal } from '../../src/state/signal.js'

const router = createPageRouter(
  {
    main: { path: '/', admin: false },
    dash: { path: '/hilos', admin: true },
    user: { path: '/user/{id}', admin: false },
  },
  { fallback: 'main' },
)

// A fake browser binding: an in-memory pathname plus a single popstate
// listener, so the navigator is exercised with no DOM.
function fakeEnvironment(initial: string) {
  let pathname = initial
  let popListener: (() => void) | null = null

  const env: NavigationEnvironment = {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    onPopState: (listener) => {
      popListener = listener

      return () => {
        popListener = null
      }
    },
  }

  return {
    env,
    /** Simulate a back/forward navigation to `next`. */
    pop: (next: string) => {
      pathname = next
      popListener?.()
    },
    isPopAttached: () => popListener !== null,
  }
}

// A fake page subscription that records what it was asked to subscribe and
// carries the writable page-error and page-loading signals the navigator
// proxies.
function fakePages() {
  const calls: Array<{ page: string; params: Record<string, string> }> = []
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const pageLoading = createSignal(false)
  let cleared = 0
  const pages: NavigablePages = {
    subscribe: (page, params = {}) => {
      calls.push({ page, params })

      return null
    },
    pageError,
    pageLoading,
    clearPageError: () => {
      cleared += 1
      pageError.set(null)
    },
  }

  return { pages, calls, pageError, clearedCount: () => cleared }
}

describe('createHilosRouter', () => {
  it('seeds the current route from the location before start', () => {
    const { env } = fakeEnvironment('/hilos')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentRoute.get().page).toBe('dash')
    expect(navigator.currentPath.get()).toBe('/hilos')
  })

  it('derives the current title from the route via the resolver', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const titles: Record<string, string> = { main: 'Home', user: 'User' }
    const navigator = createHilosRouter(
      router,
      pages,
      env,
      (page) => titles[page] ?? '',
    )
    navigator.start()

    expect(navigator.currentTitle.get()).toBe('Home')

    navigator.navigate('/user/42')

    expect(navigator.currentTitle.get()).toBe('User')
  })

  it('defaults the current title to empty without a resolver', () => {
    const { env } = fakeEnvironment('/')
    const { pages } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.currentTitle.get()).toBe('')
  })

  it('subscribes the current page and attaches popstate on start', () => {
    const { env, isPopAttached } = fakeEnvironment('/hilos')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    navigator.start()

    expect(calls).toEqual([{ page: 'dash', params: {} }])
    expect(isPopAttached()).toBe(true)
  })

  it('navigates in place: pushes history, swaps route, re-subscribes', () => {
    const { env } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.navigate('/user/42')

    expect(env.pathname()).toBe('/user/42')
    expect(navigator.currentPath.get()).toBe('/user/42')
    expect(navigator.currentRoute.get()).toEqual({
      page: 'user',
      params: { id: '42' },
      admin: false,
    })
    expect(calls.at(-1)).toEqual({ page: 'user', params: { id: '42' } })
  })

  it('re-applies the route on back/forward navigation', () => {
    const { env, pop } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    pop('/hilos')

    expect(navigator.currentRoute.get().page).toBe('dash')
    expect(calls.at(-1)).toEqual({ page: 'dash', params: {} })
  })

  it('stops tracking history on stop', () => {
    const { env, pop, isPopAttached } = fakeEnvironment('/')
    const { pages, calls } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()

    navigator.stop()
    pop('/hilos')

    expect(isPopAttached()).toBe(false)
    expect(navigator.currentRoute.get().page).toBe('main')
    expect(calls).toEqual([{ page: 'main', params: {} }])
  })

  it('proxies the page subscription error from the page subscription', () => {
    const { env } = fakeEnvironment('/')
    const { pages, pageError } = fakePages()
    const navigator = createHilosRouter(router, pages, env)

    expect(navigator.pageError.get()).toBeNull()

    pageError.set({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'Resource #9 not found',
    })

    expect(navigator.pageError.get()?.httpCode).toBe(404)
  })

  it('clears the page error through the page subscription without navigating', () => {
    const { env, isPopAttached } = fakeEnvironment('/')
    const { pages, pageError, calls, clearedCount } = fakePages()
    const navigator = createHilosRouter(router, pages, env)
    navigator.start()
    pageError.set({
      page: 'user',
      httpCode: 401,
      errorCode: 'unauthorized',
      message: 'Authentication required',
    })
    const subscribesBefore = calls.length

    navigator.clearPageError()

    expect(clearedCount()).toBe(1)
    expect(navigator.pageError.get()).toBeNull()
    // Resume in place: no re-subscribe, and history tracking is untouched.
    expect(calls.length).toBe(subscribesBefore)
    expect(isPopAttached()).toBe(true)
  })
})
