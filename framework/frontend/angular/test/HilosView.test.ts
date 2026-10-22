// The routed outlet while a page waits for its first answer (HIL-983): a skeleton
// in the page's place instead of white, never before the delay, gone on the
// answer, and never over a refusal. The page-state marker the e2e suites of all
// three demos wait on keeps its three values through all of it.
//
// And a page shell that draws the deletion of its own resource (HIL-1603): the
// outlet keeps the opted-in page mounted, its open dialog and draft with it,
// while the shell draws the one ErrorPage in place of its content.
import { Component, signal, type Type } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createAuthGate,
  createHilosRouter,
  createPageRouter,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  lazyPage,
} from '@hilos/core'
import type {
  AuthGate,
  HilosPageIdentity,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageRouteMatch,
  PageSubscriptionError,
  PageView,
  WritableSignal,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { hilosAdminViews } from '@hilos/angular/admin-views'
import { HilosPages, type LazyPageView } from '@hilos/core'

const NOT_FOUND: PageSubscriptionError = {
  page: 'main',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'Not found',
}

@Component({
  selector: 'test-main-page',
  template: '<div data-id="main">Leaving page</div>',
})
class MainPage {}

@Component({
  selector: 'test-main-skeleton',
  template: '<div data-id="main-skeleton"></div>',
})
class MainSkeleton {}

@Component({
  selector: 'test-known-page',
  template: '<div data-id="known-page">Known</div>',
})
class KnownPage {}

interface WaitingRouter {
  router: HilosRouter
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
  depart(): void
}

/**
 * A router whose page is still waiting for its first answer.
 *
 * @param page The key of the current page.
 * @returns The router and the two signals a case drives.
 */
function waitingRouter(page: string): WaitingRouter {
  const pageLoading = createSignal(true)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const leaveListeners = new Set<() => void>()

  return {
    pageLoading,
    pageError,
    depart: () => {
      for (const listener of [...leaveListeners]) {
        listener()
      }
      pageLoading.set(true)
    },
    router: {
      currentRoute: createSignal<PageRouteMatch>({
        page,
        params: {},
        admin: false,
      }),
      currentPath: createSignal(''),
      currentTitle: createSignal(''),
      pageError,
      pageLoading,
      pageIdentity: createSignal(undefined),
      dashboardSections: createSignal(undefined),
      resolvePath: (key) => `/${key}`,
      clearPageError: () => {},
      denyCurrentPage: () => {},
      awaitPageAnswer: () => {},
      onLeave: (listener) => {
        leaveListeners.add(listener)
        return () => {
          leaveListeners.delete(listener)
        }
      },
      navigate: () => {},
      replacePath: () => {},
      start: () => {},
      stop: () => {},
    },
  }
}

/**
 * Mount the outlet over a router, optionally with a declared skeleton for the
 * main page.
 *
 * @param router The router the outlet mirrors.
 * @param withSkeletons Whether the project declares its own main skeleton.
 * @returns The mounted fixture.
 */
function mountView(
  router: HilosRouter,
  withSkeletons = false,
): ComponentFixture<HilosView> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosView)
  fixture.componentRef.setInput('pages', { main: MainPage })
  if (withSkeletons) {
    fixture.componentRef.setInput('pageSkeletons', { main: MainSkeleton })
  }
  fixture.detectChanges()

  return fixture
}

function outlast(fixture: ComponentFixture<HilosView>, ms: number): void {
  vi.advanceTimersByTime(ms)
  fixture.detectChanges()
}

function find(
  fixture: ComponentFixture<HilosView>,
  dataId: string,
): Element | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${dataId}"]`,
  )
}

describe('HilosView skeleton', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('draws nothing in the first moments of the wait', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 1)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
  })

  it('draws the default skeleton once the wait outlasts the delay', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(
      find(fixture, 'hilos-page-skeleton')?.querySelector(
        '[data-id="hilos-skeleton"]',
      ),
    ).not.toBeNull()
  })

  it('announces the page skeleton once, whatever it draws', () => {
    const fixture = mountView(waitingRouter('main').router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    const status = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[role="status"]',
    )
    expect(status).toHaveLength(1)
    expect(status[0].textContent).toBe('Loading…')
  })

  it('gives way to the page on its answer', () => {
    const { router, pageLoading } = waitingRouter('main')
    const fixture = mountView(router)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    pageLoading.set(false)
    fixture.detectChanges()
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(find(fixture, 'main')).not.toBeNull()
  })

  it('draws the skeleton the project declared for the page key', () => {
    const fixture = mountView(waitingRouter('main').router, true)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'main-skeleton')).not.toBeNull()
    expect(find(fixture, 'hilos-skeleton')).toBeNull()
  })

  it('falls back to the default skeleton for an undeclared page key', () => {
    const fixture = mountView(waitingRouter('settings').router, true)

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'main-skeleton')).toBeNull()
    expect(find(fixture, 'hilos-skeleton')).not.toBeNull()
  })

  it('shows the refusal, not a skeleton, when the page is denied', () => {
    const { router, pageError } = waitingRouter('main')
    const fixture = mountView(router)

    pageError.set(NOT_FOUND)
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(find(fixture, 'page-error')).not.toBeNull()
  })

  it('keeps the page-state marker going from loading to ready', () => {
    const { router, pageLoading } = waitingRouter('main')
    const fixture = mountView(router)
    const marker = () =>
      find(fixture, 'hilos-page-state')?.getAttribute('data-state')

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(marker()).toBe('loading')
    pageLoading.set(false)
    fixture.detectChanges()
    expect(marker()).toBe('ready')
  })
})

