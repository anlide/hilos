// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest'

import { createDeferredFlagState } from '../../src/primitives/deferredFlag.js'
import { DEFAULT_SKELETON_DELAY_MS } from '../../src/primitives/skeleton.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { bindPageDeparture } from '../../src/routing/bindPageDeparture.js'
import {
  createHilosRouter,
  type HilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import {
  type PageError,
  PAGE_LOAD_FAILED_ERROR,
} from '../../src/routing/pageError.js'
import { lazyPage } from '../../src/routing/pageView.js'
import { createPageViewState } from '../../src/routing/pageViewState.js'
import { createSignal, subscribeSignal } from '../../src/state/signal.js'

afterEach(() => {
  document.body.replaceChildren()
  vi.useRealTimers()
})

function mountBinding(composed = false) {
  const listeners = new Set<() => void>()
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const skeletonShown = createSignal(false)
  const router: HilosRouter = {
    currentRoute: createSignal({ page: 'old', params: {}, admin: false }),
    currentPath: createSignal('/old'),
    currentTitle: createSignal('Old'),
    pageLoading,
    pageError,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    onLeave: (listener) => {
      listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
    start: () => {},
    stop: () => {},
  }
  const wrapper = document.createElement('main')
  const slot = document.createElement('div')
  slot.innerHTML = '<article data-id="old-page">Leaving page</article>'
  const departure = document.createElement('div')
  wrapper.append(slot, departure)
  document.body.append(wrapper)
  const loading = createSignal(false)
  const error = createSignal<PageError | null>(null)
  const off = bindPageDeparture({
    router,
    slot,
    departure,
    skeletonShown,
    ...(composed ? { loading, error } : {}),
  })

  return {
    wrapper,
    slot,
    departure,
    pageLoading,
    pageError,
    skeletonShown,
    loading,
    error,
    off,
    leave: () => {
      for (const listener of [...listeners]) {
        listener()
      }
    },
    listenerCount: () => listeners.size,
  }
}

describe('bindPageDeparture', () => {
  it('copies the settled page on departure and clears it when the answer arrives', () => {
    const { departure, pageLoading, leave, wrapper } = mountBinding()
    wrapper.scrollTop = 25

    leave()
    expect(departure.textContent).toBe('Leaving page')
    expect(departure.querySelector('[data-id]')).toBeNull()

    pageLoading.set(true)
    pageLoading.set(false)
    expect(departure.childElementCount).toBe(0)
    expect(wrapper.scrollTop).toBe(0)
  })

  it('keeps the standing copy when a second navigation starts while loading', () => {
    const { departure, pageLoading, slot, leave } = mountBinding()
    leave()
    pageLoading.set(true)
    slot.innerHTML = '<article>Later page</article>'

    leave()

    expect(departure.textContent).toBe('Leaving page')
  })

  it('does not copy a page when loading rises without navigation', () => {
    const { departure, pageLoading } = mountBinding()

    pageLoading.set(true)

    expect(departure.childElementCount).toBe(0)
  })

  it('clears a copy on page error even when loading does not change', () => {
    const { departure, pageError, leave } = mountBinding()
    leave()

    pageError.set({
      page: 'new',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'Missing page',
    })

    expect(departure.childElementCount).toBe(0)
  })

  it('clears a copy when the skeleton appears', () => {
    const { departure, skeletonShown, leave } = mountBinding()
    leave()

    skeletonShown.set(true)

    expect(departure.childElementCount).toBe(0)
  })

  it('unsubscribes and empties the copy without moving scroll', () => {
    const { departure, wrapper, off, leave, listenerCount } = mountBinding()
    leave()
    wrapper.scrollTop = 29

    off()

    expect(departure.childElementCount).toBe(0)
    expect(wrapper.scrollTop).toBe(29)
    expect(listenerCount()).toBe(0)
    leave()
    expect(departure.childElementCount).toBe(0)
  })

  it('keeps the copy through the server answer and a second departure while code loads', () => {
    const { departure, pageLoading, loading, leave, slot } = mountBinding(true)
    leave()
    loading.set(true)
    pageLoading.set(true)
    pageLoading.set(false)
    slot.replaceChildren()
    leave()

    expect(departure.textContent).toBe('Leaving page')
    loading.set(false)
    expect(departure.childElementCount).toBe(0)
  })

  it('clears the copy on a local error even while composed loading stays true', () => {
    const { departure, loading, error, leave } = mountBinding(true)
    leave()
    loading.set(true)

    error.set(PAGE_LOAD_FAILED_ERROR)

    expect(departure.childElementCount).toBe(0)
  })

  it('clears the copy at the original skeleton deadline while only the import remains', () => {
    vi.useFakeTimers()
    const { departure, loading, pageLoading, skeletonShown, leave } =
      mountBinding(true)
    const flag = createDeferredFlagState(DEFAULT_SKELETON_DELAY_MS)
    const offLoading = subscribeSignal(loading, flag.set)
    const offShown = subscribeSignal(flag.shown, skeletonShown.set)
    leave()
    loading.set(true)
    pageLoading.set(true)
    vi.advanceTimersByTime(150)
    pageLoading.set(false)
    vi.advanceTimersByTime(149)
    expect(departure.textContent).toBe('Leaving page')

    vi.advanceTimersByTime(1)

    expect(departure.childElementCount).toBe(0)
    offLoading()
    offShown()
    flag.dispose()
  })

  it('copies before the composed state hides the settled component on route publication', () => {
    const currentRoute = createSignal({ page: 'old', params: {}, admin: false })
    const pageLoading = createSignal(false)
    const listeners = new Set<() => void>()
    const settled = () => 'Leaving page'
    const router: HilosRouter = {
      currentRoute,
      currentPath: createSignal('/old'),
      currentTitle: createSignal('Old'),
      pageLoading,
      pageError: createSignal(null),
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      resolvePath: (page) => `/${page}`,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
      onLeave: (listener) => {
        listeners.add(listener)
        return () => {
          listeners.delete(listener)
        }
      },
    }
    const state = createPageViewState<() => string>(router)
    state.setPages({
      old: settled,
      next: lazyPage(() => new Promise(() => {})),
    })
    const slot = document.createElement('div')
    const article = document.createElement('article')
    article.textContent = state.view.get()?.() ?? ''
    slot.append(article)
    const departure = document.createElement('div')
    // A synchronous consumer makes an early loading publication observable.
    const offView = subscribeSignal(state.view, () => {
      slot.replaceChildren()
    })
    const offDeparture = bindPageDeparture({
      router,
      slot,
      departure,
      skeletonShown: createSignal(false),
      loading: state.loading,
      error: state.error,
    })

    for (const listener of listeners) listener()
    currentRoute.set({ page: 'next', params: {}, admin: false })
    pageLoading.set(true)
    pageLoading.set(false)

    expect(slot.childElementCount).toBe(0)
    expect(departure.textContent).toBe('Leaving page')
    offView()
    offDeparture()
    state.dispose()
  })

  it('clears the copy when navigation lands on the local route miss', () => {
    const pageRouter = createPageRouter({
      old: { path: '/old', admin: false },
    })
    let pathname = '/old'
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
    const pages: NavigablePages = {
      subscribe: () => null,
      unsubscribe: () => {},
      refuseUnbuilt: () => {},
      pageError: createSignal<PageSubscriptionError | null>(null),
      pageLoading: createSignal(false),
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
    }
    const navigator = createHilosRouter(pageRouter, pages, env)
    navigator.start()
    const slot = document.createElement('div')
    slot.innerHTML = '<article data-id="old-page">Leaving page</article>'
    const departure = document.createElement('div')
    document.body.append(slot, departure)
    bindPageDeparture({
      router: navigator,
      slot,
      departure,
      skeletonShown: createSignal(false),
    })

    navigator.navigate('/missing')

    expect(navigator.pageError.get()?.errorCode).toBe('route_not_found')
    expect(departure.childElementCount).toBe(0)
    expect(slot.querySelector('[data-id="old-page"]')).not.toBeNull()
  })
})
