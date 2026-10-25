import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { HilosPages, createSignal } from '@hilos/core/base'
import type {
  HilosDashboardSection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'

import HilosDashboardPage from './HilosDashboardPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** The name the dashboard's own answer carries, straight from the page catalog. */
const IDENTITY: HilosPageIdentity = {
  label: 'Hilos',
  lead: 'Administrative sections for the project.',
  breadcrumb: [],
  children: [],
}

/** The grouping the same answer carries: one framework group with one card. */
const SECTIONS: HilosDashboardSection[] = [
  {
    title: 'Access & identity',
    description: 'Users and the roles that grant them panel access.',
    items: [
      {
        page: HilosPages.USERS,
        label: 'Users',
        lead: 'Application users and panel operators.',
        icon: 'bi-people',
      },
    ],
  },
]

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

function mountPage(
  identity: HilosPageIdentity | undefined,
  sections: HilosDashboardSection[] | undefined,
  options?: {
    slots?: Record<string, string>
    testRouter?: HilosRouter
  },
) {
  const r = options?.testRouter ?? createTestRouter(identity, sections).router
  return mount(HilosDashboardPage, {
    slots: options?.slots,
    global: {
      provide: { [hilosRouterKey as symbol]: r },
    },
  })
}

describe('HilosDashboardPage', () => {
  it('leaves out a section with no cards that have addresses', () => {
    const wrapper = mountPage(IDENTITY, [
      ...SECTIONS,
      {
        title: 'Hidden',
        description: 'No destination',
        items: [{ page: 'unrouted', label: 'Nowhere', lead: '', icon: null }],
      },
    ])
    expect(wrapper.findAll('h2').map((heading) => heading.text())).toEqual([
      'Access & identity',
    ])
    expect(wrapper.text()).not.toContain('No destination')
  })

  it('draws the shared head skeleton, no h1, no icon, and the card skeleton before the page answers', () => {
    const wrapper = mountPage(undefined, undefined)

    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="dashboard-title-skeleton"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('.bi-gear-fill').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-head-title"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="dashboard-skeleton"]').exists()).toBe(true)
  })

  it('transitions from skeleton to answered head on the same mount', async () => {
    const testContext = createTestRouter(undefined, undefined)
    const wrapper = mountPage(undefined, undefined, {
      testRouter: testContext.router,
    })

    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('h1').exists()).toBe(false)

    testContext.pageIdentity.set(IDENTITY)
    testContext.dashboardSections.set(SECTIONS)
    await nextTick()

    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      false,
    )
    expect(wrapper.findAll('h1')).toHaveLength(1)
    const title = wrapper.get('[data-id="hilos-page-head-title"]')
    expect(title.text()).toBe('Hilos')
    expect(wrapper.find('[data-id="hilos-page-head-block"]').text()).toContain(
      'Administrative sections for the project.',
    )

    const icon = wrapper.find('i.bi-gear-fill')
    expect(icon.exists()).toBe(true)
    const iconContainer = icon.element.closest('span')
    expect(iconContainer?.getAttribute('aria-hidden')).toBe('true')
    expect(iconContainer?.classList.contains('rounded-circle')).toBe(true)

    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-head-action"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="dashboard-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="dashboard-title-skeleton"]').exists()).toBe(
      false,
    )
  })

  it('does not create crumbs or tabs when input identity carries non-empty breadcrumb, children, or pageTabs', () => {
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

    const wrapper = mountPage(richIdentity, SECTIONS)

    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(0)
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Hilos',
    )
  })

  it('updates label and lead on subsequent identity changes, removes lead paragraph when empty without fallback, and returns to skeleton on reset', async () => {
    const testContext = createTestRouter(IDENTITY, SECTIONS)
    const wrapper = mountPage(IDENTITY, SECTIONS, {
      testRouter: testContext.router,
    })

    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Hilos',
    )
    expect(wrapper.find('[data-id="hilos-page-head-block"]').text()).toContain(
      'Administrative sections for the project.',
    )

    testContext.pageIdentity.set({
      label: 'Custom Admin',
      lead: 'Custom lead line.',
      breadcrumb: [],
      children: [],
    })
    await nextTick()
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Custom Admin',
    )
    expect(wrapper.find('[data-id="hilos-page-head-block"]').text()).toContain(
      'Custom lead line.',
    )

    testContext.pageIdentity.set({
      label: 'Custom Admin',
      lead: '',
      breadcrumb: [],
      children: [],
    })
    await nextTick()
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'Custom Admin',
    )
    expect(
      wrapper.find('[data-id="hilos-page-head-block"]').find('p').exists(),
    ).toBe(false)
    expect(wrapper.text()).not.toContain('Administrative sections')

    testContext.pageIdentity.set(undefined)
    await nextTick()
    expect(wrapper.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('h1').exists()).toBe(false)
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
    const testContext = createTestRouter(rawIdentity, SECTIONS)
    mountPage(rawIdentity, SECTIONS, {
      testRouter: testContext.router,
    })

    const inRouter = testContext.pageIdentity.get()
    expect(inRouter).toBe(rawIdentity)
    expect(inRouter?.pageHeadOwner).toBeUndefined()
    expect(inRouter?.breadcrumb).toHaveLength(1)
    expect(inRouter?.children).toHaveLength(1)
    expect(inRouter?.pageTabs).toHaveLength(1)
  })

  it('keeps framework and project cards, section order, and the #top slot after the page head in real DOM', () => {
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

    const wrapper = mountPage(IDENTITY, sectionsWithProject, {
      slots: {
        top: '<div data-id="project-top-slot">Custom Top Content</div>',
      },
    })

    const head = wrapper.get('[data-id="hilos-page-head"]')
    const topSlot = wrapper.get('[data-id="project-top-slot"]')
    const frameworkCard = wrapper.get(
      `[data-id="dashboard-card-${HilosPages.USERS}"]`,
    )
    const projectCard = wrapper.get('[data-id="dashboard-card-project-page"]')

    expect(head.element.compareDocumentPosition(topSlot.element)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )
    expect(topSlot.element.compareDocumentPosition(frameworkCard.element)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    )
    expect(
      frameworkCard.element.compareDocumentPosition(projectCard.element),
    ).toBe(Node.DOCUMENT_POSITION_FOLLOWING)

    expect(wrapper.findAll('h2').map((h) => h.text())).toEqual([
      'Access & identity',
      'Project Area',
    ])
    expect(topSlot.text()).toBe('Custom Top Content')
  })
})
