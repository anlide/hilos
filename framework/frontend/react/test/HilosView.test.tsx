// The two parity fixes HIL-424 brought to the React outlet, each with no guard
// before this spec: the gate's modal must not draw a SECOND copy of the sign-in
// surface while the same surface already stands in place of a 401'd page, and
// the dialog must name itself without hard-coding a visible title the
// identifier-first surface owns itself — and since HIL-832 that name is the
// heading the surface draws, not a fixed string of the frame's own.
//
// And the outlet while a page waits for its first answer (HIL-983): a skeleton in
// the page's place instead of white, never before the delay, gone on the answer,
// and never over a refusal.
//
// And a page shell that draws the deletion of its own resource (HIL-1603): the
// outlet keeps the opted-in page mounted, its open dialog and draft with it,
// while the shell draws the one ErrorPage in place of its content.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode, useEffect, useState } from 'react'
import type { ComponentType } from 'react'
import {
  AUTH_SURFACE_HEADING_ID,
  bindAccessLoss,
  bindPageScope,
  bindSessionScope,
  createAuthGate,
  createHilosRouter,
  createPageRouter,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  lazyPage,
  HilosPages,
  ScopeManager,
  sessionUserId,
} from '@hilos/core'
import { hilosAdminViews } from '@hilos/react/admin-views'
import type {
  AuthGate,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageRouteMatch,
  PageSubscriptionError,
  PageView,
  ProjectSignal,
  LazyPageView,
  WritableSignal,
} from '@hilos/core'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView, type HilosViewProps } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const UNAUTHORIZED: PageSubscriptionError = {
  page: 'user',
  httpCode: 401,
  errorCode: 'unauthorized',
  title: 'Unauthorized',
  message: 'Authentication required',
}

const PAGES = { user: () => <div data-id="user-page">Leaving page</div> }

function AuthSurface() {
  return <div data-id="auth-surface" />
}

// The real surface names itself with a heading it owns; this one stands in for
// it, id and all, so the frame has something to point at.
function HeadedAuthSurface() {
  return (
    <div data-id="auth-surface">
      <h2 id={AUTH_SURFACE_HEADING_ID}>Confirm your email</h2>
    </div>
  )
}

interface DepartureRouter extends HilosRouter {
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
  depart(): void
}

function routerWith(pageError: PageSubscriptionError | null): DepartureRouter {
  const pageLoading = createSignal(false)
  const pageErrorSignal = createSignal<PageSubscriptionError | null>(pageError)
  const leaveListeners = new Set<() => void>()
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'user',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: pageErrorSignal,
    pageLoading,
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => '/user',
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaveListeners.add(listener)
      return () => {
        leaveListeners.delete(listener)
      }
    },
    depart: () => {
      for (const listener of [...leaveListeners]) {
        listener()
      }
      pageLoading.set(true)
    },
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function fakeAuthGate(open: boolean): AuthGate {
  return {
    modalOpen: createSignal(open),
    requireAuth: () => {},
    dismiss: () => {},
  }
}

// The modal portals to <body>, so assertions query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

