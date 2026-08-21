import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { HilosPages, createSignal } from '@hilos/core'
import type { HilosRouter, PageRouteMatch } from '@hilos/core'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    clearPageError: () => {},
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

function renderPage(page: string, children?: ReactNode) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosAdminPage page={page}>{children}</HilosAdminPage>
    </HilosRouterContext.Provider>,
  )
}

describe('HilosAdminPage', () => {
  afterEach(cleanup)

  it('renders the heading, breadcrumb, and child cards for a section', () => {
    const { container } = renderPage(HilosPages.I18N)
    expect(
      container.querySelector('[data-id="hilos-admin-title"]')?.textContent,
    ).toContain('Internationalization')
    expect(
      container.querySelector('[data-id="hilos-breadcrumb"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id^="hilos-admin-child-"]'),
    ).not.toBeNull()
  })

  it('renders the empty stub for a leaf', () => {
    const { container } = renderPage(HilosPages.I18N_LANGUAGE)
    expect(
      container.querySelector('[data-id="hilos-admin-empty"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
  })

  it('overrides the body with provided children', () => {
    const { container } = renderPage(
      HilosPages.I18N,
      <div data-id="custom-body">mine</div>,
    )
    expect(container.querySelector('[data-id="custom-body"]')).not.toBeNull()
    expect(
      container.querySelector('[data-id="hilos-admin-children"]'),
    ).toBeNull()
  })

  it('passes the resolved admin children to a render function', () => {
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosAdminPage page={HilosPages.I18N}>
          {({ adminChildren }) => (
            <div data-id="count">{adminChildren.length}</div>
          )}
        </HilosAdminPage>
      </HilosRouterContext.Provider>,
    )
    const count = container.querySelector('[data-id="count"]')
    expect(count).not.toBeNull()
    expect(Number(count?.textContent)).toBeGreaterThan(0)
  })
})