describe('HilosView departure', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps an inert copy until the next page answers', () => {
    const state = waitingRouter('main')
    state.pageLoading.set(false)
    const fixture = mountView(state.router)

    state.depart()
    fixture.detectChanges()
    const departure = find(fixture, 'hilos-page-departure')!
    expect(departure.getAttribute('aria-hidden')).toBe('true')
    expect(departure.hasAttribute('inert')).toBe(true)
    expect(departure.textContent?.trim()).toBe('Leaving page')
    expect(departure.querySelector('[data-id]')).toBeNull()

    state.pageLoading.set(false)
    fixture.detectChanges()
    expect(departure.childElementCount).toBe(0)
  })

  it('removes the copy on an error or the skeleton threshold', () => {
    const state = waitingRouter('main')
    state.pageLoading.set(false)
    const fixture = mountView(state.router)
    const departure = find(fixture, 'hilos-page-departure')!

    state.depart()
    fixture.detectChanges()
    state.pageError.set(NOT_FOUND)
    fixture.detectChanges()
    expect(departure.childElementCount).toBe(0)

    state.pageError.set(null)
    state.pageLoading.set(false)
    fixture.detectChanges()
    state.depart()
    fixture.detectChanges()
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'hilos-page-skeleton')).not.toBeNull()
    expect(departure.childElementCount).toBe(0)
  })

  it('does not copy a loading change and keeps a copy through repeated departures', () => {
    const state = waitingRouter('main')
    state.pageLoading.set(false)
    const fixture = mountView(state.router)
    const departure = find(fixture, 'hilos-page-departure')!

    state.pageLoading.set(true)
    fixture.detectChanges()
    expect(departure.childElementCount).toBe(0)

    state.pageLoading.set(false)
    fixture.detectChanges()
    state.depart()
    const copied = departure.firstElementChild
    state.depart()
    expect(departure.firstElementChild).toBe(copied)
  })
})

function navigablePages(): NavigablePages {
  const pageLoading = createSignal(false)

  return {
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
}

function navigation(initial: string): NavigationEnvironment {
  let pathname = initial

  return {
    pathname: () => pathname,
    pushState: (next) => {
      pathname = next
    },
    replaceState: (next) => {
      pathname = next
    },
    onPopState: () => () => {},
  }
}

describe('HilosView route miss', () => {
  afterEach(() => TestBed.resetTestingModule())

  it('shows the route error instead of the old page and returns home', () => {
    const pageRouter = createPageRouter({
      main: { path: '/', admin: false },
      known: { path: '/known', admin: false },
    })
    const navigator = createHilosRouter(
      pageRouter,
      navigablePages(),
      navigation('/known'),
    )
    navigator.start()
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: navigator }],
    })
    const fixture = TestBed.createComponent(HilosView)
    fixture.componentRef.setInput('pages', { main: MainPage, known: KnownPage })
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelector('[data-id="known-page"]')).not.toBeNull()

    navigator.navigate('/nope')
    fixture.detectChanges()

    const surface = root.querySelector('[data-id="page-error"]')
    expect(
      root
        .querySelector('[data-id="hilos-page-state"]')
        ?.getAttribute('data-state'),
    ).toBe('error')
    expect(surface).not.toBeNull()
    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(surface?.querySelector('h1')?.textContent?.trim()).toBe('404')
    expect(surface?.textContent).toContain('Page Not Found')
    expect(surface?.textContent).toContain(
      'The page you are looking for does not exist.',
    )
    expect(root.querySelector('[data-id="known-page"]')).toBeNull()
    expect(root.querySelector('[data-id="main"]')).toBeNull()
    expect(root.querySelector('[data-id="hilos-page-skeleton"]')).toBeNull()

    surface?.querySelector<HTMLElement>('[data-id="page-error-home"]')?.click()
    fixture.detectChanges()

    expect(root.querySelector('[data-id="main"]')).not.toBeNull()
    expect(root.querySelector('[data-id="page-error"]')).toBeNull()
    expect(
      root
        .querySelector('[data-id="hilos-page-state"]')
        ?.getAttribute('data-state'),
    ).toBe('ready')
  })
})

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

