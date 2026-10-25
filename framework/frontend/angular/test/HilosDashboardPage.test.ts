import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HilosPages, createSignal } from '@hilos/core/base'
import type {
  HilosDashboardSection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosDashboardPage } from '../src/admin/dashboard/HilosDashboardPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  initialIdentity: HilosPageIdentity | undefined,
  initialSections: HilosDashboardSection[] | undefined,
) {
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(
    initialIdentity,
  )
  const dashboardSections = createSignal<HilosDashboardSection[] | undefined>(
    initialSections,
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

@Component({
  imports: [HilosDashboardPage],
  template: `
    <hilos-dashboard-page>
      <div data-id="project-top-slot">Custom Top Content</div>
    </hilos-dashboard-page>
  `,
})
class HostWithProjectedContent {}

function mountPage(
  identity: HilosPageIdentity | undefined,
  sections: HilosDashboardSection[] | undefined,
  customRouter?: HilosRouter,
): {
  fixture: ComponentFixture<HilosDashboardPage>
  container: HTMLElement
} {
  const routerToProvide =
    customRouter ?? createTestRouter(identity, sections).router
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: routerToProvide }],
  })
  const fixture = TestBed.createComponent(HilosDashboardPage)
  fixture.detectChanges()
  return {
    fixture,
    container: fixture.nativeElement as HTMLElement,
  }
}

function mountProjectedHost(
  identity: HilosPageIdentity | undefined,
  sections: HilosDashboardSection[] | undefined,
  customRouter?: HilosRouter,
): {
  fixture: ComponentFixture<HostWithProjectedContent>
  container: HTMLElement
} {
  const routerToProvide =
    customRouter ?? createTestRouter(identity, sections).router
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: routerToProvide }],
  })
  const fixture = TestBed.createComponent(HostWithProjectedContent)
  fixture.detectChanges()
  return {
    fixture,
    container: fixture.nativeElement as HTMLElement,
  }
}

describe('HilosDashboardPage', () => {
  afterEach(() => {
    TestBed.resetTestingModule()
  })

  it('renders the section cards the page answered with', () => {
    const { container } = mountPage(IDENTITY, SECTIONS)
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
    const { container } = mountPage(IDENTITY, SECTIONS)
    const headings = [...container.querySelectorAll('h2')].map((heading) =>
      heading.textContent?.trim(),
    )

    expect(headings).toEqual(['Access & identity', 'Chat administration'])
    expect(
      container.querySelector('[data-id="dashboard-card-admin_users"]'),
    ).not.toBeNull()
  })

  it('leaves out a card the route map has no address for', () => {
    const { container } = mountPage(IDENTITY, [
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
    const { container } = mountPage(IDENTITY, [
      ...SECTIONS,
      {
        title: 'Hidden',
        description: 'No destination',
        items: [{ page: 'unrouted', label: 'Nowhere', lead: '', icon: null }],
      },
    ])
    expect(
      [...container.querySelectorAll('h2')].map((heading) =>
        heading.textContent?.trim(),
      ),
    ).toEqual(['Access & identity', 'Chat administration'])
    expect(container.textContent).not.toContain('No destination')
  })

  it('draws placeholders while the sections are still on the wire', () => {
    // An empty grid would jump the layout on every visit to the dashboard.
    const { container } = mountPage(IDENTITY, undefined)

    expect(
      container.querySelector('[data-id="dashboard-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelectorAll('a')).toHaveLength(0)
  })

  it('draws the shared head skeleton, no h1, no icon, and the card skeleton before the page answers', () => {
    const { container } = mountPage(undefined, undefined)

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-title-skeleton"]'),
    ).toBeNull()
    expect(container.querySelector('h1')).toBeNull()
    expect(container.querySelector('.bi-gear-fill')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-title"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="dashboard-skeleton"]'),
    ).not.toBeNull()
  })

  it('transitions from skeleton to answered head on the same mount', () => {
    const context = createTestRouter(undefined, undefined)
    const { fixture, container } = mountPage(
      undefined,
      undefined,
      context.router,
    )

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(container.querySelector('h1')).toBeNull()

    context.pageIdentity.set(IDENTITY)
    context.dashboardSections.set(SECTIONS)
    fixture.detectChanges()

    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).toBeNull()
    expect(container.querySelectorAll('h1')).toHaveLength(1)
    const title = container.querySelector('[data-id="hilos-page-head-title"]')
    expect(title?.textContent?.trim()).toBe('Hilos')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Administrative sections for the project.')

    const icon = container.querySelector('i.bi-gear-fill')
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

    const { container } = mountPage(richIdentity, SECTIONS)

    expect(container.querySelector('[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(container.querySelector('nav')).toBeNull()
    expect(container.querySelectorAll('[role="tab"]')).toHaveLength(0)
    expect(
      container
        .querySelector('[data-id="hilos-page-head-title"]')
        ?.textContent?.trim(),
    ).toBe('Hilos')
  })

  it('updates label and lead on subsequent identity changes, removes lead paragraph when empty without fallback, and returns to skeleton on reset', () => {
    const context = createTestRouter(IDENTITY, SECTIONS)
    const { fixture, container } = mountPage(IDENTITY, SECTIONS, context.router)

    expect(
      container
        .querySelector('[data-id="hilos-page-head-title"]')
        ?.textContent?.trim(),
    ).toBe('Hilos')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Administrative sections for the project.')

    context.pageIdentity.set({
      label: 'Custom Admin',
      lead: 'Custom lead line.',
      breadcrumb: [],
      children: [],
    })
    fixture.detectChanges()
    expect(
      container
        .querySelector('[data-id="hilos-page-head-title"]')
        ?.textContent?.trim(),
    ).toBe('Custom Admin')
    expect(
      container.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain('Custom lead line.')

    context.pageIdentity.set({
      label: 'Custom Admin',
      lead: '',
      breadcrumb: [],
      children: [],
    })
    fixture.detectChanges()
    expect(
      container
        .querySelector('[data-id="hilos-page-head-title"]')
        ?.textContent?.trim(),
    ).toBe('Custom Admin')
    expect(
      container
        .querySelector('[data-id="hilos-page-head-block"]')
        ?.querySelector('p'),
    ).toBeNull()
    expect(container.textContent).not.toContain('Administrative sections')

    context.pageIdentity.set(undefined)
    fixture.detectChanges()
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
    const context = createTestRouter(rawIdentity, SECTIONS)
    mountPage(rawIdentity, SECTIONS, context.router)

    const inRouter = context.pageIdentity.get()
    expect(inRouter).toBe(rawIdentity)
    expect(inRouter?.pageHeadOwner).toBeUndefined()
    expect(inRouter?.breadcrumb).toHaveLength(1)
    expect(inRouter?.children).toHaveLength(1)
    expect(inRouter?.pageTabs).toHaveLength(1)
  })

  it('keeps framework and project cards, section order, and projected content (<ng-content />) after the page head in real DOM', () => {
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

    const { container } = mountProjectedHost(IDENTITY, sectionsWithProject)

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
      [...container.querySelectorAll('h2')].map((heading) =>
        heading.textContent?.trim(),
      ),
    ).toEqual(['Access & identity', 'Chat administration', 'Project Area'])
    expect(topSlot?.textContent?.trim()).toBe('Custom Top Content')
  })

  it('renders project-supplied areas above the framework sections', () => {
    const { container } = mountProjectedHost(IDENTITY, SECTIONS)
    expect(
      container.querySelector('[data-id="project-top-slot"]'),
    ).not.toBeNull()
  })
})
