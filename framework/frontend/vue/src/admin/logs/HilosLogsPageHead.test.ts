import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick, type Component } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  createPageRouter,
  createSignal,
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosRouter,
} from '@hilos/core/base'
import type { HilosLogRotationsContext } from '@hilos/core/admin/logs'

import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosLogsPage from './HilosLogsPage.vue'
import HilosLogsKeysPage from './HilosLogsKeysPage.vue'
import HilosLogsWorkersPage from './HilosLogsWorkersPage.vue'
import HilosLogsRotationsPage from './HilosLogsRotationsPage.vue'
import HilosLogsSettingsPage from './HilosLogsSettingsPage.vue'
import HilosLogsViewPage from './HilosLogsViewPage.vue'

const LEAD = 'Rotation stats, log keys, workers, and the viewer.'
const TABS = [
  { page: HilosPages.LOGS, label: 'Overview' },
  { page: HilosPages.LOGS_KEYS, label: 'By key' },
  { page: HilosPages.LOGS_WORKERS, label: 'By worker' },
  { page: HilosPages.LOGS_ROTATIONS, label: 'Rotations' },
  { page: HilosPages.LOGS_SETTINGS, label: 'Settings' },
  { page: HilosPages.LOGS_VIEW, label: 'Viewer' },
]
const PATHS = [
  '/hilos/logs',
  '/hilos/logs/keys',
  '/hilos/logs/workers',
  '/hilos/logs/rotations',
  '/hilos/logs/settings',
  '/hilos/logs/view',
]
const PAGES = [
  { page: HilosPages.LOGS, component: HilosLogsPage },
  { page: HilosPages.LOGS_KEYS, component: HilosLogsKeysPage },
  { page: HilosPages.LOGS_WORKERS, component: HilosLogsWorkersPage },
  { page: HilosPages.LOGS_ROTATIONS, component: HilosLogsRotationsPage },
  { page: HilosPages.LOGS_SETTINGS, component: HilosLogsSettingsPage },
  { page: HilosPages.LOGS_VIEW, component: HilosLogsViewPage },
]

/** Identity from an adopted Logs page; its own title is distinct from its owner. */
function identity(page: string): HilosPageIdentity {
  return {
    label: TABS.find((tab) => tab.page === page)!.label,
    lead: 'The current page lead.',
    breadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.LOGS, label: 'Logs' },
    ],
    children: [],
    pageHeadOwner: {
      page: HilosPages.LOGS,
      label: 'Logs',
      lead: LEAD,
      icon: 'bi-journal-text',
    },
    pageTabs: TABS,
  }
}

const mounted: VueWrapper[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
})

