import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { createSignal } from '@hilos/core'
import type {
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
    pageError: createSignal<PageSubscriptionError | null>(pageError),
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
