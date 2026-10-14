import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosAdminAccess,
  type HilosChangeLogContext,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'

import { hilosRouterKey } from '../../hilosRouterKey.js'
import HilosChangeLogTablesPage from './HilosChangeLogTablesPage.vue'

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
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page, params) =>
      detailBuilt && page === HilosPages.CHANGE_LOG_TABLE
        ? `/hilos/change-log/tables/${params.tableId}`
        : undefined,
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
      expect(wrapper.get('h1').text()).toBe('Tracked tables')
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
      const noise = wrapper.get('[data-id="hilos-change-log-noisy-columns"]')
      expect(noise.get('table').attributes('aria-labelledby')).toBe(
        noise.get('h2').attributes('id'),
      )
      expect(noise.findAll('th').map((th) => th.attributes('scope'))).toEqual([
        'col',
        'col',
      ])
      expect(noise.findAll('tbody tr')).toHaveLength(2)
      expect(noise.get('tbody').text()).toContain('project_record.heartbeat')
      expect(noise.get('tbody').text()).toContain(NOISY[0]!.reason)
      expect(noise.get('.d-md-none').text()).toContain(NOISY[0]!.reason)
      expect(noise.find('em').exists()).toBe(false)
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
      expect(noise.findAll('tbody tr')).toHaveLength(2)
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

  it('uses the core empty copy only for a valid empty snapshot', async () => {
    const h = harness([])
    const { wrapper } = await mountPage(h)
    const noise = wrapper.get('[data-id="hilos-change-log-noisy-columns"]')
    expect(noise.text()).toContain(CHANGE_LOG_NOISY_COLUMNS_EMPTY)
    expect(noise.find('table').exists()).toBe(false)
    for (const invalid of [null, [{ table: 'broken', column: 'heartbeat' }]]) {
      h.scope.data.set(CHANGE_LOG_NOISY_COLUMNS_SECTION, invalid)
      await nextTick()
      expect(noise.text()).not.toContain(CHANGE_LOG_NOISY_COLUMNS_EMPTY)
      expect(noise.find('table').exists()).toBe(false)
    }
  })

  it('unregisters the table and releases every connection binding on unmount', async () => {
    const h = harness()
    const { wrapper } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(TABLE)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
