import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { HilosPages, createSignal } from '@hilos/core'
import type {
  HilosDashboardSection,
  HilosPageIdentity,
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

/** The name the dashboard's own answer carries, straight from the page catalog. */
const IDENTITY: HilosPageIdentity = {
  label: 'Hilos',
  lead: 'Administrative sections for the project.',
  breadcrumb: [],
  children: [],
}

function createTestRouter(
  sections: HilosDashboardSection[] | undefined,
  identity: HilosPageIdentity | undefined,
) {
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(identity)
  const dashboardSections = createSignal<HilosDashboardSection[] | undefined>(
    sections,
  )
  const routerInstance: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity,
    dashboardSections,
    resolvePath: (page) => (page === 'unrouted' ? undefined : `/hilos/${page}`),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return { router: routerInstance, pageIdentity, dashboardSections }
}

function renderPage(
  sections: HilosDashboardSection[] | undefined,
  children?: ReactNode,
  identity?: HilosPageIdentity,
  testRouter?: HilosRouter,
) {
  const r = testRouter ?? createTestRouter(sections, identity).router
  return render(
    <HilosRouterContext.Provider value={r}>
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

  it('leaves out a section with no cards that have addresses', () => {
    const { container } = renderPage([
      ...SECTIONS,
      {
        title: 'Hidden',
        description: 'No destination',
        items: [{ page: 'unrouted', label: 'Nowhere', lead: '', icon: null }],
      },
    ])
    expect(
      [...container.querySelectorAll('h2')].map(
        (heading) => heading.textContent,
      ),
    ).toEqual(['Access & identity', 'Chat administration'])
    expect(container.textContent).not.toContain('No destination')
  })

  it('draws placeholders while the sections are still on the wire', () => {
    // An empty grid would jump the layout on every visit to the dashboard.
    const { container } = renderPage(undefined)

    expect(
      container.querySelector('[data-id="dashboard-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelectorAll('a')).toHaveLength(0)
  })

  it('draws the shared head skeleton, no h1, no icon, and the card skeleton before the page answers', () => {
    const { container } = renderPage(undefined)

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-title-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(container.querySelector('.bi-gear')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-skeleton"]'),
    ).not.toBeNull()
  })

  it('transitions from skeleton to answered head on the same mount', () => {
    const context = createTestRouter(undefined, undefined)
    const { container } = renderPage(
      undefined,
      undefined,
      undefined,
      context.router,
    )

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelector('h1')).toBeNull()

    act(() => {
      context.pageIdentity.set(IDENTITY)
      context.dashboardSections.set(SECTIONS)
    })

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    const title = container.querySelector('[data-id="hilos-page-head-title"]')
    expect(title?.textContent).toBe('Hilos')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Administrative sections for the project.')

    const icon = container.querySelector('i.bi-gear')
    expect(icon).not.toBeNull()
    const iconContainer = icon?.closest('span')
    expect(iconContainer?.getAttribute('aria-hidden')).toBe('true')
    expect(iconContainer?.className).toContain('rounded-circle')

    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(container.querySelector('[data-id="hilos-page-tabs"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-action"]'),
    ).toBeNull()
    expect(container.querySelector('[data-id="dashboard-title"]')).toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-title-skeleton"]'),
    ).toBeNull()
  })

  it('does not create crumbs or tabs when identity has breadcrumb or pageTabs', () => {
    const richIdentity: HilosPageIdentity = {
      label: 'Hilos',
      lead: 'Administrative sections for the project.',
      breadcrumb: [
        { page: 'parent', label: 'Parent' },
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
      ],
      children: [{ page: 'child', label: 'Child', lead: '', icon: null }],
      pageTabs: [
        { page: 'tab-1', label: 'Tab 1' },
        { page: 'tab-2', label: 'Tab 2' },
      ],
    }

    const { container } = renderPage(SECTIONS, undefined, richIdentity)

    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(container.querySelector('nav')).toBeNull()
    expect(container.querySelectorAll('[role="tab"]')).toHaveLength(0)
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]')?.textContent,
    ).toBe('Hilos')
  })

  it('updates label and lead on subsequent identity changes, removes lead paragraph when empty without fallback, and returns to skeleton on reset', () => {
    const context = createTestRouter(SECTIONS, IDENTITY)
    const { container } = renderPage(
      SECTIONS,
      undefined,
      IDENTITY,
      context.router,
    )

    expect(
      container.querySelector('[data-id="hilos-page-head-title"]')?.textContent,
    ).toBe('Hilos')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Administrative sections for the project.')

    act(() => {
      context.pageIdentity.set({
        label: 'Custom Admin',
        lead: 'Custom lead line.',
        breadcrumb: [],
        children: [],
      })
    })
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]')?.textContent,
    ).toBe('Custom Admin')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Custom lead line.')

    act(() => {
      context.pageIdentity.set({
        label: 'Custom Admin',
        lead: '',
        breadcrumb: [],
        children: [],
      })
    })
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]')?.textContent,
    ).toBe('Custom Admin')
    expect(
      container
        .querySelector('[data-id="hilos-page-head-block"]')
        ?.querySelector('p'),
    ).toBeNull()
    expect(container.textContent).not.toContain('Administrative sections')

    act(() => {
      context.pageIdentity.set(undefined)
    })
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelector('h1')).toBeNull()
  })

  it('does not mutate the source identity stored in router.pageIdentity', () => {
    const rawIdentity: HilosPageIdentity = Object.freeze({
      label: 'Hilos',
      lead: 'Administrative sections for the project.',
      breadcrumb: Object.freeze([
        { page: 'home', label: 'Home' },
      ]) as unknown as HilosPageIdentity['breadcrumb'],
      children: Object.freeze([
        { page: 'sub', label: 'Sub' },
      ]) as unknown as HilosPageIdentity['children'],
      pageTabs: Object.freeze([
        { page: 'tab', label: 'Tab' },
      ]) as unknown as HilosPageIdentity['pageTabs'],
    })
    const context = createTestRouter(SECTIONS, rawIdentity)
    renderPage(SECTIONS, undefined, rawIdentity, context.router)

    const inRouter = context.pageIdentity.get()
    expect(inRouter).toBe(rawIdentity)
    expect(inRouter?.pageHeadOwner).toBeUndefined()
    expect(inRouter?.breadcrumb).toHaveLength(1)
    expect(inRouter?.children).toHaveLength(1)
    expect(inRouter?.pageTabs).toHaveLength(1)
  })

  it('keeps framework and project cards, section order, and children after the page head in real DOM', () => {
    const sectionsWithProject: HilosDashboardSection[] = [
      ...SECTIONS,
      {
        title: 'Project Area',
        description: 'Custom cards for this project.',
        items: [
          {
            page: 'project-page',
            label: 'Project Item',
            lead: 'Project card description.',
            icon: 'bi-star',
          },
        ],
      },
    ]

    const { container } = renderPage(
      sectionsWithProject,
      <div data-id="project-top-slot">Custom Top Content</div>,
      IDENTITY,
    )

    const head = container.querySelector('[data-id="hilos-page-head"]')
    const topSlot = container.querySelector('[data-id="project-top-slot"]')
    const frameworkCard = container.querySelector(
      '[data-id="dashboard-card-hilos_users"]',
    )
    const projectCard = container.querySelector(
      '[data-id="dashboard-card-project-page"]',
    )

    expect(head).not.toBeNull()
    expect(topSlot).not.toBeNull()
    expect(frameworkCard).not.toBeNull()
    expect(projectCard).not.toBeNull()

    expect(head!.compareDocumentPosition(topSlot!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )
    expect(topSlot!.compareDocumentPosition(frameworkCard!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )
    expect(frameworkCard!.compareDocumentPosition(projectCard!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )

    expect(
      [...container.querySelectorAll('h2')].map((h) => h.textContent),
    ).toEqual(['Access & identity', 'Chat administration', 'Project Area'])
    expect(topSlot?.textContent).toBe('Custom Top Content')
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
