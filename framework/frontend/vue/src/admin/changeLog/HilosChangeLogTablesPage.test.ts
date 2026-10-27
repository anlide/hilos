import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import {
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosAdminAccess,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'

import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosChangeLogTablesPage from './HilosChangeLogTablesPage.vue'

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

const TABLE = 'hilosChangeLogTables'
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
const ROWS = [
  {
    rowKey: 'project_record',
    slots: {
      table: {
        name: 'project_record',
        owner: 'project',
        journaled: true,
        columns: {
          value: 4,
          personal: 2,
          binary: 3,
          secret: 1,
          noise: 2,
          key: 77,
        },
        changesLast24h: 0,
        lastChangeAt: null,
      },
    },
  },
  {
    rowKey: 'hilos_session',
    slots: {
      table: {
        name: 'hilos_session',
        owner: 'framework',
        journaled: false,
        columns: null,
        changesLast24h: null,
        lastChangeAt: null,
      },
    },
  },
]

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(noisy: unknown = NOISY) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLES)
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
          page: HilosPages.CHANGE_LOG_TABLES,
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
      page: HilosPages.CHANGE_LOG_TABLES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log/tables'),
    currentTitle: createSignal('Tracked tables'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Tracked tables',
      lead: 'Database tables, their journal label and column rules.',
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
  const wrapper = mount(HilosChangeLogTablesPage, {
    props: { context: markRaw(h.context) },
    global: { provide: { [hilosRouterKey as symbol]: r } },
  })
  mounted.push(wrapper)
  h.pushWindow()
  await nextTick()
  return { wrapper, r }
}

describe('HilosChangeLogTablesPage', () => {
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
      'hilos-page-tab-hilos_change_log_tables',
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

  it.each(['full', 'view'] as const)(
    'draws the same schema metadata under %s access',
    async (access) => {
      const h = harness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      for (const listener of h.listeners.get('projectSignal') ?? []) {
        listener({
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
            },
            data: { adminViewMode: true },
          },
        })
      }
      expect(hilosAdminAccess.get()).toBe(access)
      const { wrapper } = await mountPage(h)
      expect(wrapper.findAll('h1')).toHaveLength(1)
      expect(wrapper.get('h1').text()).toBe('Change Log')
      const tracked = wrapper.get(
        'table [data-id="hilos-table-row-project_record"]',
      )
      expect(tracked.get('code').text()).toBe('project_record')
      expect(tracked.text()).toContain('Project')
      expect(tracked.text()).toContain(
        'with value 4 · as fact 5 · noisy 2 · secret 1',
      )
      expect(tracked.text()).not.toContain('77')
      expect(tracked.findAll('td')[3]!.text()).toBe('0')
      expect(tracked.findAll('td')[4]!.text()).toBe('—')
      const untracked = wrapper.get(
        'table [data-id="hilos-table-row-hilos_session"]',
      )
      expect(untracked.text()).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
      expect(untracked.text()).toContain('Framework')
      expect(untracked.get('code').classes()).toContain('text-body-secondary')
      expect(
        untracked
          .findAll('td')
          .slice(2, 5)
          .map((cell) => cell.text()),
      ).toEqual(['—', '—', '—'])
      expect(
        wrapper.get('[data-id="hilos-table-card-hilos_session"]').text(),
      ).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
      const modes = wrapper.get('[data-id="hilos-change-log-column-modes"]')
      expect(modes.get('h2').text()).toBe('How a column enters the journal')
      for (const mode of CHANGE_LOG_COLUMN_MODES) {
        expect(modes.text()).toContain(mode.label)
        expect(modes.text()).toContain(mode.description)
      }
      expect(
        modes
          .findAll('i')
          .every((icon) => icon.attributes('aria-hidden') === 'true'),
      ).toBe(true)
      expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
      expect(h.connection.sendTableRendered).toHaveBeenCalled()
      h.pushWindow([])
      await nextTick()
    },
  )

  it.each([false, true])(
    'offers Open only for a tracked table when detail is built: %s',
    async (built) => {
      const { wrapper, r } = await mountPage(harness(), built)
      const links = wrapper.findAll(
        '[data-id="hilos-change-log-open-table-project_record"]',
      )
      expect(links).toHaveLength(built ? 2 : 0)
      expect(
        wrapper
          .find('[data-id="hilos-change-log-open-table-hilos_session"]')
          .exists(),
      ).toBe(false)
      if (built) {
        expect(links[0]!.text()).toBe('Open')
        expect(links[0]!.attributes('aria-label')).toBe('Open project_record')
        expect(links[0]!.attributes('href')).toBe(
          '/hilos/change-log/tables/project_record',
        )
        expect(r.resolvePath).toHaveBeenCalledWith(
          HilosPages.CHANGE_LOG_TABLE,
          { tableId: 'project_record' },
        )
        await links[0]!.trigger('click')
        expect(r.navigate).toHaveBeenCalledWith(
          '/hilos/change-log/tables/project_record',
        )
      }
    },
  )

  it('unregisters the table and releases every connection binding on unmount', async () => {
    const h = harness()
    const { wrapper } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
