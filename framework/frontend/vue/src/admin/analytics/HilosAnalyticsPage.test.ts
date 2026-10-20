import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  ANALYTICS_OVERVIEW_EMPTY_TEXT,
  ANALYTICS_OVERVIEW_OPEN_USERS_LABEL,
} from '@hilos/core/admin/analytics'
import {
  HilosPages,
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

import HilosAnalyticsPage from './HilosAnalyticsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const IDENTITY: HilosPageIdentity = {
  label: 'Analytics',
  lead: '',
  breadcrumb: [],
  children: [],
}

function router(
  usersPath: string | undefined,
  identity: HilosPageIdentity | undefined,
): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.ANALYTICS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/analytics'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => (page === HilosPages.USERS ? usersPath : undefined),
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

function mountPage(
  usersPath: string | undefined,
  identity: HilosPageIdentity | undefined,
) {
  return mount(HilosAnalyticsPage, {
    global: {
      provide: { [hilosRouterKey as symbol]: router(usersPath, identity) },
    },
  })
}

describe('HilosAnalyticsPage', () => {
  it('draws the shared empty-state text instead of the shell stub', () => {
    const wrapper = mountPage('/people', IDENTITY)

    expect(
      wrapper.find('[data-id="hilos-analytics-overview-empty"] p').text(),
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
    expect(wrapper.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
    expect(wrapper.find('i.bi-inbox').attributes('aria-hidden')).toBe('true')
  })

  it('links to the users path resolved by the router', () => {
    const wrapper = mountPage('/people', IDENTITY)
    const link = wrapper.find('[data-id="hilos-analytics-overview-open-users"]')

    expect(link.text()).toBe(ANALYTICS_OVERVIEW_OPEN_USERS_LABEL)
    expect(link.attributes('href')).toBe('/people')
  })

  it('keeps the text without a link when the users path is unavailable', () => {
    const wrapper = mountPage(undefined, IDENTITY)

    expect(
      wrapper.find('[data-id="hilos-analytics-overview-empty"]').text(),
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
    expect(
      wrapper.find('[data-id="hilos-analytics-overview-open-users"]').exists(),
    ).toBe(false)
  })

  it('draws the empty state while the shell waits for the page identity', () => {
    const wrapper = mountPage('/people', undefined)

    expect(
      wrapper.find('[data-id="hilos-admin-title-skeleton"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-analytics-overview-empty"] p').text(),
    ).toBe(ANALYTICS_OVERVIEW_EMPTY_TEXT)
  })
})
