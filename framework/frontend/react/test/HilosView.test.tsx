// The two parity fixes HIL-424 brought to the React outlet, each with no guard
// before this spec: the gate's modal must not draw a SECOND copy of the sign-in
// surface while the same surface already stands in place of a 401'd page, and
// the dialog must name itself without hard-coding a visible title the
// identifier-first surface owns itself — and since HIL-832 that name is the
// heading the surface draws, not a fixed string of the frame's own.
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { AUTH_SURFACE_HEADING_ID, createSignal } from '@hilos/core'
import type {
  AuthGate,
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
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
