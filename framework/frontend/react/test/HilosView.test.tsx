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
import { act, cleanup, render } from '@testing-library/react'
import {
  AUTH_SURFACE_HEADING_ID,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
} from '@hilos/core'
import type {
  AuthGate,
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
  WritableSignal,
} from '@hilos/core'

import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const UNAUTHORIZED: PageSubscriptionError = {
  page: 'user',
  httpCode: 401,
  errorCode: 'unauthorized',
  message: 'Authentication required',
}

const PAGES = { user: () => <div data-id="user-page" /> }

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

function routerWith(pageError: PageSubscriptionError | null): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'user',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal<PageSubscriptionError | null>(pageError),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
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