function trackedPage(id: string) {
  const mounted = vi.fn()
  @Component({
    selector: 'test-tracked-page',
    template: `<div data-id="${id}">${id}</div>`,
  })
  class TrackedPage {
    constructor() {
      mounted()
    }
  }
  return { component: TrackedPage, mounted }
}

/** Real navigation order, with independently controlled server answers. */
function controlledRouter(
  initial = '/',
  unbuilt: ReadonlySet<string> = new Set(),
) {
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(undefined)
  const awaitPageAnswer = () => {
    pageError.set(null)
    pageLoading.set(true)
  }
  const subscribe = vi.fn(awaitPageAnswer)
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      lazy: { path: '/lazy/{id?}', admin: true },
      other: { path: '/other', admin: true },
    }),
    {
      ...navigablePages(),
      pageLoading,
      pageError,
      pageIdentity,
      subscribe,
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
      awaitPageAnswer,
    },
    navigation(initial),
    undefined,
    unbuilt,
  )
  router.start()
  const answer = () => {
    pageError.set(null)
    pageLoading.set(false)
  }
  return { router, pageLoading, pageError, pageIdentity, subscribe, answer }
}

/** Mount inputs before Angular runs effects that read required inputs. */
function mountPages(
  router: HilosRouter,
  pages: Record<string, PageView<Type<unknown>>>,
  extra: {
    pageSkeletons?: Record<string, Type<unknown>>
    authSurface?: Type<unknown>
    authGate?: AuthGate
  } = {},
): ComponentFixture<HilosView> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosView)
  fixture.componentRef.setInput('pages', pages)
  for (const [key, value] of Object.entries(extra)) {
    fixture.componentRef.setInput(key, value)
  }
  fixture.detectChanges()
  return fixture
}

function marker(fixture: ComponentFixture<HilosView>) {
  return find(fixture, 'hilos-page-state')?.getAttribute('data-state')
}

async function settleCode(fixture: ComponentFixture<HilosView>): Promise<void> {
  await Promise.resolve()
  fixture.detectChanges()
}