describe('HilosView auth modal', () => {
  it('draws no modal while the surface already stands in place of a 401', () => {
    // The gate opens the modal for an owed ack as well as for a gated action
    // (HIL-422), and that can happen on a page already showing the surface.
    render(
      <HilosRouterContext.Provider value={routerWith(UNAUTHORIZED)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate(true)}
        />
      </HilosRouterContext.Provider>,
    )

    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('names the dialog with the heading the surface draws', () => {
    render(
      <HilosRouterContext.Provider value={routerWith(null)}>
        <HilosView
          pages={PAGES}
          authSurface={HeadedAuthSurface}
          authGate={fakeAuthGate(true)}
        />
      </HilosRouterContext.Provider>,
    )

    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-labelledby')).toBe(
      AUTH_SURFACE_HEADING_ID,
    )
    expect(document.getElementById(AUTH_SURFACE_HEADING_ID)?.textContent).toBe(
      'Confirm your email',
    )
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
    expect(dialog?.querySelector('.modal-title')).toBeNull()
  })

  it('keeps the Sign in name for a surface that carries no heading', () => {
    render(
      <HilosRouterContext.Provider value={routerWith(null)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate(true)}
        />
      </HilosRouterContext.Provider>,
    )

    // Nothing carries the id, so aria-labelledby resolves to nothing and the
    // accessible name falls through to the label the frame keeps as a safety
    // net for a project surface of its own.
    expect(document.getElementById(AUTH_SURFACE_HEADING_ID)).toBeNull()
    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
    expect(dialog?.querySelector('.modal-title')).toBeNull()
  })
})

const NOT_FOUND: PageSubscriptionError = {
  page: 'user',
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'Not found',
}

function UserSkeleton() {
  return <div data-id="user-skeleton" />
}

interface WaitingRouter {
  router: HilosRouter
  pageLoading: WritableSignal<boolean>
  pageError: WritableSignal<PageSubscriptionError | null>
}

/** A router whose page is still waiting for its first answer. */
function waitingRouter(page: string): WaitingRouter {
  const pageLoading = createSignal(true)
  const pageError = createSignal<PageSubscriptionError | null>(null)

  return {
    pageLoading,
    pageError,
    router: {
      ...routerWith(null),
      currentRoute: createSignal<PageRouteMatch>({
        page,
        params: {},
        admin: false,
      }),
      pageError,
      pageLoading,
    },
  }
}

function renderView(router: HilosRouter, withSkeletons = false) {
  render(
    <HilosRouterContext.Provider value={router}>
      <HilosView
        pages={PAGES}
        pageSkeletons={withSkeletons ? { user: UserSkeleton } : undefined}
      />
    </HilosRouterContext.Provider>,
  )
}

function outlast(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

function pageSkeleton(): Element | null {
  return document.querySelector('[data-id="hilos-page-skeleton"]')
}

describe('HilosView skeleton', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('draws nothing in the first moments of the wait', () => {
    renderView(waitingRouter('user').router)

    outlast(DEFAULT_SKELETON_DELAY_MS - 1)
    expect(pageSkeleton()).toBeNull()
  })

  it('draws the default skeleton once the wait outlasts the delay', () => {
    renderView(waitingRouter('user').router)

    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(
      pageSkeleton()?.querySelector('[data-id="hilos-skeleton"]'),
    ).not.toBeNull()
  })

  it('announces the page skeleton once, whatever it draws', () => {
    renderView(waitingRouter('user').router)

    outlast(DEFAULT_SKELETON_DELAY_MS)
    const status = document.querySelectorAll('[role="status"]')
    expect(status).toHaveLength(2)
    expect(status[0].textContent).toBe('')
    expect(status[1].textContent).toBe('Loading…')
  })

  it('gives way to the page on its answer', () => {
    const { router, pageLoading } = waitingRouter('user')
    renderView(router)

    outlast(DEFAULT_SKELETON_DELAY_MS)
    act(() => {
      pageLoading.set(false)
    })
    expect(pageSkeleton()).toBeNull()
    expect(document.querySelector('[data-id="user-page"]')).not.toBeNull()
  })

  it('draws the skeleton the project declared for the page key', () => {
    renderView(waitingRouter('user').router, true)

    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(document.querySelector('[data-id="user-skeleton"]')).not.toBeNull()
    expect(document.querySelector('[data-id="hilos-skeleton"]')).toBeNull()
  })

  it('falls back to the default skeleton for an undeclared page key', () => {
    renderView(waitingRouter('settings').router, true)

    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(document.querySelector('[data-id="user-skeleton"]')).toBeNull()
    expect(document.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
  })

  it('shows the refusal, not a skeleton, when the page is denied', () => {
    const { router, pageError } = waitingRouter('user')
    renderView(router)

    act(() => {
      pageError.set(NOT_FOUND)
    })
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(pageSkeleton()).toBeNull()
    expect(document.querySelector('[data-id="page-error"]')).not.toBeNull()
  })

  it('keeps the page-state marker going from loading to ready', () => {
    const { router, pageLoading } = waitingRouter('user')
    renderView(router)
    const marker = () =>
      document
        .querySelector('[data-id="hilos-page-state"]')
        ?.getAttribute('data-state')

    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(marker()).toBe('loading')
    act(() => {
      pageLoading.set(false)
    })
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
    const router = routerWith(null)
    renderView(router)

    act(() => router.depart())
    const departure = document.querySelector(
      '[data-id="hilos-page-departure"]',
    )!
    expect(departure.getAttribute('aria-hidden')).toBe('true')
    expect(departure.hasAttribute('inert')).toBe(true)
    expect(departure.textContent).toBe('Leaving page')
    expect(departure.querySelector('[data-id]')).toBeNull()

    act(() => router.pageLoading.set(false))
    expect(departure.childElementCount).toBe(0)
  })

  it('removes the copy on an error or the skeleton threshold', () => {
    const router = routerWith(null)
    renderView(router)
    const departure = document.querySelector(
      '[data-id="hilos-page-departure"]',
    )!

    act(() => router.depart())
    act(() => router.pageError.set(NOT_FOUND))
    expect(departure.childElementCount).toBe(0)

    act(() => {
      router.pageError.set(null)
      router.pageLoading.set(false)
    })
    act(() => router.depart())
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(pageSkeleton()).not.toBeNull()
    expect(departure.childElementCount).toBe(0)
  })

  it('does not copy a loading change and keeps a copy through repeated departures', () => {
    const router = routerWith(null)
    renderView(router)
    const departure = document.querySelector(
      '[data-id="hilos-page-departure"]',
    )!

    act(() => router.pageLoading.set(true))
    expect(departure.childElementCount).toBe(0)

    act(() => router.pageLoading.set(false))
    act(() => router.depart())
    const copied = departure.firstElementChild
    act(() => router.depart())
    expect(departure.firstElementChild).toBe(copied)
  })
})

function KnownPage() {
  return <div data-id="known-page">Known</div>
}

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
  afterEach(() => {
    cleanup()
  })

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
    const { container } = render(
      <HilosRouterContext.Provider value={navigator}>
        <HilosView pages={{ main: PAGES.user, known: KnownPage }} />
      </HilosRouterContext.Provider>,
    )
    // PAGES.user is the home component; its marker is user-page.
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()

    act(() => navigator.navigate('/nope'))

    const surface = container.querySelector('[data-id="page-error"]')
    expect(
      container
        .querySelector('[data-id="hilos-page-state"]')
        ?.getAttribute('data-state'),
    ).toBe('error')
    expect(surface).not.toBeNull()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(surface?.querySelector('h1')?.textContent).toBe('404')
    expect(surface?.textContent).toContain('Page Not Found')
    expect(surface?.textContent).toContain(
      'The page you are looking for does not exist.',
    )
    expect(container.querySelector('[data-id="known-page"]')).toBeNull()
    expect(container.querySelector('[data-id="user-page"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-skeleton"]'),
    ).toBeNull()

    act(() => {
      fireEvent.click(surface!.querySelector('[data-id="page-error-home"]')!)
    })

    expect(container.querySelector('[data-id="user-page"]')).not.toBeNull()
    expect(container.querySelector('[data-id="page-error"]')).toBeNull()
    expect(
      container
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
  function Component() {
    useEffect(() => {
      mounted()
    }, [])
    return <div data-id={id}>{id}</div>
  }
  return { component: Component, mounted }
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

function renderPages(
  router: HilosRouter,
  pages: Record<string, PageView<ComponentType>>,
  extra: Omit<HilosViewProps, 'pages'> = {},
) {
  return render(<HilosView pages={pages} {...extra} />, {
    wrapper: ({ children }) => (
      <HilosRouterContext.Provider value={router}>
        {children}
      </HilosRouterContext.Provider>
    ),
  })
}

function marker(): string | null | undefined {
  return document
    .querySelector('[data-id="hilos-page-state"]')
    ?.getAttribute('data-state')
}

function departure(): Element {
  return document.querySelector('[data-id="hilos-page-departure"]')!
}

async function settleCode(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

describe('HilosView lazy pages', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('keeps the departure and original skeleton deadline after a fast server answer', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const { router, answer, pageLoading } = controlledRouter()
    answer()
    renderPages(
      router,
      { main: PAGES.user, lazy: lazyPage(loader) },
      {
        pageSkeletons: { lazy: UserSkeleton },
      },
    )
    const copy = departure()
    const frame = copy.parentElement!
    frame.scrollTop = 90

    act(() => router.navigate('/lazy'))
    outlast(100)
    act(answer)
    expect(pageLoading.get()).toBe(false)
    expect(copy.textContent).toBe('Leaving page')
    expect(copy.getAttribute('aria-hidden')).toBe('true')
    expect(copy.hasAttribute('inert')).toBe(true)
    expect(copy.querySelector('[data-id]')).toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(marker()).toBe('loading')

    outlast(DEFAULT_SKELETON_DELAY_MS - 101)
    expect(copy.textContent).toBe('Leaving page')
    expect(pageSkeleton()).toBeNull()
    outlast(1)
    expect(copy.childElementCount).toBe(0)
    expect(frame.scrollTop).toBe(0)
    expect(document.querySelector('[data-id="user-skeleton"]')).not.toBeNull()
    const status = document.querySelectorAll('[role="status"]')
    expect(status).toHaveLength(2)
    expect(status[0].textContent).toBe('')
    expect(status[1].textContent).toBe('Loading…')

    pending.resolve(page.component)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(document.querySelector('[data-id="lazy-page"]')).not.toBeNull()
    expect(pageSkeleton()).toBeNull()
    expect(marker()).toBe('ready')
  })

  it('does not mount fast code before the server answers or restart its skeleton timer', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const { router, answer } = controlledRouter('/lazy')
    renderPages(router, { lazy: lazyPage(() => pending.promise) })

    outlast(100)
    pending.resolve(page.component)
    await settleCode()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(marker()).toBe('loading')
    outlast(DEFAULT_SKELETON_DELAY_MS - 101)
    expect(pageSkeleton()).toBeNull()
    outlast(1)
    expect(pageSkeleton()).not.toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()

    act(answer)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(marker()).toBe('ready')
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(1)
    expect(document.querySelector('[role="status"]')?.textContent).toBe('')
  })

  it.each(['resolve', 'reject'] as const)(
    'keeps one deadline through a second navigation and ignores a late %s',
    async (outcome) => {
      const first = deferred<ComponentType>()
      const second = deferred<ComponentType>()
      const stale = trackedPage('stale-page')
      const current = trackedPage('current-page')
      const { router, answer } = controlledRouter()
      answer()
      renderPages(router, {
        main: PAGES.user,
        lazy: lazyPage(() => first.promise),
        other: lazyPage(() => second.promise),
      })

      act(() => router.navigate('/lazy'))
      outlast(200)
      const copied = departure().firstElementChild
      act(() => router.navigate('/other'))
      act(answer)
      expect(departure().firstElementChild).toBe(copied)
      expect(departure().textContent).toBe('Leaving page')
      outlast(DEFAULT_SKELETON_DELAY_MS - 201)
      expect(pageSkeleton()).toBeNull()
      outlast(1)
      expect(pageSkeleton()).not.toBeNull()
      expect(departure().childElementCount).toBe(0)

      if (outcome === 'resolve') {
        first.resolve(stale.component)
      } else {
        first.reject(new Error('Old chunk failed'))
      }
      await settleCode()
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(current.mounted).not.toHaveBeenCalled()
      expect(document.querySelector('[data-id="page-error"]')).toBeNull()
      expect(marker()).toBe('loading')
      second.resolve(current.component)
      await settleCode()
      expect(current.mounted).toHaveBeenCalledTimes(1)
      expect(marker()).toBe('ready')
    },
  )

  it('removes the departure when code and data are ready without flashing a skeleton', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const { router, answer } = controlledRouter()
    answer()
    renderPages(router, {
      main: PAGES.user,
      lazy: lazyPage(() => pending.promise),
    })

    act(() => router.navigate('/lazy'))
    outlast(100)
    act(answer)
    pending.resolve(page.component)
    await settleCode()
    expect(departure().childElementCount).toBe(0)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(pageSkeleton()).toBeNull()
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(1)
    expect(document.querySelector('[role="status"]')?.textContent).toBe('')
  })

  it('shows a safe local error, returns home by SPA navigation and retries only on revisit', async () => {
    const first = deferred<ComponentType>()
    const retry = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const loader = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(retry.promise)
    const { router, answer, subscribe } = controlledRouter()
    answer()
    const { container } = renderPages(router, {
      main: PAGES.user,
      lazy: lazyPage(loader),
    })

    act(() => router.navigate('/lazy'))
    first.reject(new Error('secret engine message /assets/chunk.js'))
    await settleCode()
    const surface = container.querySelector('[data-id="page-error"]')
    expect(surface?.getAttribute('data-error-code')).toBe('503')
    expect(surface?.querySelector('.lead')?.textContent).toBe(
      'Page could not be loaded',
    )
    expect(surface?.querySelector('p.text-body-secondary')?.textContent).toBe(
      'Please try opening this page again.',
    )
    expect(container.textContent).not.toContain('secret engine message')
    expect(container.textContent).not.toContain('/assets/chunk.js')
    expect(marker()).toBe('error')
    expect(departure().childElementCount).toBe(0)
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(pageSkeleton()).toBeNull()

    fireEvent.click(surface!.querySelector('[data-id="page-error-home"]')!)
    expect(router.currentPath.get()).toBe('/')
    expect(subscribe).toHaveBeenLastCalledWith('main', {})
    act(answer)
    expect(container.querySelector('[data-id="user-page"]')).not.toBeNull()
    act(() => router.navigate('/lazy'))
    expect(loader).toHaveBeenCalledTimes(2)
    act(answer)
    retry.resolve(page.component)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(container.querySelector('[data-id="page-error"]')).toBeNull()
  })

  it.each([
    { httpCode: 401, auth: true },
    { httpCode: 401, auth: false },
    { httpCode: 403, auth: true },
    { httpCode: 404, auth: true },
  ])(
    'shows the server refusal immediately ($httpCode, auth=$auth) over a late import failure',
    async ({ httpCode, auth }) => {
      const pending = deferred<ComponentType>()
      const page = trackedPage('lazy-page')
      const { router, answer, pageError } = controlledRouter()
      answer()
      const { container } = renderPages(
        router,
        {
          main: PAGES.user,
          lazy: lazyPage(() => pending.promise),
        },
        { authSurface: auth ? AuthSurface : undefined },
      )
      act(() => router.navigate('/lazy'))
      act(() =>
        pageError.set({
          ...NOT_FOUND,
          page: 'lazy',
          httpCode,
          title: 'Server title',
          message: 'Server refusal words',
        }),
      )
      expect(departure().childElementCount).toBe(0)
      expect(marker()).toBe('error')
      expect(container.querySelector('[data-id="auth-surface"]') !== null).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(
          container
            .querySelector('[data-id="page-error"]')
            ?.getAttribute('data-error-code'),
        ).toBe(String(httpCode))
        expect(container.textContent).toContain('Server refusal words')
      }
      pending.reject(new Error('Chunk failed'))
      await settleCode()
      outlast(DEFAULT_SKELETON_DELAY_MS)
      expect(page.mounted).not.toHaveBeenCalled()
      expect(pageSkeleton()).toBeNull()
      expect(container.querySelector('[data-id="auth-surface"]') !== null).toBe(
        httpCode === 401 && auth,
      )
      if (httpCode !== 401 || !auth) {
        expect(container.textContent).toContain('Server title')
        expect(container.textContent).toContain('Server refusal words')
      }
    },
  )

  it('keeps resolved code gated by 401 and reuses it after authentication', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const { router, pageError, pageLoading, answer } = controlledRouter('/lazy')
    const currentUserId = createSignal<number | null>(null)
    const gate = createAuthGate({ router, currentUserId })
    renderPages(
      router,
      { lazy: lazyPage(loader) },
      { authSurface: AuthSurface, authGate: gate },
    )

    act(() => {
      pageError.set({ ...NOT_FOUND, page: 'lazy', httpCode: 401 })
      pageLoading.set(false)
      gate.requireAuth()
    })
    pending.resolve(page.component)
    await settleCode()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.querySelector('[data-id="page-error"]')).toBeNull()
    expect(page.mounted).not.toHaveBeenCalled()
    expect(document.querySelector('[role="dialog"]')).toBeNull()

    act(() => {
      router.awaitPageAnswer()
      currentUserId.set(42)
    })
    expect(page.mounted).not.toHaveBeenCalled()
    act(answer)
    expect(page.mounted).toHaveBeenCalledTimes(1)
    expect(loader).toHaveBeenCalledTimes(1)
    expect(document.querySelector('[data-id="auth-surface"]')).toBeNull()
    expect(gate.modalOpen.get()).toBe(false)
  })

  it('lets a later server refusal replace the local load error', async () => {
    const pending = deferred<ComponentType>()
    const { router, pageError } = controlledRouter('/lazy')
    const { container } = renderPages(router, {
      lazy: lazyPage(() => pending.promise),
    })
    pending.reject(new Error('Chunk failed'))
    await settleCode()
    expect(container.textContent).toContain('Page could not be loaded')
    act(() => pageError.set({ ...NOT_FOUND, page: 'lazy', httpCode: 403 }))
    expect(
      container
        .querySelector('[data-id="page-error"]')
        ?.getAttribute('data-error-code'),
    ).toBe('403')
    expect(container.textContent).toContain('Not found')
  })

  it('replaces a descriptor at the same key and ignores the old resolution', async () => {
    const old = deferred<ComponentType>()
    const replacement = deferred<ComponentType>()
    const stale = trackedPage('stale-page')
    const current = trackedPage('current-page')
    const firstLoader = vi.fn(() => old.promise)
    const nextLoader = vi.fn(() => replacement.promise)
    const { router, answer } = controlledRouter('/lazy')
    const { rerender, container } = renderPages(router, {
      lazy: lazyPage(firstLoader),
    })
    act(answer)
    outlast(100)
    rerender(<HilosView pages={{ lazy: lazyPage(nextLoader) }} />)
    old.resolve(stale.component)
    await settleCode()
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(firstLoader).toHaveBeenCalledTimes(1)
    expect(nextLoader).toHaveBeenCalledTimes(1)
    outlast(DEFAULT_SKELETON_DELAY_MS - 100)
    expect(pageSkeleton()).not.toBeNull()
    replacement.resolve(current.component)
    await settleCode()
    expect(current.mounted).toHaveBeenCalledTimes(1)

    rerender(<HilosView pages={{ lazy: KnownPage }} />)
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
    rerender(<HilosView pages={{}} />)
    expect(container.querySelector('[data-id="known-page"]')).toBeNull()
    expect(marker()).toBe('ready')
  })

  it('notices a replaced entry in the same map on rerender', async () => {
    const pending = deferred<ComponentType>()
    const stale = trackedPage('stale-page')
    const pages: Record<string, PageView<ComponentType>> = {
      lazy: lazyPage(() => pending.promise),
    }
    const { router, answer } = controlledRouter('/lazy')
    const { rerender, container } = renderPages(router, pages)
    act(answer)
    pages.lazy = KnownPage
    rerender(<HilosView pages={pages} />)
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
    pending.resolve(stale.component)
    await settleCode()
    expect(stale.mounted).not.toHaveBeenCalled()
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
  })

  it('keeps an in-flight descriptor across map updates and releases its cache on unmount', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = controlledRouter('/lazy')
    const view = renderPages(router, { lazy: descriptor })
    view.rerender(<HilosView pages={{ lazy: descriptor, main: PAGES.user }} />)
    expect(loader).toHaveBeenCalledTimes(1)
    pending.resolve(page.component)
    act(answer)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(1)
    view.unmount()

    renderPages(router, { lazy: descriptor })
    expect(loader).toHaveBeenCalledTimes(2)
    await settleCode()
    expect(page.mounted).toHaveBeenCalledTimes(2)
  })

  it.each(['resolve', 'reject'] as const)(
    'does not replace a ready eager page with a departed import %s',
    async (outcome) => {
      const pending = deferred<ComponentType>()
      const stale = trackedPage('stale-page')
      const { router, answer } = controlledRouter('/lazy')
      const { container } = renderPages(router, {
        lazy: lazyPage(() => pending.promise),
        main: PAGES.user,
      })
      act(() => router.navigate('/'))
      act(answer)
      expect(container.querySelector('[data-id="user-page"]')).not.toBeNull()
      if (outcome === 'resolve') {
        pending.resolve(stale.component)
      } else {
        pending.reject(new Error('Departed chunk failed'))
      }
      await settleCode()
      expect(stale.mounted).not.toHaveBeenCalled()
      expect(container.querySelector('[data-id="user-page"]')).not.toBeNull()
      expect(container.querySelector('[data-id="page-error"]')).toBeNull()
      expect(pageSkeleton()).toBeNull()
      expect(marker()).toBe('ready')
    },
  )

  it('reuses code across maps and params while waiting for each server answer', async () => {
    const pending = deferred<ComponentType>()
    const page = trackedPage('lazy-page')
    const loader = vi.fn(() => pending.promise)
    const descriptor = lazyPage(loader)
    const { router, answer } = controlledRouter('/lazy/1')
    const { rerender, container } = renderPages(router, { lazy: descriptor })
    pending.resolve(page.component)
    act(answer)
    await settleCode()
    rerender(<HilosView pages={{ lazy: descriptor, main: PAGES.user }} />)
    expect(page.mounted).toHaveBeenCalledTimes(1)

    act(() => router.navigate('/lazy/2'))
    expect(
      container.querySelector('.hilos-page-slot [data-id="lazy-page"]'),
    ).toBeNull()
    expect(marker()).toBe('loading')
    act(answer)
    expect(page.mounted).toHaveBeenCalledTimes(2)
    act(() => router.navigate('/'))
    act(answer)
    act(() => router.navigate('/lazy/3'))
    act(answer)
    expect(page.mounted).toHaveBeenCalledTimes(3)
    expect(loader).toHaveBeenCalledTimes(1)
  })

  it.each(['resolve', 'reject'] as const)(
    'disposes listeners and timers before a late %s',
    async (outcome) => {
      const pending = deferred<ComponentType>()
      const page = trackedPage('lazy-page')
      const otherLoader = vi.fn(() => Promise.resolve(KnownPage))
      const { router, answer } = controlledRouter()
      answer()
      const { unmount } = renderPages(router, {
        main: PAGES.user,
        lazy: lazyPage(() => pending.promise),
        other: lazyPage(otherLoader),
      })
      act(() => router.navigate('/lazy'))
      const copy = departure()
      expect(copy.childElementCount).toBe(2)
      expect(
        copy.children[0]?.querySelector('[role="status"]')?.textContent,
      ).toBe('')
      expect(copy.children[1]?.textContent).toBe('Leaving page')
      unmount()
      expect(copy.childElementCount).toBe(0)
      expect(vi.getTimerCount()).toBe(0)
      if (outcome === 'resolve') {
        pending.resolve(page.component)
      } else {
        pending.reject(new Error('Disposed chunk failed'))
      }
      act(() => {
        router.navigate('/other')
        answer()
      })
      await settleCode()
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
      const { container } = renderPages(router, { lazy: lazyPage(loader) })
      await settleCode()
      expect(loader).not.toHaveBeenCalled()
      expect(container.querySelector('[data-id="page-error"]')).not.toBeNull()
      expect(pageSkeleton()).toBeNull()
    },
  )

  it('reconnects effects in StrictMode and leaves no controller listening after unmount', async () => {
    const pending = deferred<ComponentType>()
    const loader = vi.fn(() => pending.promise)
    const otherLoader = vi.fn(() => Promise.resolve(KnownPage))
    const { router, answer } = controlledRouter('/lazy')
    const leaveListeners = new Set<() => void>()
    const onLeave = router.onLeave
    vi.spyOn(router, 'onLeave').mockImplementation((listener) => {
      leaveListeners.add(listener)
      const off = onLeave(listener)
      return () => {
        off()
        leaveListeners.delete(listener)
      }
    })
    const { container, unmount } = render(
      <StrictMode>
        <HilosRouterContext.Provider value={router}>
          <HilosView
            pages={{ lazy: lazyPage(loader), other: lazyPage(otherLoader) }}
          />
        </HilosRouterContext.Provider>
      </StrictMode>,
    )
    expect(loader).toHaveBeenCalledTimes(1)
    expect(leaveListeners.size).toBe(2)
    pending.resolve(KnownPage)
    await settleCode()
    expect(container.querySelector('[data-id="known-page"]')).toBeNull()
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(pageSkeleton()).not.toBeNull()
    act(answer)
    expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
    expect(marker()).toBe('ready')
    act(() => router.navigate('/other'))
    expect(otherLoader).toHaveBeenCalledTimes(1)
    act(answer)
    await settleCode()
    expect(marker()).toBe('ready')
    unmount()
    expect(leaveListeners.size).toBe(0)
    act(() => router.navigate('/lazy'))
    expect(loader).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })
})

