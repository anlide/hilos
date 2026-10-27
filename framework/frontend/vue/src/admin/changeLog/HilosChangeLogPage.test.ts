import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  CHANGE_LOG_OVERVIEW_SECTION,
  type HilosChangeLogContext,
  type HilosChangeLogOverview,
} from '@hilos/core/admin/change-log'

import HilosChangeLogPage from './HilosChangeLogPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const TABS = [
  { page: HilosPages.CHANGE_LOG, label: 'Overview', path: '/hilos/change-log' },
  {
    page: HilosPages.CHANGE_LOG_FEED,
    label: 'Feed',
    path: '/hilos/change-log/feed',
  },
  {
    page: HilosPages.CHANGE_LOG_TABLES,
    label: 'Tracked tables',
    path: '/hilos/change-log/tables',
  },
  {
    page: HilosPages.CHANGE_LOG_NOISY,
    label: 'Noisy columns',
    path: '/hilos/change-log/noisy',
  },
]
const OWNER = {
  page: HilosPages.CHANGE_LOG,
  label: 'Change Log',
  lead: 'Who changed what in the tracked tables, and through which action.',
  icon: 'bi-clock-history',
}

const OVERVIEW: HilosChangeLogOverview = {
  journalEntries: 12,
  journalBytes: 2048,
  oldestAt: '2026-09-01T10:00:00.000Z',
  journaledTables: ['bot', 'hilos_user'],
  liveTables: 32,
}

const mounted: ReturnType<typeof mount>[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
})

async function mountPage(
  unresolved?: string,
  unserved?: string,
  projectRoot = '/hilos',
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG)
  scope.data.set(CHANGE_LOG_OVERVIEW_SECTION, OVERVIEW)
  const connection = {
    registerTableWindow: vi.fn(),
    sendTableViewport: vi.fn(),
    sendAction: vi.fn(),
  }
  const context: HilosChangeLogContext = {
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  }
  const identity: HilosPageIdentity = {
    label: 'Change Log',
    lead: 'Who changed what in the tracked tables, and through which action.',
    breadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
    ],
    children: [],
    pageHeadOwner: OWNER,
    pageTabs: TABS.map(({ page, label }) => ({ page, label })),
  }
  identity.pageTabs = identity.pageTabs?.filter((tab) => tab.page !== unserved)
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log'),
    currentTitle: createSignal('Change Log'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      page === unresolved
        ? undefined
        : page === HilosPages.DASHBOARD
          ? projectRoot
          : TABS.find((tab) => tab.page === page)?.path.replace(
              '/hilos',
              projectRoot,
            ),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const wrapper = mount(HilosChangeLogPage, {
    props: { context: markRaw(context) },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  mounted.push(wrapper)
  await nextTick()
  return { wrapper, connection, router }
}

describe('HilosChangeLogPage', () => {
  it('draws its own overview snapshot under the shared section head with two linked tiles', async () => {
    const { wrapper, connection, router } = await mountPage()
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Change Log')
    expect(wrapper.get('h1').attributes('data-id')).toBe(
      'hilos-page-head-title',
    )
    const head = wrapper.get('[data-id="hilos-page-head"]')
    expect(
      head
        .get('.bi-clock-history')
        .element.closest('[aria-hidden]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(head.text()).toContain(OWNER.lead)
    const crumbs = wrapper.get('[data-id="hilos-breadcrumb"]')
    expect(crumbs.attributes('aria-label')).toBe('Breadcrumb')
    expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Change Log',
    ])
    expect(crumbs.get('a').attributes('href')).toBe('/hilos')
    expect(crumbs.get('[aria-current="page"]').text()).toBe('Change Log')
    const nav = wrapper.get('nav[data-id="hilos-page-tabs"]')
    expect(nav.attributes('aria-label')).toBe('Change Log pages')
    expect(nav.findAll('a').map((link) => link.text())).toEqual(
      TABS.map((tab) => tab.label),
    )
    expect(nav.findAll('a').map((link) => link.attributes('href'))).toEqual(
      TABS.map((tab) => tab.path),
    )
    expect(nav.findAll('[aria-current]')).toHaveLength(1)
    expect(nav.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-hilos_change_log',
    )
    expect(nav.get('[aria-current="page"]').classes()).toContain('active')
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id^="hilos-admin-child-"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-title"]').exists()).toBe(false)

    const feed = wrapper.get('a[data-id="hilos-change-log-link-feed"]')
    const tables = wrapper.get('a[data-id="hilos-change-log-link-tables"]')
    expect(feed.attributes('href')).toBe('/hilos/change-log/feed')
    expect(tables.attributes('href')).toBe('/hilos/change-log/tables')
    expect(wrapper.findAll('[data-id^="hilos-change-log-link-"]')).toHaveLength(
      2,
    )
    expect(feed.get('[data-id="hilos-change-log-tile-journal"]').text()).toBe(
      '12',
    )
    expect(tables.get('[data-id="hilos-change-log-tile-tracked"]').text()).toBe(
      '2 of 32',
    )
    expect(feed.text()).toContain('In the journal database')
    expect(feed.text()).toContain('since September 2026')
    expect(tables.text()).toContain('Tracked')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-viewport-table"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-change-log-feed-empty"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="modal"]').exists()).toBe(false)
    expect(connection.registerTableWindow).not.toHaveBeenCalled()
    expect(connection.sendTableViewport).not.toHaveBeenCalled()
    expect(connection.sendAction).not.toHaveBeenCalled()
    await feed.trigger('click')
    expect(router.navigate).toHaveBeenCalledWith('/hilos/change-log/feed')
    await tables.trigger('click')
    expect(router.navigate).toHaveBeenCalledWith('/hilos/change-log/tables')
  })

  it('resolves tile addresses through the project router', async () => {
    const { wrapper, router } = await mountPage(
      undefined,
      undefined,
      '/project/admin',
    )
    const feed = wrapper.get('a[data-id="hilos-change-log-link-feed"]')
    expect(feed.attributes('href')).toBe('/project/admin/change-log/feed')
    expect(
      wrapper
        .get('a[data-id="hilos-change-log-link-tables"]')
        .attributes('href'),
    ).toBe('/project/admin/change-log/tables')
    await feed.trigger('click')
    expect(router.navigate).toHaveBeenCalledWith(
      '/project/admin/change-log/feed',
    )
  })

  it.each(['unresolved', 'unserved'] as const)(
    'keeps the figures without a fictitious link when a page is %s',
    async (reason) => {
      const { wrapper } = await mountPage(
        reason === 'unresolved' ? HilosPages.CHANGE_LOG_FEED : undefined,
        reason === 'unserved' ? HilosPages.CHANGE_LOG_TABLES : undefined,
      )
      expect(
        wrapper.findAll('[data-id^="hilos-change-log-link-"]'),
      ).toHaveLength(1)
      expect(
        wrapper
          .find(
            `[data-id="hilos-change-log-link-${reason === 'unresolved' ? 'feed' : 'tables'}"]`,
          )
          .exists(),
      ).toBe(false)
      expect(
        wrapper.get('[data-id="hilos-change-log-tile-journal"]').text(),
      ).toBe('12')
      expect(
        wrapper.get('[data-id="hilos-change-log-tile-tracked"]').text(),
      ).toBe('2 of 32')
    },
  )
})