describe('HilosView lazy pages', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('keeps the departure and original skeleton deadline after a fast server answer', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const { router, answer, pageLoading, subscribe } = controlledRouter()
    answer()
    const fixture = mountPages(
      router,
      { main: MainPage, lazy: lazyPage(loader) },
      { pageSkeletons: { lazy: MainSkeleton } },
    )
    const copy = find(fixture, 'hilos-page-departure')!
    const frame = copy.parentElement!
    frame.scrollTop = 90
    expect(loader).not.toHaveBeenCalled()

    router.navigate('/lazy')
    fixture.detectChanges()
    outlast(fixture, 100)
    answer()
    fixture.detectChanges()
    expect(pageLoading.get()).toBe(false)
    expect(copy.textContent).toBe('Leaving page')
    expect(copy.getAttribute('aria-hidden')).toBe('true')
    expect(copy.hasAttribute('inert')).toBe(true)
    expect(copy.querySelector('[data-id]')).toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(marker(fixture)).toBe('loading')

    outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 101)
    expect(copy.textContent).toBe('Leaving page')
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    outlast(fixture, 1)
    expect(copy.childElementCount).toBe(0)
    expect(frame.scrollTop).toBe(0)
    expect(find(fixture, 'main-skeleton')).not.toBeNull()
    const statuses = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '[role="status"]',
    )
    expect(statuses).toHaveLength(1)
    expect(statuses[0].textContent).toBe('Loading…')

    pending.resolve(page.component)
    await settleCode(fixture)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(find(fixture, 'lazy-page')).not.toBeNull()
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(marker(fixture)).toBe('ready')
    expect(subscribe).toHaveBeenCalledTimes(2)
  })

  it('does not mount fast code before the server answers or restart its skeleton timer', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const { router, answer } = controlledRouter('/lazy')
    const fixture = mountPages(router, {
      lazy: lazyPage(() => pending.promise),
    })

    outlast(fixture, 100)
    pending.resolve(page.component)
    await settleCode(fixture)
    expect(page.mounted).not.toHaveBeenCalled()
    expect(marker(fixture)).toBe('loading')
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 101)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    outlast(fixture, 1)
    expect(find(fixture, 'hilos-page-skeleton')).not.toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()

    answer()
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(marker(fixture)).toBe('ready')
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[role="status"]'),
    ).toBeNull()
  })

  it.each(['resolve', 'reject'] as const)(
    'keeps one deadline through a second navigation and ignores a late %s',
    async (outcome) => {
      const first = deferred<Type<unknown>>()
      const second = deferred<Type<unknown>>()
      const stale = trackedPage('stale-page')
      const current = trackedPage('current-page')
      const { router, answer } = controlledRouter()
      answer()
      const fixture = mountPages(router, {
        main: MainPage,
        lazy: lazyPage(() => first.promise),
        other: lazyPage(() => second.promise),
      })
      const copy = find(fixture, 'hilos-page-departure')!

      router.navigate('/lazy')
      fixture.detectChanges()
      outlast(fixture, 200)
      const copied = copy.firstElementChild
      router.navigate('/other')
      answer()
      fixture.detectChanges()
      expect(copy.firstElementChild).toBe(copied)
      expect(copy.textContent).toBe('Leaving page')
      outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 201)
      expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
      outlast(fixture, 1)
      expect(find(fixture, 'hilos-page-skeleton')).not.toBeNull()
      expect(copy.childElementCount).toBe(0)

      if (outcome === 'resolve') {
        first.resolve(stale.component)
      } else {
        first.reject(new Error('Old chunk failed'))
      }
      await settleCode(fixture)
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(current.mounted).not.toHaveBeenCalled()
      expect(find(fixture, 'page-error')).toBeNull()
      expect(marker(fixture)).toBe('loading')
      second.resolve(current.component)
      await settleCode(fixture)
      expect(current.mounted).toHaveBeenCalledTimes(1)
      expect(marker(fixture)).toBe('ready')
    },
  )

  it('removes the departure when code and data are ready without flashing a skeleton', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const { router, answer } = controlledRouter()
    answer()
    const fixture = mountPages(router, {
      main: MainPage,
      lazy: lazyPage(() => pending.promise),
    })

    router.navigate('/lazy')
    fixture.detectChanges()
    outlast(fixture, 100)
    answer()
    pending.resolve(page.component)
    await settleCode(fixture)
    expect(find(fixture, 'hilos-page-departure')!.childElementCount).toBe(0)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[role="status"]'),
    ).toBeNull()
  })

  it('shows a safe local error, returns home by SPA navigation and retries only on revisit', async () => {
    const first = deferred<Type<unknown>>()
    const retry = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const loader = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(retry.promise)
    const { router, answer, subscribe } = controlledRouter()
    answer()
    const fixture = mountPages(router, {
      main: MainPage,
      lazy: lazyPage(loader),
    })

    router.navigate('/lazy')
    fixture.detectChanges()
    first.reject(new Error('secret engine message /assets/chunk.js'))
    await settleCode(fixture)
    const surface = find(fixture, 'page-error')!
    expect(surface.getAttribute('data-error-code')).toBe('503')
    expect(surface.querySelector('h1')?.textContent?.trim()).toBe('503')
    expect(surface.querySelector('.lead')?.textContent).toBe(
      'Page could not be loaded',
    )
    expect(surface.querySelector('p.text-body-secondary')?.textContent).toBe(
      'Please try opening this page again.',
    )
    expect(surface.textContent).not.toContain('secret engine message')
    expect(surface.textContent).not.toContain('/assets/chunk.js')
    expect(marker(fixture)).toBe('error')
    expect(find(fixture, 'hilos-page-departure')!.childElementCount).toBe(0)
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(find(fixture, 'hilos-page-skeleton')).toBeNull()

    surface.querySelector<HTMLElement>('[data-id="page-error-home"]')!.click()
    fixture.detectChanges()
    expect(router.currentPath.get()).toBe('/')
    expect(subscribe).toHaveBeenLastCalledWith('main', {})
    answer()
    fixture.detectChanges()
    expect(find(fixture, 'main')).not.toBeNull()
    router.navigate('/lazy')
    fixture.detectChanges()
    expect(loader).toHaveBeenCalledTimes(2)
    answer()
    retry.resolve(page.component)
    await settleCode(fixture)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(find(fixture, 'page-error')).toBeNull()
  })

  it.each([
    { httpCode: 401, auth: true },
    { httpCode: 401, auth: false },
    { httpCode: 403, auth: true },
    { httpCode: 404, auth: true },
  ])(
    'shows the server refusal immediately ($httpCode, auth=$auth) over a late import failure',
    async ({ httpCode, auth }) => {
      const pending = deferred<Type<unknown>>()
      const page = trackedPage('lazy-page')
      const surface = trackedPage('auth-surface')
      const { router, answer, pageError } = controlledRouter()
      answer()
      const fixture = mountPages(
        router,
        { main: MainPage, lazy: lazyPage(() => pending.promise) },
        { authSurface: auth ? surface.component : undefined },
      )
      router.navigate('/lazy')
      fixture.detectChanges()
      pageError.set({
        ...NOT_FOUND,
        page: 'lazy',
        httpCode,
        title: 'Server title',
        message: 'Server refusal words',
      })
      fixture.detectChanges()
      expect(find(fixture, 'hilos-page-departure')!.childElementCount).toBe(0)
      expect(marker(fixture)).toBe('error')
      expect(find(fixture, 'auth-surface') !== null).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(
          find(fixture, 'page-error')?.getAttribute('data-error-code'),
        ).toBe(String(httpCode))
        expect(find(fixture, 'page-error')?.textContent).toContain(
          'Server refusal words',
        )
      }
      pending.reject(new Error('Chunk failed'))
      await settleCode(fixture)
      outlast(fixture, DEFAULT_SKELETON_DELAY_MS)
      expect(page.mounted).not.toHaveBeenCalled()
      expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
      expect(find(fixture, 'auth-surface') !== null).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(find(fixture, 'page-error')?.textContent).toContain(
          'Server title',
        )
        expect(find(fixture, 'page-error')?.textContent).toContain(
          'Server refusal words',
        )
      }
    },
  )

  it('keeps resolved code gated by 401 and reuses it after authentication', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const surface = trackedPage('auth-surface')
    const loader = vi.fn(() => pending.promise)
    const { router, pageError, pageLoading, answer } = controlledRouter('/lazy')
    const currentUserId = createSignal<number | null>(null)
    const gate = createAuthGate({ router, currentUserId })
    const fixture = mountPages(
      router,
      { lazy: lazyPage(loader) },
      { authSurface: surface.component, authGate: gate },
    )
    const root = fixture.nativeElement as HTMLElement
    expect(surface.mounted).not.toHaveBeenCalled()

    pageError.set({ ...NOT_FOUND, page: 'lazy', httpCode: 401 })
    pageLoading.set(false)
    gate.requireAuth()
    fixture.detectChanges()
    pending.resolve(page.component)
    await settleCode(fixture)
    expect(root.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(1)
    expect(find(fixture, 'page-error')).toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(root.querySelector('[role="dialog"]')).toBeNull()

    router.awaitPageAnswer()
    currentUserId.set(42)
    fixture.detectChanges()
    expect(page.mounted).not.toHaveBeenCalled()
    answer()
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(find(fixture, 'auth-surface')).toBeNull()
    expect(gate.modalOpen.get()).toBe(false)
  })

  it('mounts projected auth content only while the action modal is open', () => {
    const surface = trackedPage('auth-surface')
    const { router, answer } = controlledRouter()
    answer()
    const gate = createAuthGate({
      router,
      currentUserId: createSignal<number | null>(null),
    })
    const fixture = mountPages(
      router,
      { main: MainPage },
      { authSurface: surface.component, authGate: gate },
    )
    expect(surface.mounted).not.toHaveBeenCalled()
    expect(find(fixture, 'auth-surface')).toBeNull()

    gate.requireAuth()
    fixture.detectChanges()
    expect(surface.mounted).toHaveBeenCalledTimes(1)
    expect(find(fixture, 'auth-surface')).not.toBeNull()
    expect(find(fixture, 'main')).not.toBeNull()
    gate.dismiss()
    fixture.detectChanges()
    expect(find(fixture, 'auth-surface')).toBeNull()
    gate.requireAuth()
    fixture.detectChanges()
    expect(surface.mounted).toHaveBeenCalledTimes(2)
  })

  it('lets a later server refusal replace the local load error', async () => {
    const pending = deferred<Type<unknown>>()
    const { router, pageError } = controlledRouter('/lazy')
    const fixture = mountPages(router, {
      lazy: lazyPage(() => pending.promise),
    })
    pending.reject(new Error('Chunk failed'))
    await settleCode(fixture)
    expect(find(fixture, 'page-error')?.textContent).toContain(
      'Page could not be loaded',
    )
    pageError.set({ ...NOT_FOUND, page: 'lazy', httpCode: 403 })
    fixture.detectChanges()
    expect(find(fixture, 'page-error')?.getAttribute('data-error-code')).toBe(
      '403',
    )
    expect(find(fixture, 'page-error')?.textContent).toContain('Not found')
  })

  it('replaces a descriptor at the same key and ignores the old resolution', async () => {
    const old = deferred<Type<unknown>>()
    const replacement = deferred<Type<unknown>>()
    const stale = trackedPage('stale-page')
    const current = trackedPage('current-page')
    const firstLoader = vi.fn(() => old.promise)
    const nextLoader = vi.fn(() => replacement.promise)
    const { router, answer } = controlledRouter('/lazy')
    const fixture = mountPages(router, { lazy: lazyPage(firstLoader) })
    answer()
    fixture.detectChanges()
    outlast(fixture, 100)
    fixture.componentRef.setInput('pages', { lazy: lazyPage(nextLoader) })
    fixture.detectChanges()
    old.resolve(stale.component)
    await settleCode(fixture)
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(firstLoader).toHaveBeenCalledTimes(1)
    expect(nextLoader).toHaveBeenCalledTimes(1)
    outlast(fixture, DEFAULT_SKELETON_DELAY_MS - 100)
    expect(find(fixture, 'hilos-page-skeleton')).not.toBeNull()
    replacement.resolve(current.component)
    await settleCode(fixture)
    expect(current.mounted).toHaveBeenCalledTimes(1)

    fixture.componentRef.setInput('pages', { lazy: KnownPage })
    fixture.detectChanges()
    expect(find(fixture, 'known-page')).not.toBeNull()
    fixture.componentRef.setInput('pages', {})
    fixture.detectChanges()
    expect(find(fixture, 'known-page')).toBeNull()
    expect(marker(fixture)).toBe('ready')
  })

  it('keeps an in-flight descriptor across map updates and releases its cache on destroy', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = controlledRouter('/lazy')
    const fixture = mountPages(router, { lazy: descriptor })
    fixture.componentRef.setInput('pages', { lazy: descriptor, main: MainPage })
    fixture.detectChanges()
    expect(loader).toHaveBeenCalledTimes(1)
    pending.resolve(page.component)
    answer()
    await settleCode(fixture)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    fixture.destroy()

    const next = TestBed.createComponent(HilosView)
    next.componentRef.setInput('pages', { lazy: descriptor })
    next.detectChanges()
    expect(loader).toHaveBeenCalledTimes(2)
    await settleCode(next)
    expect(page.mounted).toHaveBeenCalledTimes(2)
  })

  it.each(['resolve', 'reject'] as const)(
    'does not replace a ready eager page with a departed import %s',
    async (outcome) => {
      const pending = deferred<Type<unknown>>()
      const stale = trackedPage('stale-page')
      const { router, answer } = controlledRouter('/lazy')
      const fixture = mountPages(router, {
        lazy: lazyPage(() => pending.promise),
        main: MainPage,
      })
      router.navigate('/')
      answer()
      fixture.detectChanges()
      expect(find(fixture, 'main')).not.toBeNull()
      if (outcome === 'resolve') {
        pending.resolve(stale.component)
      } else {
        pending.reject(new Error('Departed chunk failed'))
      }
      await settleCode(fixture)
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(find(fixture, 'main')).not.toBeNull()
      expect(find(fixture, 'page-error')).toBeNull()
      expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
      expect(marker(fixture)).toBe('ready')
    },
  )

  it('reuses code across maps and params while waiting for each server answer', async () => {
    const pending = deferred<Type<unknown>>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = controlledRouter('/lazy/1')
    const fixture = mountPages(router, { lazy: descriptor })
    pending.resolve(page.component)
    answer()
    await settleCode(fixture)
    fixture.componentRef.setInput('pages', { lazy: descriptor, main: MainPage })
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(1)

    router.navigate('/lazy/2')
    fixture.detectChanges()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '.hilos-page-slot [data-id="lazy-page"]',
      ),
    ).toBeNull()
    expect(marker(fixture)).toBe('loading')
    answer()
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(2)
    router.navigate('/')
    answer()
    fixture.detectChanges()
    router.navigate('/lazy/3')
    answer()
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(3)
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it.each(['resolve', 'reject'] as const)(
    'disposes listeners and timers before a late %s',
    async (outcome) => {
      const pending = deferred<Type<unknown>>()
      const page = trackedPage('lazy-page')
      const otherLoader = vi.fn(() => Promise.resolve(KnownPage))
      const { router, answer } = controlledRouter()
      answer()
      const fixture = mountPages(router, {
        main: MainPage,
        lazy: lazyPage(() => pending.promise),
        other: lazyPage(otherLoader),
      })
      router.navigate('/lazy')
      fixture.detectChanges()
      const copy = find(fixture, 'hilos-page-departure')!
      expect(copy.childElementCount).toBe(1)
      fixture.destroy()
      expect(copy.childElementCount).toBe(0)
      expect(vi.getTimerCount()).toBe(0)
      if (outcome === 'resolve') {
        pending.resolve(page.component)
      } else {
        pending.reject(new Error('Disposed chunk failed'))
      }
      router.navigate('/other')
      answer()
      await Promise.resolve()
      expect(otherLoader).not.toHaveBeenCalled()
      expect(page.mounted).not.toHaveBeenCalled()
      expect(copy.childElementCount).toBe(0)
      expect(vi.getTimerCount()).toBe(0)
    },
  )

  it.each(['unbuilt', 'unknown', 'refused'] as const)(
    'does not start a loader for a known %s page',
    async (kind) => {
      const loader = vi.fn(() => Promise.resolve(KnownPage))
      const { router, pageError } = controlledRouter(
        kind === 'unknown' ? '/unknown' : '/lazy',
        new Set(kind === 'unbuilt' ? ['lazy'] : []),
      )
      if (kind === 'refused') {
        pageError.set({ ...NOT_FOUND, page: 'lazy' })
      }
      const fixture = mountPages(router, { lazy: lazyPage(loader) })
      await settleCode(fixture)
      expect(loader).not.toHaveBeenCalled()
      expect(find(fixture, 'page-error')).not.toBeNull()
      expect(find(fixture, 'hilos-page-skeleton')).toBeNull()
    },
  )
})