it('mounts a project override without running the default admin descriptor', () => {
  const defaults = hilosAdminViews()
  const descriptor = defaults[
    HilosPages.DASHBOARD
  ] as LazyPageView<ComponentType>
  const load = vi.spyOn(descriptor, 'load')
  const router = {
    ...routerWith(null),
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.DASHBOARD,
      params: {},
      admin: true,
    }),
  }
  const { container } = renderPages(router, {
    ...defaults,
    [HilosPages.DASHBOARD]: KnownPage,
  })
  expect(container.querySelector('[data-id="known-page"]')).not.toBeNull()
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
 * A card on the admin shell with one dialog in modals, opened from a button in
 * its content. The draft is the card's own state, so it lives exactly as long as
 * the card's instance does.
 *
 * @param preserveModalDraftsOnDelete Whether the card opts in.
 */
function shellPage(preserveModalDraftsOnDelete: boolean) {
  const mounted = vi.fn()
  function Card() {
    const [open, setOpen] = useState(false)
    const [draft, setDraft] = useState('')
    useEffect(() => {
      mounted()
    }, [])

    return (
      <HilosAdminPage
        page="lazy"
        preserveModalDraftsOnDelete={preserveModalDraftsOnDelete}
        modals={
          <HilosModal open={open} title="Rename" onClose={() => setOpen(false)}>
            <input
              data-id="draft"
              data-autofocus=""
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
            />
          </HilosModal>
        }
      >
        <p data-id="page-body">The card</p>
        <button
          type="button"
          data-id="open-draft"
          onClick={() => setOpen(true)}
        >
          Rename
        </button>
      </HilosAdminPage>
    )
  }

  return { component: Card, mounted }
}

