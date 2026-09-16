import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { HilosPages, createSignal } from '@hilos/core'
import type {
  HilosDashboardSection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

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

function router(
  identity: HilosPageIdentity | undefined,
  sections: HilosDashboardSection[] | undefined,
): HilosRouter {
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
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(sections),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function mountPage(
  identity: HilosPageIdentity | undefined,
  sections: HilosDashboardSection[] | undefined,
) {
  return mount(HilosDashboardPage, {
    global: {
      provide: { [hilosRouterKey as symbol]: router(identity, sections) },
    },
  })
}

describe('HilosDashboardPage', () => {
  it('renders the heading and the lead the page answered with', () => {
    const wrapper = mountPage(IDENTITY, SECTIONS)

    expect(wrapper.find('[data-id="dashboard-title"]').text()).toBe('Hilos')
    expect(wrapper.text()).toContain('Administrative sections for the project.')
    expect(wrapper.find('[data-id="dashboard-title-skeleton"]').exists()).toBe(
      false,
    )
  })

  it('draws the heading skeleton and no heading before the page answers', () => {
    const wrapper = mountPage(undefined, undefined)

    expect(wrapper.find('[data-id="dashboard-title-skeleton"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('h1').exists()).toBe(false)
  })

  it('draws no lead paragraph when the lead is empty', () => {
    const wrapper = mountPage({ ...IDENTITY, lead: '' }, SECTIONS)
    const heading = wrapper.find('[data-id="dashboard-title"]')

    expect(heading.exists()).toBe(true)
    // The heading's wrapper holds the h1 alone: no empty paragraph under it.
    expect(heading.element.parentElement?.querySelector('p')).toBeNull()
  })
})
