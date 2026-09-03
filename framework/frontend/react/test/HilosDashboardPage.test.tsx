import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { createSignal } from '@hilos/core'
import type {
  HilosDashboardSection,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosDashboardPage } from '../src/admin/dashboard/HilosDashboardPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** The grouping the dashboard's own answer carries: a framework group and a project one. */
const SECTIONS: HilosDashboardSection[] = [
  {
    title: 'Access & identity',
    description: 'Users and the roles that grant them panel access.',
    items: [
      {
        page: 'hilos_users',
        label: 'Users',
        lead: 'Application users and panel operators.',
        icon: 'bi-people',
      },
    ],
  },
  {
    title: 'Chat administration',
    description: 'What this product adds to the panel.',
    items: [
      {
        page: 'admin_users',
        label: 'Users',
        lead: 'Chat users and their access.',
        icon: 'bi-people',
      },
    ],
  },
]

function router(sections: HilosDashboardSection[] | undefined): HilosRouter {
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
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(sections),
    resolvePath: (page) => (page === 'unrouted' ? undefined : `/hilos/${page}`),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function renderPage(
  sections: HilosDashboardSection[] | undefined,
  children?: ReactNode,
) {
  return render(
    <HilosRouterContext.Provider value={router(sections)}>
      <HilosDashboardPage>{children}</HilosDashboardPage>
    </HilosRouterContext.Provider>,
  )
}

describe('HilosDashboardPage', () => {
  afterEach(cleanup)

  it('renders the section cards the page answered with', () => {
    const { container } = renderPage(SECTIONS)
    expect(container.querySelector('[data-id="dashboard-view"]')).not.toBeNull()
    const usersCard = container.querySelector(
      '[data-id="dashboard-card-hilos_users"]',
    )
    expect(usersCard).not.toBeNull()
    expect(usersCard?.getAttribute('href')).toMatch(/^\/hilos/)
  })

  it("renders a project's own group after the framework ones", () => {
    // The project is a guest in the framework's dashboard, so its group is
    // appended rather than merged in — the order the answer arrives in is kept.
    const { container } = renderPage(SECTIONS)
    const headings = [...container.querySelectorAll('h2')].map(
      (heading) => heading.textContent,
    )

    expect(headings).toEqual(['Access & identity', 'Chat administration'])
    expect(
      container.querySelector('[data-id="dashboard-card-admin_users"]'),
    ).not.toBeNull()
  })

  it('leaves out a card the route map has no address for', () => {
    const { container } = renderPage([
      {
        title: 'Access & identity',
        description: '',
        items: [
          { page: 'unrouted', label: 'Nowhere', lead: '', icon: null },
          { page: 'hilos_users', label: 'Users', lead: '', icon: 'bi-people' },
        ],
      },
    ])

    expect(container.querySelectorAll('a')).toHaveLength(1)
    expect(
      container.querySelector('[data-id="dashboard-card-hilos_users"]'),
    ).not.toBeNull()
  })

  it('draws placeholders while the sections are still on the wire', () => {
    // An empty grid would jump the layout on every visit to the dashboard.
    const { container } = renderPage(undefined)

    expect(
      container.querySelector('[data-id="dashboard-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelectorAll('a')).toHaveLength(0)
  })

  it('renders project-supplied areas above the framework sections', () => {
    const { container } = renderPage(
      SECTIONS,
      <div data-id="custom-top">mine</div>,
    )
    expect(container.querySelector('[data-id="custom-top"]')).not.toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-card-hilos_users"]'),
    ).not.toBeNull()
  })
})
