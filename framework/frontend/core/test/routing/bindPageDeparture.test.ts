// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest'

import { type PageSubscriptionError } from '../../src/protocol/pageError.js'
import { bindPageDeparture } from '../../src/routing/bindPageDeparture.js'
import {
  createHilosRouter,
  type HilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from '../../src/routing/HilosRouter.js'
import { createPageRouter } from '../../src/routing/PageRouter.js'
import { createSignal } from '../../src/state/signal.js'

afterEach(() => {
  document.body.replaceChildren()
})

function mountBinding() {
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
  const off = bindPageDeparture({ router, slot, departure, skeletonShown })

  return {
    wrapper,
    slot,
    departure,
    pageLoading,
    pageError,
    skeletonShown,
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
