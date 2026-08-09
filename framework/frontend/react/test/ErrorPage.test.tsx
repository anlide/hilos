import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { createSignal } from '@hilos/core'
import type {
  AuthGate,
  HilosRouter,
  PageRouteMatch,
  PageSubscriptionError,
} from '@hilos/core'

import { ErrorPage } from '../src/ErrorPage.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const NOT_FOUND: PageSubscriptionError = {
  page: 'user',
  httpCode: 404,
  errorCode: 'not_found',
  message: 'Resource #9 not found',
}

describe('ErrorPage', () => {
  afterEach(cleanup)

  it('renders the HTTP code and a mapped title', () => {
    const { container } = render(<ErrorPage error={NOT_FOUND} />)

    const surface = container.querySelector('[data-id="page-error"]')
    expect(surface?.getAttribute('data-error-code')).toBe('404')
    expect(surface?.textContent).toContain('404')
    expect(surface?.textContent).toContain('Page Not Found')
  })

  it('falls back to a generic title for an unmapped status', () => {
    const { container } = render(
      <ErrorPage error={{ ...NOT_FOUND, httpCode: 418 }} />,
    )

    expect(
      container.querySelector('[data-id="page-error"]')?.textContent,
    ).toContain('Error')
  })
})

function routerWith(pageError: PageSubscriptionError | null): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({ page: 'user', params: {} }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal<PageSubscriptionError | null>(pageError),
    pageLoading: createSignal(false),
    clearPageError: () => {},
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

const PAGES = { user: () => <div data-id="user-page" /> }

describe('HilosView page error', () => {
  afterEach(cleanup)

  it('renders the error surface in place of the page when an error is set', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={routerWith(NOT_FOUND)}>
        <HilosView pages={PAGES} />
      </HilosRouterContext.Provider>,
    )

    expect(container.querySelector('[data-id="page-error"]')).not.toBeNull()
    expect(container.querySelector('[data-id="user-page"]')).toBeNull()
  })

  it('renders the mapped page when there is no error', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={routerWith(null)}>
        <HilosView pages={PAGES} />
      </HilosRouterContext.Provider>,
    )

    expect(container.querySelector('[data-id="user-page"]')).not.toBeNull()
    expect(container.querySelector('[data-id="page-error"]')).toBeNull()
  })
})

const UNAUTHORIZED: PageSubscriptionError = {
  page: 'user',
  httpCode: 401,
  errorCode: 'unauthorized',
  message: 'Authentication required',
}

function AuthSurface() {
  return <div data-id="auth-surface" />
}

function fakeAuthGate(open = false): AuthGate {
  return {
    modalOpen: createSignal(open),
    requireAuth: () => {},
    dismiss: () => {},
  }
}

describe('HilosView auth gate', () => {
  afterEach(cleanup)

  it('mounts the auth surface in place of ErrorPage on an anonymous 401', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={routerWith(UNAUTHORIZED)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate()}
        />
      </HilosRouterContext.Provider>,
    )

    expect(container.querySelector('[data-id="auth-surface"]')).not.toBeNull()
    expect(container.querySelector('[data-id="page-error"]')).toBeNull()
  })

  it('still renders ErrorPage for a non-401 error', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={routerWith(NOT_FOUND)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate()}
        />
      </HilosRouterContext.Provider>,
    )

    expect(container.querySelector('[data-id="page-error"]')).not.toBeNull()
    expect(container.querySelector('[data-id="auth-surface"]')).toBeNull()
  })

  it('falls back to ErrorPage on a 401 when no surface is registered', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={routerWith(UNAUTHORIZED)}>
        <HilosView pages={PAGES} />
      </HilosRouterContext.Provider>,
    )

    expect(container.querySelector('[data-id="page-error"]')).not.toBeNull()
  })

  it('shows the auth surface in a modal when the gate opens', () => {
    render(
      <HilosRouterContext.Provider value={routerWith(null)}>
        <HilosView
          pages={PAGES}
          authSurface={AuthSurface}
          authGate={fakeAuthGate(true)}
        />
      </HilosRouterContext.Provider>,
    )

    // The modal portals to <body>; the live page still renders beneath it.
    expect(document.body.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.body.querySelector('[data-id="auth-surface"]'),
    ).not.toBeNull()
  })
})