it('mounts a project override without running the default admin descriptor', () => {
  const defaults = hilosAdminViews()
  const descriptor = defaults[HilosPages.DASHBOARD] as LazyPageView<
    Type<unknown>
  >
  const load = vi.spyOn(descriptor, 'load')
  const { router, pageLoading } = waitingRouter(HilosPages.DASHBOARD)
  pageLoading.set(false)
  const fixture = mountPages(router, {
    ...defaults,
    [HilosPages.DASHBOARD]: KnownPage,
  })
  expect(find(fixture, 'known-page')).not.toBeNull()
  expect(load).not.toHaveBeenCalled()
})

/** The ordinary 404 of a card whose resource no longer exists. */
const CARD_NOT_FOUND: PageSubscriptionError = {
  page: 'lazy',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** The live 404 of a card whose resource was deleted while it stood open. */
const CARD_DELETED: PageSubscriptionError = {
  ...CARD_NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

const CARD_IDENTITY: HilosPageIdentity = {
  label: 'Poland',
  lead: 'One country.',
  breadcrumb: [{ page: 'lazy', label: 'Poland' }],
  children: [],
}

/**
 * A card on the admin shell with one dialog in #pageModals, opened from a
 * button in its #pageBody. The draft is the card's own state, so it lives
 * exactly as long as the card's instance does.
 *
 * @param preserve Whether the card opts in.
 */
function shellPage(preserve: boolean) {
  const mounted = vi.fn()
  @Component({
    selector: 'test-card-page',
    imports: [HilosAdminPage, HilosModal],
    template: `
      <hilos-admin-page page="lazy" [preserveModalDraftsOnDelete]="preserve">
        <ng-template #pageBody>
          <p data-id="page-body">The card</p>
          <button type="button" data-id="open-draft" (click)="open.set(true)">
            Rename
          </button>
        </ng-template>
        <ng-template #pageModals>
          <hilos-modal [(open)]="open" title="Rename">
            <input
              data-id="draft"
              data-autofocus
              [value]="draft()"
              (input)="onDraft($event)"
            />
          </hilos-modal>
        </ng-template>
      </hilos-admin-page>
    `,
  })
  class Card {
    protected readonly preserve = preserve
    protected readonly open = signal(false)
    protected readonly draft = signal('')

    constructor() {
      mounted()
    }

    protected onDraft(event: Event): void {
      this.draft.set((event.target as HTMLInputElement).value)
    }
  }

  return { component: Card, mounted }
}

describe('HilosView and a page shell under a live deletion', () => {
  afterEach(() => {
    TestBed.resetTestingModule()
    document.body.innerHTML = ''
  })

  /** Every page error drawn anywhere in the document. */
  function pageErrors(): NodeListOf<Element> {
    return document.querySelectorAll('[data-id="page-error"]')
  }

  function draftInput(): HTMLInputElement | null {
    return document.querySelector<HTMLInputElement>('[data-id="draft"]')
  }

  /**
   * Open the card, its dialog from the card's button, and type a draft.
   *
   * @param preserve Whether the card opts in.
   * @param kind Whether the outlet maps the card eagerly or behind a loader.
   */
  async function openCard(preserve: boolean, kind: 'eager' | 'lazy' = 'eager') {
    const page = shellPage(preserve)
    const harness = controlledRouter('/lazy/1')
    harness.pageIdentity.set(CARD_IDENTITY)
    const fixture = mountPages(harness.router, {
      main: KnownPage,
      lazy:
        kind === 'eager'
          ? page.component
          : lazyPage(() => Promise.resolve(page.component)),
    })
    harness.answer()
    await settleCode(fixture)
    const opener = find(fixture, 'open-draft') as HTMLElement
    opener.focus()
    opener.click()
    fixture.detectChanges()
    const draft = draftInput()!
    draft.value = 'my draft'
    draft.dispatchEvent(new Event('input'))
    fixture.detectChanges()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(draft.value).toBe('my draft')
    expect(document.activeElement).toBe(draft)
    const fail = (error: PageSubscriptionError) => {
      harness.pageError.set(error)
      fixture.detectChanges()
    }

    return { ...harness, fixture, page, draft, fail }
  }

  it.each(['eager', 'lazy'] as const)(
    'keeps the %s card, its dialog and the draft while the shell draws the one error',
    async (kind) => {
      const { fixture, fail, page, draft } = await openCard(true, kind)
      expect(find(fixture, 'hilos-admin-title')?.textContent?.trim()).toBe(
        'Poland',
      )

      fail(CARD_DELETED)

      expect(page.mounted).toHaveBeenCalledTimes(1)
      expect(draftInput()).toBe(draft)
      expect(draft.value).toBe('my draft')
      expect(pageErrors()).toHaveLength(1)
      const shell = find(fixture, 'hilos-admin-page')
      expect(shell?.contains(pageErrors()[0])).toBe(true)
      expect(
        shell
          ?.querySelector('[data-id="page-error-transition-message"]')
          ?.textContent?.trim(),
      ).toBe('It was deleted just now.')
      for (const gone of [
        'hilos-breadcrumb',
        'hilos-admin-title',
        'page-body',
        'open-draft',
      ]) {
        expect(find(fixture, gone)).toBeNull()
      }
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(marker(fixture)).toBe('error')
    },
  )

  it('keeps the hold through a plain 404 that brings no line', async () => {
    const { fixture, fail, page, draft } = await openCard(true)
    fail(CARD_DELETED)

    fail({ ...CARD_NOT_FOUND })

    expect(find(fixture, 'page-error-transition-message')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(find(fixture, 'hilos-admin-page')?.contains(pageErrors()[0])).toBe(
      true,
    )
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(draftInput()).toBe(draft)
    expect(draft.value).toBe('my draft')
  })

  it.each([
    { httpCode: 401, errorCode: 'unauthorized' },
    { httpCode: 403, errorCode: 'forbidden' },
    { httpCode: 503, errorCode: 'unavailable' },
  ])(
    'takes the held card and its dialog away on a $httpCode',
    async ({ httpCode, errorCode }) => {
      const { fixture, fail } = await openCard(true)
      fail(CARD_DELETED)

      fail({
        ...CARD_NOT_FOUND,
        httpCode,
        errorCode,
        title: 'Refused',
        message: 'Server refusal words',
      })

      expect(find(fixture, 'hilos-admin-page')).toBeNull()
      expect(find(fixture, 'modal')).toBeNull()
      expect(pageErrors()).toHaveLength(1)
      expect(pageErrors()[0].getAttribute('data-error-code')).toBe(
        String(httpCode),
      )
      expect((fixture.nativeElement as HTMLElement).textContent).toContain(
        'Server refusal words',
      )
    },
  )

  it('takes the held card and its dialog away on navigation', async () => {
    const { fixture, fail, answer } = await openCard(true)
    fail(CARD_DELETED)

    ;(find(fixture, 'page-error-home') as HTMLElement).click()
    answer()
    fixture.detectChanges()

    expect(find(fixture, 'known-page')).not.toBeNull()
    expect(find(fixture, 'hilos-admin-page')).toBeNull()
    expect(find(fixture, 'modal')).toBeNull()
    expect(pageErrors()).toHaveLength(0)
  })

  it('replaces a card that did not opt in, line and all', async () => {
    const { fixture, fail, page } = await openCard(false)

    fail(CARD_DELETED)

    expect(find(fixture, 'hilos-admin-page')).toBeNull()
    expect(find(fixture, 'modal')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(
      find(fixture, 'page-error-transition-message')?.textContent?.trim(),
    ).toBe('It was deleted just now.')
    expect(page.mounted).toHaveBeenCalledTimes(1)
  })

  it('never mounts an opted-in card that is refused before it is shown', async () => {
    const page = shellPage(true)
    const { router, pageError, pageLoading } = controlledRouter('/lazy/1')
    pageError.set(CARD_DELETED)
    pageLoading.set(false)
    const fixture = mountPages(router, { lazy: page.component })
    await settleCode(fixture)

    expect(page.mounted).not.toHaveBeenCalled()
    expect(find(fixture, 'hilos-admin-page')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
  })

  it('leaves focus in the open dialog and returns it to Back to home on close', async () => {
    const { fixture, fail, draft } = await openCard(true)

    fail(CARD_DELETED)
    expect(document.activeElement).toBe(draft)

    ;(find(fixture, 'modal-close') as HTMLElement).click()
    fixture.detectChanges()

    expect(find(fixture, 'modal')).toBeNull()
    expect(document.activeElement).toBe(find(fixture, 'page-error-home'))
  })
})