/** Mount a real Logs view with its real shell, head and body controllers. */
function mountPage(
  component: Component,
  page: string,
  options: {
    params?: Record<string, string>
    pending?: boolean
    omitted?: string[]
  } = {},
) {
  const params = options.params ?? {}
  const routes = createPageRouter(HILOS_ROUTE_DECLARATIONS)
  const path = routes.path(page, params)!
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(
    options.pending ? undefined : identity(page),
  )
  const navigator: HilosRouter = {
    currentRoute: createSignal(routes.match(path)),
    currentPath: createSignal(path),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity,
    dashboardSections: createSignal(undefined),
    resolvePath: (key, values) =>
      options.omitted?.includes(key) ? undefined : routes.path(key, values),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const connection = {
    on: () => () => {},
    sendTableViewport: () => {},
    sendTableFacets: () => {},
    sendTableRendered: () => {},
    registerTableWindow: () => {},
    unregisterTableWindow: () => {},
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const actions = {
    // A fully addressed Viewer starts its existing body read on mount.
    dispatch: () => ({
      requestId: 'body-read',
      loading: createSignal(true),
      done: new Promise<never>(() => {}),
    }),
  } as unknown as HilosLogRotationsContext['actions']
  const wrapper = mount(component, {
    props: { context: { connection, scopes, actions } },
    global: { provide: { [hilosRouterKey as symbol]: navigator } },
  })
  mounted.push(wrapper)
  return { wrapper, pageIdentity }
}

/** Read the actual head DOM, including the Settings wrapper's fallthrough. */
function expectHead(wrapper: VueWrapper, currentPage: string, paths = PATHS) {
  expect(wrapper.findAll('[data-id="hilos-page-head"]')).toHaveLength(1)
  const heading = wrapper.get('h1')
  expect(wrapper.findAll('h1')).toHaveLength(1)
  expect(heading.attributes('data-id')).toBe('hilos-page-head-title')
  expect(heading.text()).toBe('Logs')
  const headingId = heading.attributes('id')
  expect(headingId).toMatch(/\S+/)
  expect(wrapper.findAll(`[id="${headingId}"]`)).toHaveLength(1)
  const block = wrapper.get('[data-id="hilos-page-head-block"]')
  expect(block.get('p').text()).toBe(LEAD)
  const icon = block.get('i.bi-journal-text')
  expect(icon.element.parentElement?.classList.contains('rounded-circle')).toBe(
    true,
  )
  expect(icon.element.parentElement?.getAttribute('aria-hidden')).toBe('true')
  const crumbs = wrapper.get('[data-id="hilos-breadcrumb"]')
  expect(crumbs.element.tagName).toBe('NAV')
  expect(crumbs.attributes('aria-label')).toBe('Breadcrumb')
  expect(crumbs.findAll('ol > li').map((crumb) => crumb.text())).toEqual([
    'Hilos',
    'Logs',
  ])
  expect(
    crumbs.get('[data-id="hilos-breadcrumb-hilos"]').attributes('href'),
  ).toBe('/hilos')
  const last = crumbs.get('[aria-current="page"]')
  expect(last.text()).toBe('Logs')
  expect(last.find('a').exists()).toBe(false)
  const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
  expect(tabs.element.tagName).toBe('NAV')
  expect(tabs.attributes('aria-label')).toBe('Logs pages')
  expect(tabs.findAll('ul > li > a').map((tab) => tab.text())).toEqual(
    TABS.map((tab) => tab.label),
  )
  for (const [index, tab] of TABS.entries()) {
    const link = tabs.get(`[data-id="hilos-page-tab-${tab.page}"]`)
    expect(link.attributes('href')).toBe(paths[index])
    expect(link.classes().includes('active')).toBe(tab.page === currentPage)
    expect(link.attributes('aria-current')).toBe(
      tab.page === currentPage ? 'page' : undefined,
    )
  }
  expect(tabs.findAll('[aria-current="page"]')).toHaveLength(1)
  expect(tabs.findAll('.active')).toHaveLength(1)
  expect(
    wrapper.find('[role="tablist"], [role="tab"], [role="tabpanel"]').exists(),
  ).toBe(false)
  expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
  expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
}

describe('Logs shared page head', () => {
  for (const { page, component } of PAGES) {
    it(`renders the complete section head on ${page}`, () => {
      const { wrapper } = mountPage(component, page)
      expectHead(wrapper, page)
    })

    it(`waits for identity on ${page} before drawing the complete head`, async () => {
      const { wrapper, pageIdentity } = mountPage(component, page, {
        pending: true,
      })
      const skeleton = wrapper.get('[data-id="hilos-page-head-skeleton"]')
      expect(skeleton.attributes('aria-hidden')).toBe('true')
      expect(skeleton.get('span').classes()).toContain('rounded-circle')
      expect(wrapper.find('h1').exists()).toBe(false)
      expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
      expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
      expect(skeleton.text()).toBe('')
      pageIdentity.set(identity(page))
      await nextTick()
      expect(
        wrapper.find('[data-id="hilos-page-head-skeleton"]').exists(),
      ).toBe(false)
      expectHead(wrapper, page)
    })
  }

  it('keeps Rotations current with the due parameter', () => {
    const { wrapper } = mountPage(
      HilosLogsRotationsPage,
      HilosPages.LOGS_ROTATIONS,
      { params: { state: 'due' } },
    )
    expectHead(
      wrapper,
      HilosPages.LOGS_ROTATIONS,
      PATHS.map((path, index) =>
        index === 3 ? '/hilos/logs/rotations/due' : path,
      ),
    )
  })

  it('keeps Viewer current with the full stream and anchor address', () => {
    const { wrapper } = mountPage(HilosLogsViewPage, HilosPages.LOGS_VIEW, {
      params: {
        nodeId: 'node-2',
        source: 'live',
        stream: 'worker-0.log',
        anchor: '1792836000123',
      },
    })
    expectHead(
      wrapper,
      HilosPages.LOGS_VIEW,
      PATHS.map((path, index) =>
        index === 5
          ? '/hilos/logs/view/node-2/live/worker-0.log/1792836000123'
          : path,
      ),
    )
  })

  it('omits a tab with no built address', () => {
    const { wrapper } = mountPage(HilosLogsPage, HilosPages.LOGS, {
      omitted: [HilosPages.LOGS_WORKERS],
    })
    expect(
      wrapper.find('[data-id="hilos-page-tab-hilos_logs_workers"]').exists(),
    ).toBe(false)
    expect(
      wrapper
        .findAll('[data-id="hilos-page-tabs"] a')
        .map((link) => link.text()),
    ).toEqual(['Overview', 'By key', 'Rotations', 'Settings', 'Viewer'])
    expect(
      wrapper.findAll('[data-id="hilos-page-tabs"] [aria-current="page"]'),
    ).toHaveLength(1)
  })

  it('hides a single eligible tab while keeping the head', () => {
    const { wrapper } = mountPage(
      HilosLogsSettingsPage,
      HilosPages.LOGS_SETTINGS,
      {
        omitted: TABS.filter(
          (tab) => tab.page !== HilosPages.LOGS_SETTINGS,
        ).map((tab) => tab.page),
      },
    )
    expect(wrapper.get('h1').text()).toBe('Logs')
    expect(wrapper.get('[data-id="hilos-page-head-block"] p').text()).toBe(LEAD)
    expect(wrapper.get('[data-id="hilos-breadcrumb"]').text()).toContain('Logs')
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
  })
})
