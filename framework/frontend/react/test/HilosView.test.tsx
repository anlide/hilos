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
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { StrictMode, useEffect } from 'react'
import type { ComponentType } from 'react'
import {
  AUTH_SURFACE_HEADING_ID,
  createAuthGate,
  createHilosRouter,
  createPageRouter,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  lazyPage,
  HilosPages,
} from '@hilos/core'
import { hilosAdminViews } from '@hilos/react/admin-views'
import type {
  AuthGate,
  HilosRouter,
  NavigablePages,
  NavigationEnvironment,
  PageRouteMatch,
  PageSubscriptionError,
  PageView,
  LazyPageView,
  WritableSignal,
} from '@hilos/core'

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
    expect(status).toHaveLength(1)
    expect(status[0].textContent).toBe('Loading…')
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
  return { router, pageLoading, pageError, subscribe, answer }
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
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(1)
    expect(document.querySelector('[role="status"]')?.textContent).toBe(
      'Loading…',
    )

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
    expect(document.querySelector('[role="status"]')).toBeNull()
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
    expect(document.querySelector('[role="status"]')).toBeNull()
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
      expect(copy.childElementCount).toBe(1)
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