/** The one surface a test may find of each page error drawn. */
function pageErrors(): NodeListOf<Element> {
  return document.querySelectorAll('[data-id="page-error"]')
}

function draftInput(): HTMLInputElement | null {
  return document.querySelector<HTMLInputElement>('[data-id="draft"]')
}

describe('HilosView and a page shell under a live deletion', () => {
  /**
   * Open the card, its dialog from the card's button, and type a draft.
   *
   * @param preserve Whether the card opts in.
   * @param kind Whether the outlet maps the card eagerly or behind a loader.
   * @param strict Whether the outlet stands under StrictMode.
   */
  async function openCard(
    preserve: boolean,
    kind: 'eager' | 'lazy' = 'eager',
    strict = false,
  ) {
    const page = shellPage(preserve)
    const harness = controlledRouter('/lazy/1')
    harness.pageIdentity.set(CARD_IDENTITY)
    const view = (
      <HilosRouterContext.Provider value={harness.router}>
        <HilosView
          pages={{
            main: KnownPage,
            lazy:
              kind === 'eager'
                ? page.component
                : lazyPage(() => Promise.resolve(page.component)),
          }}
        />
      </HilosRouterContext.Provider>
    )
    const rendered = render(strict ? <StrictMode>{view}</StrictMode> : view)
    act(harness.answer)
    await settleCode()
    const opener = document.querySelector<HTMLElement>(
      '[data-id="open-draft"]',
    )!
    opener.focus()
    act(() => {
      fireEvent.click(opener)
    })
    const draft = draftInput()!
    act(() => {
      fireEvent.change(draft, { target: { value: 'my draft' } })
    })
    const mounts = page.mounted.mock.calls.length
    expect(mounts).toBe(strict ? 2 : 1)
    expect(draft.value).toBe('my draft')
    expect(document.activeElement).toBe(draft)

    return { ...harness, ...rendered, page, mounts, draft }
  }

  it.each([
    ['eager', false],
    ['lazy', false],
    ['eager', true],
  ] as const)(
    'keeps the %s card (StrictMode: %s), its dialog and the draft while the shell draws the one error',
    async (kind, strict) => {
      const { pageError, page, mounts, draft } = await openCard(
        true,
        kind,
        strict,
      )
      expect(
        document.querySelector('[data-id="hilos-admin-title"]')?.textContent,
      ).toBe('Poland')

      act(() => pageError.set(CARD_DELETED))

      expect(page.mounted).toHaveBeenCalledTimes(mounts)
      expect(draftInput()).toBe(draft)
      expect(draft.value).toBe('my draft')
      expect(pageErrors()).toHaveLength(1)
      const shell = document.querySelector('[data-id="hilos-admin-page"]')
      expect(shell?.contains(pageErrors()[0])).toBe(true)
      expect(
        shell?.querySelector('[data-id="page-error-transition-message"]')
          ?.textContent,
      ).toBe('It was deleted just now.')
      for (const gone of [
        'hilos-breadcrumb',
        'hilos-admin-title',
        'page-body',
        'open-draft',
      ]) {
        expect(document.querySelector(`[data-id="${gone}"]`)).toBeNull()
      }
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(marker()).toBe('error')
    },
  )

  it('keeps the hold through a plain 404 that brings no line', async () => {
    const { pageError, page, draft } = await openCard(true)
    act(() => pageError.set(CARD_DELETED))

    act(() => pageError.set({ ...CARD_NOT_FOUND }))

    expect(
      document.querySelector('[data-id="page-error-transition-message"]'),
    ).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(
      document
        .querySelector('[data-id="hilos-admin-page"]')
        ?.contains(pageErrors()[0]),
    ).toBe(true)
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
      const { pageError, container } = await openCard(true)
      act(() => pageError.set(CARD_DELETED))

      act(() =>
        pageError.set({
          ...CARD_NOT_FOUND,
          httpCode,
          errorCode,
          title: 'Refused',
          message: 'Server refusal words',
        }),
      )

      expect(document.querySelector('[data-id="hilos-admin-page"]')).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(pageErrors()).toHaveLength(1)
      expect(pageErrors()[0].getAttribute('data-error-code')).toBe(
        String(httpCode),
      )
      expect(container.textContent).toContain('Server refusal words')
    },
  )

  it('takes the held card and its dialog away on navigation', async () => {
    const { pageError, answer } = await openCard(true)
    act(() => pageError.set(CARD_DELETED))

    act(() => {
      fireEvent.click(
        document.querySelector<HTMLElement>('[data-id="page-error-home"]')!,
      )
    })
    act(answer)

    expect(document.querySelector('[data-id="known-page"]')).not.toBeNull()
    expect(document.querySelector('[data-id="hilos-admin-page"]')).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(pageErrors()).toHaveLength(0)
  })

  it('replaces a card that did not opt in, line and all', async () => {
    const { pageError, page } = await openCard(false)

    act(() => pageError.set(CARD_DELETED))

    expect(document.querySelector('[data-id="hilos-admin-page"]')).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
    expect(
      document.querySelector('[data-id="page-error-transition-message"]')
        ?.textContent,
    ).toBe('It was deleted just now.')
    expect(page.mounted).toHaveBeenCalledTimes(1)
  })

  it('never mounts an opted-in card that is refused before it is shown', async () => {
    const page = shellPage(true)
    const { router, pageError, pageLoading } = controlledRouter('/lazy/1')
    act(() => {
      pageError.set(CARD_DELETED)
      pageLoading.set(false)
    })
    renderPages(router, { lazy: page.component })
    await settleCode()

    expect(page.mounted).not.toHaveBeenCalled()
    expect(document.querySelector('[data-id="hilos-admin-page"]')).toBeNull()
    expect(pageErrors()).toHaveLength(1)
  })

  it('leaves focus in the open dialog and returns it to Back to home on close', async () => {
    const { pageError, draft } = await openCard(true)

    act(() => pageError.set(CARD_DELETED))
    expect(document.activeElement).toBe(draft)

    act(() => {
      fireEvent.click(
        document.querySelector<HTMLElement>('[data-id="modal-close"]')!,
      )
    })

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.activeElement).toBe(
      document.querySelector('[data-id="page-error-home"]'),
    )
  })
})

