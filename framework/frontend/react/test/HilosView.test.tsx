// The two parity fixes HIL-424 brought to the React outlet, each with no guard
// before this spec: the gate's modal must not draw a SECOND copy of the sign-in
// surface while the same surface already stands in place of a 401'd page, and
// the dialog must name itself without hard-coding a visible title the
// identifier-first surface owns itself.
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { createSignal } from '@hilos/core'
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

  it('names the dialog without giving it a visible title', () => {
    render(
      <HilosRouterContext.Provider value={routerWith(null)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate(true)}
        />
      </HilosRouterContext.Provider>,
    )

    const dialog = document.querySelector('[data-id="modal"]')
    expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
    expect(dialog?.querySelector('.modal-title')).toBeNull()
  })
})
