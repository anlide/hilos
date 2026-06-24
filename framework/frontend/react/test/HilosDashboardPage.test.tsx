import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { createSignal } from '@hilos/core'
import type { HilosRouter, PageRouteMatch } from '@hilos/core'

import { HilosDashboardPage } from '../src/admin/dashboard/HilosDashboardPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({ page: '', params: {} }),
    pageError: createSignal(null),
    navigate: () => {},
    start: () => {},
    stop: () => {},
  }
}

function renderPage(children?: ReactNode) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosDashboardPage>{children}</HilosDashboardPage>
    </HilosRouterContext.Provider>,
  )
}

describe('HilosDashboardPage', () => {
  afterEach(cleanup)

  it('renders the framework admin section cards from the catalog', () => {
    const { container } = renderPage()
    expect(container.querySelector('[data-id="dashboard-view"]')).not.toBeNull()
    // The Access & identity section links the Users admin page.
    const usersCard = container.querySelector(
      '[data-id="dashboard-card-hilos_users"]',
    )
    expect(usersCard).not.toBeNull()
    // Cards are no-refresh anchors resolved through the admin catalog.
    expect(usersCard?.getAttribute('href')).toMatch(/^\/hilos/)
  })

  it('renders project-supplied areas above the framework sections', () => {
    const { container } = renderPage(<div data-id="custom-top">mine</div>)
    expect(container.querySelector('[data-id="custom-top"]')).not.toBeNull()
    // The framework sections still render alongside the project content.
    expect(
      container.querySelector('[data-id="dashboard-card-hilos_users"]'),
    ).not.toBeNull()
  })
})
