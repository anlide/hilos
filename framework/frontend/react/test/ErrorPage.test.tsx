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
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

const FORBIDDEN: PageSubscriptionError = {
  page: 'user',
  httpCode: 403,
  errorCode: 'forbidden',
  title: 'Forbidden',
  message: 'You do not have access to this page.',
}

describe('ErrorPage', () => {
  afterEach(cleanup)

  it('renders the HTTP code and server title', () => {
    const { container } = render(<ErrorPage error={NOT_FOUND} />)

    const surface = container.querySelector('[data-id="page-error"]')
    expect(surface?.getAttribute('data-error-code')).toBe('404')
    expect(surface?.textContent).toContain('404')
    expect(surface?.textContent).toContain('Page Not Found')
  })

  it('renders server fallback wording for an unmapped status', () => {
    const { container } = render(
      <ErrorPage
        error={{
          ...NOT_FOUND,
          httpCode: 418,
          title: 'Error',
          message: 'An error occurred.',
        }}
      />,
    )

    expect(
      container.querySelector('[data-id="page-error"]')?.textContent,
    ).toContain('Error')
  })

  it('renders arbitrary server text literally for a known status', () => {
    const { container } = render(
      <ErrorPage
        error={{
          ...FORBIDDEN,
          title: 'Server <b>title</b>',
          message: 'Server <em>description</em> & details.',
        }}
      />,
    )
    const surface = container.querySelector('[data-id="page-error"]')
    expect(surface?.querySelector('.lead')?.textContent).toBe(
      'Server <b>title</b>',
    )
    expect(surface?.querySelector('p.text-body-secondary')?.textContent).toBe(
      'Server <em>description</em> & details.',
    )
    expect(surface?.querySelector('b, em')).toBeNull()
    expect(
      surface
        ?.querySelector('[data-id="page-error-home"]')
        ?.getAttribute('href'),
    ).toBe('/')
  })

  it('refuses a 403 without calling the reader a guest', () => {
    const { container } = render(<ErrorPage error={FORBIDDEN} />)

    const surface = container.querySelector('[data-id="page-error"]')
    expect(surface?.getAttribute('data-error-code')).toBe('403')
    expect(surface?.textContent).toContain('Forbidden')
    expect(surface?.textContent).toContain(
      'You do not have access to this page.',
    )
    expect(surface?.textContent).not.toMatch(/guest/i)
  })
})

/** The live 404 of a page whose resource was deleted while it stood open. */
const DELETED: PageSubscriptionError = {
  ...NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

describe('ErrorPage transition line', () => {
  afterEach(cleanup)

  // The description is the paragraph right after the title: once the line is
  // there, p.text-body-secondary names two paragraphs.
  const DESCRIPTION = '.lead + p'
  const LINE = '[data-id="page-error-transition-message"]'

  it('draws the line under the description and before the way home', () => {
    const { container } = render(<ErrorPage error={DELETED} />)

    const surface = container.querySelector('[data-id="page-error"]')!
    const description = surface.querySelector(DESCRIPTION)!
    const line = surface.querySelector(LINE)!
    const home = surface.querySelector('[data-id="page-error-home"]')!
    expect(description.textContent).toBe(NOT_FOUND.message)
    expect(description.className).toBe('text-body-secondary mb-1')
    expect(line.tagName).toBe('P')
    expect(line.className).toBe('small text-body-secondary')
    expect(line.textContent).toBe('It was deleted just now.')
    expect(description.nextElementSibling).toBe(line)
    expect(line.nextElementSibling).toBe(home)
    expect(surface.querySelectorAll('h1')).toHaveLength(1)
    expect(
      surface.querySelectorAll('[data-id="page-error-home"]'),
    ).toHaveLength(1)
  })

  it('shows any server line literally, never as markup', () => {
    const { container } = render(
      <ErrorPage
        error={{ ...DELETED, transitionMessage: 'Gone <b>now</b> & for good' }}
      />,
    )

    const line = container.querySelector(LINE)!
    expect(line.textContent).toBe('Gone <b>now</b> & for good')
    expect(line.querySelector('b')).toBeNull()
    expect(line.innerHTML).toBe('Gone &lt;b&gt;now&lt;/b&gt; &amp; for good')
  })

  it('draws neither the line nor its margin for a refusal without the key', () => {
    const { container } = render(<ErrorPage error={NOT_FOUND} />)

    const surface = container.querySelector('[data-id="page-error"]')!
    expect(surface.querySelector(LINE)).toBeNull()
    expect(surface.querySelector(DESCRIPTION)?.className).toBe(
      'text-body-secondary',
    )
    expect(surface.querySelectorAll('p')).toHaveLength(2)
  })

  it('takes the line away when the next refusal carries no key', () => {
    const { container, rerender } = render(<ErrorPage error={DELETED} />)

    rerender(<ErrorPage error={NOT_FOUND} />)
    expect(container.querySelector(LINE)).toBeNull()
    expect(container.querySelector(DESCRIPTION)?.className).toBe(
      'text-body-secondary',
    )

    rerender(
      <ErrorPage error={{ ...FORBIDDEN, transitionMessage: 'Moved.' }} />,
    )
    expect(container.querySelector(LINE)?.textContent).toBe('Moved.')
    rerender(<ErrorPage error={FORBIDDEN} />)
    expect(container.querySelector(LINE)).toBeNull()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(
      container.querySelectorAll('[data-id="page-error-home"]'),
    ).toHaveLength(1)
  })
})

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
    resolvePath: () => '/user',
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
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
  title: 'Unauthorized',
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
