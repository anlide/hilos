import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosChangeLogNoisyPage from './HilosChangeLogNoisyPage.vue'

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

const TABLE = 'hilosChangeLogNoisyColumns'
const NOISY = [
  {
    table: 'project_record',
    column: 'heartbeat',
    reason: 'Updated automatically <em>without a decision</em>',
  },
  {
    table: 'project_record',
    column: 'seen_at',
    reason: 'Moves with every read',
  },
]
const ROWS = NOISY.map((column) => ({
  rowKey: `${column.table}.${column.column}`,
  slots: {
    noisyColumn: { ...column, columnKey: `${column.table}.${column.column}` },
  },
}))

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(noisy: unknown = NOISY) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_NOISY)
  scope.data.set(CHANGE_LOG_NOISY_COLUMNS_SECTION, noisy)
  const listeners = new Map<string, Set<Listener>>()
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(event: string, listener: Listener): () => void {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      return () => {
        set.delete(listener)
      }
    },
  }
  const context: HilosChangeLogContext = {
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  }
  function pushWindow(rows = ROWS) {
    for (const listener of listeners.get('projectSignal') ?? []) {
      listener({
        type: 'page_response',
        data: {
          page: HilosPages.CHANGE_LOG_NOISY,
          payload: {
            windows: {
              [TABLE]: {
                rows,
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                offset: 0,
                limit: 25,
                sort: [{ field: 'name', direction: 'asc' }],
              },
            },
          },
        },
      })
    }
  }
  return { context, scope, connection, listeners, pushWindow }
}

function router(detailBuilt: boolean): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG_NOISY,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log/noisy'),
    currentTitle: createSignal('Noisy columns'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Noisy columns',
      lead: 'Columns the journal skips because they change on their own.',
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
      ],
      children: [],
      pageHeadOwner: OWNER,
      pageTabs: TABS.map(({ page, label }) => ({ page, label })),
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page, params) =>
      detailBuilt && page === HilosPages.CHANGE_LOG_TABLE
        ? `/hilos/change-log/tables/${params.tableId}`
        : page === HilosPages.DASHBOARD
          ? '/hilos'
          : TABS.find((tab) => tab.page === page)?.path,
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
}

const mounted: ReturnType<typeof mount>[] = []
const releases: (() => void)[] = []
afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  for (const release of releases.splice(0)) release()
})

async function mountPage(h = harness(), detailBuilt = false) {
  const r = router(detailBuilt)
  const wrapper = mount(HilosChangeLogNoisyPage, {
    props: { context: markRaw(h.context) },
    global: { provide: { [hilosRouterKey as symbol]: r } },
  })
  mounted.push(wrapper)
  h.pushWindow()
  await nextTick()
  return { wrapper, r }
}

describe('HilosChangeLogNoisyPage', () => {
  it('opens directly with the shared head and the table named by its sole h1', async () => {
    const { wrapper } = await mountPage()
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
      'hilos-page-tab-hilos_change_log_noisy',
    )
    expect(nav.get('[aria-current="page"]').classes()).toContain('active')
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id^="hilos-admin-child-"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-title"]').exists()).toBe(false)
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
  })

  it('draws the server window as the standard viewport and ignores the legacy snapshot', async () => {
    const h = harness([
      { table: 'wrong', column: 'snapshot', reason: 'Unused' },
    ])
    const { wrapper } = await mountPage(h)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Change Log')
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    const row = wrapper.get(
      'table [data-id="hilos-table-row-project_record.heartbeat"]',
    )
    expect(row.text()).toContain('project_record.heartbeat')
    expect(row.text()).toContain(NOISY[0]!.reason)
    expect(row.find('em').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('wrong.snapshot')
    expect(wrapper.find('[data-id="hilos-table-search"]').exists()).toBe(false)
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    h.pushWindow([])
    await nextTick()
    expect(wrapper.text()).toContain(
      'No noisy columns are declared on tracked tables',
    )
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalled()
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