describe('HilosView live access loss', () => {
  const releases: Array<() => void> = []

  function AuthLossSurface() {
    return (
      <form data-id="auth-surface">
        <input data-id="auth-identifier" data-autofocus="" />
      </form>
    )
  }

  function DirtyLossPage() {
    const [open, setOpen] = useState(false)
    return (
      <div data-id="private-page">
        <button
          type="button"
          data-id="open-draft"
          onClick={() => setOpen(true)}
        >
          Edit
        </button>
        <HilosModal
          open={open}
          title="Edit"
          confirmOnClose
          onClose={() => setOpen(false)}
        >
          <input data-id="draft" data-autofocus="" />
        </HilosModal>
      </div>
    )
  }

  beforeEach(() => vi.useFakeTimers())
  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
    vi.useRealTimers()
  })

  /** Real session and page bindings with handshake and page frames on one source. */
  function liveSession(initial = '/') {
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const send = vi.fn(() => true)
    const connection = {
      state: 'connected',
      reconnectDragging: false,
      on(event: string, listener: (payload: never) => void): () => void {
        const projectListener = listener as (signal: ProjectSignal) => void
        if (event === 'projectSignal') listeners.add(projectListener)
        return () => listeners.delete(projectListener)
      },
      send,
      forgetPageFrames: () => {},
      tableWindowDescriptors: () => ({}),
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    const pages = bindPageScope(connection, scopes)
    const router = createHilosRouter(
      createPageRouter({
        main: { path: '/', admin: false },
        [HilosPages.PUBLIC_STATUS]: { path: '/status', admin: false },
        lazy: { path: '/lazy/{id?}', admin: true },
        other: { path: '/other', admin: false },
      }),
      pages,
      navigation(initial),
    )
    releases.push(bindAccessLoss(scopes, router))
    pages.releaseOnSession()
    router.start()
    const gate = createAuthGate({
      router,
      currentUserId: sessionUserId(scopes),
    })
    const emit = (type: string, data: unknown): void => {
      for (const listener of listeners) {
        listener({ kind: 'project', type, data, envelope: {} } as ProjectSignal)
      }
    }
    const handshake = (
      id: number | null,
      data: Record<string, unknown> = {},
    ): void =>
      act(() =>
        emit('handshake_response', {
          entities: { currentUser: id === null ? null : { id } },
          data: { accountBlocked: null, ...data },
        }),
      )
    const answer = (): void =>
      act(() =>
        emit('page_response', {
          page: router.currentRoute.get().page,
          payload: {},
        }),
      )
    const refuse = (error: PageSubscriptionError): void =>
      act(() =>
        emit('subscription_page_error', {
          ...error,
          page: router.currentRoute.get().page,
        }),
      )
    const renderLossView = (
      auth = true,
      page: ComponentType = DirtyLossPage,
      strict = false,
    ) => {
      const view = (
        <HilosRouterContext.Provider value={router}>
          <HilosView
            pages={{
              main: page,
              [HilosPages.PUBLIC_STATUS]: page,
              lazy: page,
              other: KnownPage,
            }}
            {...(auth ? { authSurface: AuthLossSurface, authGate: gate } : {})}
          />
        </HilosRouterContext.Provider>
      )
      return render(strict ? <StrictMode>{view}</StrictMode> : view)
    }
    return {
      router,
      send,
      gate,
      handshake,
      answer,
      refuse,
      render: renderLossView,
    }
  }

  it('removes a dirty dialog without confirmation, keeps one auth surface, and survives an anonymous reconnect', async () => {
    const session = liveSession()
    session.handshake(7)
    session.answer()
    const { container } = session.render(true, DirtyLossPage, true)
    const opener = container.querySelector<HTMLElement>(
      '[data-id="open-draft"]',
    )!
    opener.focus()
    fireEvent.click(opener)
    await settleCode()
    const draft = document.querySelector<HTMLInputElement>('[data-id="draft"]')!
    draft.value = 'Unsaved phone number'
    expect(document.activeElement).toBe(draft)
    const sentBefore = session.send.mock.calls.length

    session.handshake(null, { accessLossReason: 'session_ended' })
    act(() => session.gate.requireAuth())
    await settleCode()

    const notice = container.querySelector<HTMLElement>(
      '[data-id="access-loss-notice"]',
    )!
    expect(notice.textContent).toBe(
      'Your session has ended. Any unsaved changes were lost.',
    )
    expect(notice.classList.contains('alert-warning')).toBe(true)
    const region = notice.parentElement!
    expect(region.getAttribute('role')).toBe('status')
    expect(region.getAttribute('aria-live')).toBe('polite')
    expect(container.querySelector('[data-id="private-page"]')).toBeNull()
    expect(draft.isConnected).toBe(false)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.activeElement).toBe(
      container.querySelector('[data-id="auth-identifier"]'),
    )
    expect(session.send).toHaveBeenCalledTimes(sentBefore)
    expect(session.router.currentPath.get()).toBe('/')

    session.handshake(null)
    session.answer()
    outlast(DEFAULT_SKELETON_DELAY_MS)
    expect(
      container.querySelector('[data-id="access-loss-notice"]')?.textContent,
    ).toBe(notice.textContent)
    expect(container.querySelector('[data-id="private-page"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-skeleton"]'),
    ).toBeNull()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )

    session.handshake(8)
    session.answer()
    await settleCode()
    expect(container.querySelector('[data-id="access-loss-notice"]')).toBeNull()
    expect(container.querySelector('[data-id="private-page"]')).not.toBeNull()
    expect(document.querySelector('[data-id="auth-surface"]')).toBeNull()
  })

  it('replaces an open action-auth dialog with the single in-place surface', async () => {
    const session = liveSession()
    session.handshake(7)
    session.answer()
    const { container } = session.render()
    act(() => session.gate.requireAuth())
    await settleCode()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )

    session.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(container.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.querySelectorAll('[data-id="auth-surface"]')).toHaveLength(
      1,
    )
    expect(document.activeElement).toBe(
      container.querySelector('[data-id="auth-identifier"]'),
    )
  })

  it.each(['session_ended', 'account_blocked'] as const)(
    'clears an existing departure copy and never copies the lost page on leave for %s',
    async (reason) => {
      const session = liveSession()
      session.handshake(7)
      session.answer()
      const { container } = session.render()
      await settleCode()
      act(() => session.router.navigate('/other'))
      const departure = container.querySelector<HTMLElement>(
        '[data-id="hilos-page-departure"]',
      )!
      expect(departure.textContent).toContain('Edit')
      expect(departure.childElementCount).toBeGreaterThan(0)

      session.handshake(null, {
        accessLossReason: reason,
        accountBlocked:
          reason === 'account_blocked'
            ? { identifier: 'ada@example.com' }
            : null,
      })
      expect(departure.childElementCount).toBe(0)
      await settleCode()
      session.answer()
      await settleCode()
      act(() => session.router.navigate('/'))
      expect(departure.childElementCount).toBe(0)
      await settleCode()
      expect(
        container.querySelector('[data-id="access-loss-notice"]'),
      ).toBeNull()
      session.answer()
      await settleCode()
      expect(container.querySelector('[data-id="private-page"]')).not.toBeNull()
    },
  )

  it.each(['loading', 'error', 'retained', 'public'] as const)(
    'gives live session end priority over a %s page and a later guest answer',
    async (state) => {
      const session = liveSession(
        state === 'retained' ? '/lazy/1' : state === 'public' ? '/status' : '/',
      )
      session.handshake(7)
      if (state !== 'loading') session.answer()
      const page =
        state === 'retained' ? shellPage(true).component : DirtyLossPage
      const { container } = session.render(true, page)
      await settleCode()
      if (state === 'error') session.refuse(NOT_FOUND)
      if (state === 'retained') session.refuse(CARD_DELETED)
      await settleCode()
      if (state === 'retained') {
        expect(
          container.querySelector('[data-id="hilos-admin-page"]'),
        ).not.toBeNull()
      }

      session.handshake(null, { accessLossReason: 'session_ended' })
      outlast(DEFAULT_SKELETON_DELAY_MS)
      for (const id of [
        'page-error',
        'hilos-page-skeleton',
        'hilos-admin-page',
        'private-page',
      ]) {
        expect(container.querySelector(`[data-id="${id}"]`)).toBeNull()
      }
      expect(
        container.querySelector('[data-id="access-loss-notice"]'),
      ).not.toBeNull()
      expect(
        container.querySelectorAll('[data-id="auth-surface"]'),
      ).toHaveLength(1)
      session.answer()
      await settleCode()
      expect(container.querySelector('[data-id="private-page"]')).toBeNull()
      expect(
        container.querySelector('[data-id="access-loss-notice"]'),
      ).not.toBeNull()
    },
  )

  it('removes the old page and explains the loss without an auth surface', async () => {
    const session = liveSession()
    session.handshake(7)
    session.answer()
    const { container } = session.render(false)
    session.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(container.querySelector('[data-id="private-page"]')).toBeNull()
    expect(container.querySelector('[data-id="auth-surface"]')).toBeNull()
    expect(
      container.querySelector('[data-id="access-loss-notice"]')?.textContent,
    ).toBe('Your session has ended. Any unsaved changes were lost.')
    expect(document.activeElement).toBe(
      container.querySelector('.hilos-page-slot > [tabindex="-1"]'),
    )
    session.answer()
    await settleCode()
    expect(container.querySelector('[data-id="private-page"]')).toBeNull()
  })

  it('preserves a deliberate focus choice outside the replaced page', async () => {
    const session = liveSession()
    session.handshake(7)
    session.answer()
    const { container } = session.render()
    const nav = document.createElement('button')
    document.body.append(nav)
    nav.focus()
    session.handshake(null, { accessLossReason: 'session_ended' })
    await settleCode()
    expect(
      container.querySelector('[data-id="access-loss-notice"]'),
    ).not.toBeNull()
    expect(document.activeElement).toBe(nav)
  })

  it.each([
    'cold guest',
    'new blocked sign-in',
    'voluntary logout',
    'unknown live loss',
  ])('shows no loss notice for %s', async (kind) => {
    const session = liveSession()
    if (kind === 'voluntary logout' || kind === 'unknown live loss') {
      session.handshake(7)
    }
    const { container } = session.render()
    session.handshake(
      null,
      kind === 'new blocked sign-in'
        ? { accountBlocked: { identifier: 'ada@example.com' } }
        : kind === 'unknown live loss'
          ? { accessLossReason: 'unknown' }
          : {},
    )
    session.answer()
    await settleCode()
    expect(container.querySelector('[data-id="access-loss-notice"]')).toBeNull()
  })
})
