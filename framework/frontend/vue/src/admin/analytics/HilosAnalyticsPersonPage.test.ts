import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  createSignal,
  formatAnalyticsTime,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosAnalyticsBrowserSessionRow,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type TableViewportController,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosAnalyticsPersonPage from './HilosAnalyticsPersonPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)

const PAGE = HilosPages.ANALYTICS_PERSON
const TABLE = 'hilosAnalyticsBrowserSessions'
const LEAD =
  'Browser sessions where this person signed in last. Other people may have performed actions in the same browser.'
const SESSION = {
  firstSeenTs: Date.UTC(2026, 9, 9, 10, 11, 12),
  lastSeenTs: Date.UTC(2026, 9, 9, 11, 12, 13),
  browser: 'Firefox 143 / Linux',
  actionCount: 7,
}
type Listener = (signal: { type?: string; data: unknown }) => void

function harness(
  param: string | undefined = '7',
  resolvePath: HilosRouter['resolvePath'] = resolveHilosPath,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE,
    params: param === undefined ? {} : { userId: param },
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal('/hilos/analytics/people/7'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<
    string,
    TableViewportController<HilosAnalyticsBrowserSessionRow>
  >()
  const sendTableViewport = vi.fn(() => true)
  const unregisterTableWindow = vi.fn((key: string) => registered.delete(key))
  let heldPageResponse: Parameters<Listener>[0] | undefined
  const connection = {
    on(event: string, listener: Listener) {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      if (event === 'projectSignal' && heldPageResponse)
        listener(heldPageResponse)
      return () => set.delete(listener)
    },
    registerTableWindow(
      key: string,
      source: TableViewportController<HilosAnalyticsBrowserSessionRow>,
    ) {
      registered.set(key, source)
    },
    unregisterTableWindow,
    sendTableViewport,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection
  const view = mount(HilosAnalyticsPersonPage, {
    props: { context: { connection, scopes } },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') heldPageResponse = { type, data }
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
  }

  return {
    view,
    currentRoute,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    navigate(id: string) {
      // HilosRouter publishes the route before PageSubscription opens the new
      // scope and forgets the connection's buffered page_response.
      currentRoute.set({ page: PAGE, params: { userId: id }, admin: true })
      scopes.openPage(PAGE)
      heldPageResponse = undefined
    },
    identity(label = 'Ada (#7)') {
      const data = scopes.page()!.data
      data.set('pageLabel', label)
      data.set('pageLead', LEAD)
      data.set('pageBreadcrumb', [
        { page: HilosPages.ANALYTICS, label: 'Analytics' },
      ])
    },
    coldWindow() {
      emit(
        'projectSignal',
        {
          page: PAGE,
          payload: {
            windows: {
              [TABLE]: {
                rows: [],
                limit: 25,
                totalCount: 0,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                rowsBefore: 0,
                sort: [{ field: 'lastSeenTs', direction: 'desc' }],
              },
            },
          },
        },
        'page_response',
      )
    },
    window(sessions: Array<[string, Record<string, unknown>]>) {
      emit('tableWindow', {
        page: PAGE,
        tableKey: TABLE,
        rows: sessions.map(([rowKey, browserSession]) => ({
          rowKey,
          slots: { browserSession },
        })),
        totalCount: sessions.length,
        totalExact: true,
        limit: 25,
        firstAnchor: null,
        lastAnchor: null,
        rowsBefore: 0,
      })
    },
    refuseWindow() {
      emit('tableWindowRefused', {
        page: PAGE,
        tableKey: TABLE,
        errorCode: 'unavailable',
      })
    },
  }
}

type View = ReturnType<typeof harness>['view']

/** The wide branch of the table; the narrow cards repeat the same cells. */
function table(view: View) {
  return view.find('[data-id="hilos-analytics-browser-sessions-table"] table')
}

/**
 * The texts of one column's cells in the wide branch, in row order.
 *
 * @param view The mounted page.
 * @param id The cell's data-id suffix.
 */
function cells(view: View, id: string) {
  return table(view)
    .findAll(`[data-id="hilos-analytics-session-${id}"]`)
    .map((node) => node.text())
}

describe('HilosAnalyticsPersonPage', () => {
  it('draws the shared identity, the person preset and real cells with session links', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { user: 7 },
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
        limit: 25,
      }),
    )
    h.window([
      ['58', SESSION],
      ['57', { ...SESSION, browser: null, actionCount: 0 }],
    ])
    await nextTick()

    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('h1').text()).toBe('Ada (#7)')
    expect(h.view.text()).toContain(LEAD)
    expect(h.view.findAll('h2')).toHaveLength(0)
    expect(table(h.view).attributes('aria-labelledby')).toBe(
      h.view.find('h1').attributes('id'),
    )

    const links = table(h.view).findAll(
      'a[data-id="hilos-analytics-session-link"]',
    )
    expect(links.map((link) => link.text())).toEqual(['#58', '#57'])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
    expect(links.map((link) => link.attributes('data-record'))).toEqual([
      '58',
      '57',
    ])
    expect(cells(h.view, 'first-seen')).toEqual([
      formatAnalyticsTime(SESSION.firstSeenTs),
      formatAnalyticsTime(SESSION.firstSeenTs),
    ])
    expect(cells(h.view, 'last-seen')).toEqual([
      formatAnalyticsTime(SESSION.lastSeenTs),
      formatAnalyticsTime(SESSION.lastSeenTs),
    ])
    expect(cells(h.view, 'browser')).toEqual([SESSION.browser, '—'])
    expect(cells(h.view, 'actions')).toEqual(['7', '0'])
    expect(
      table(h.view)
        .findAll('[data-id="hilos-analytics-session-actions"]')
        .map((node) => node.attributes('data-record')),
    ).toEqual(['58', '57'])
    // A narrow screen draws the same rows as cards titled by the session link.
    expect(
      h.view
        .findAll(
          '[data-id="hilos-table-cards"] a[data-id="hilos-analytics-session-link"]',
        )
        .map((link) => link.attributes('href')),
    ).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
  })

  it('takes a deleted account label from the page answer', async () => {
    const h = harness()
    h.identity('Deleted user #7')
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Deleted user #7')
  })

  it('draws Hidden in every value without epoch dates, zeros or values in attributes', async () => {
    const h = harness()
    h.identity('Person')
    h.coldWindow()
    h.window([
      [
        '57',
        {
          firstSeenTs: { _hidden: true },
          lastSeenTs: { _hidden: true },
          browser: { _hidden: true },
          actionCount: { _hidden: true },
        },
      ],
    ])
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Person')
    for (const id of ['first-seen', 'last-seen', 'browser', 'actions']) {
      expect(cells(h.view, id).length).toBeGreaterThan(0)
      expect(cells(h.view, id).every((text) => text === 'Hidden')).toBe(true)
    }
    expect(
      table(h.view).find('a[data-id="hilos-analytics-session-link"]').text(),
    ).toBe('#57')
    expect(h.view.html()).not.toContain('1970')
    expect(h.view.html()).not.toContain('_hidden')
    expect(cells(h.view, 'actions')).not.toContain('0')
    expect(cells(h.view, 'browser')).not.toContain('—')
  })

  it('writes the session number as text when the application has no address for it', async () => {
    const h = harness('7', () => undefined)
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    await nextTick()
    expect(h.view.findAll('a[data-id="hilos-analytics-session-link"]')).toEqual(
      [],
    )
    const number = table(h.view).find(
      '[data-id="hilos-analytics-session-link"]',
    )
    expect(number.text()).toBe('#57')
    expect(number.attributes('data-record')).toBe('57')
    expect(number.attributes('href')).toBeUndefined()
  })

  it('uses the table loading, empty and refusal states', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    await nextTick()
    expect(h.view.text()).not.toContain(
      'No browser sessions where this person signed in last',
    )
    h.window([])
    await nextTick()
    expect(h.view.text()).toContain(
      'No browser sessions where this person signed in last',
    )
    h.refuseWindow()
    await nextTick()
    expect(h.view.text()).toContain('List unavailable')
    expect(h.view.text()).not.toContain('No browser sessions')
  })

  it('replaces the controller on a new person, drops old rows and releases every listener on unmount', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    await nextTick()
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(cells(h.view, 'browser')).toEqual([SESSION.browser])

    h.navigate('8')
    await nextTick()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.unregisterTableWindow).toHaveBeenCalledWith(TABLE)
    expect(h.registered.get(TABLE)).toBeDefined()
    expect(h.registered.get(TABLE)).not.toBe(old)
    const currentListeners = [...h.listeners.values()].flatMap((set) => [
      ...set,
    ])
    expect(currentListeners).toHaveLength(oldListeners.length)
    expect(
      currentListeners.some((listener) => oldListeners.includes(listener)),
    ).toBe(false)
    await nextTick()
    expect(h.view.find('[data-record="57"]').exists()).toBe(false)
    expect(h.view.text()).not.toContain('Ada')

    h.identity('Bob (#8)')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { user: 8 } }),
    )
    h.window([['60', { ...SESSION, browser: 'Chrome 141 / macOS' }]])
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Bob (#8)')
    expect(cells(h.view, 'browser')).toEqual(['Chrome 141 / macOS'])
    expect(h.view.find('[data-record="57"]').exists()).toBe(false)

    h.view.unmount()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
  })

  it('releases the existing controller when the route loses its person', async () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.currentRoute.set({ page: PAGE, params: {}, admin: true })
    await nextTick()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(
      h.view
        .find('[data-id="hilos-analytics-browser-sessions-table"]')
        .exists(),
    ).toBe(false)
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    async (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        h.view
          .find('[data-id="hilos-analytics-browser-sessions-table"]')
          .exists(),
      ).toBe(false)
      h.currentRoute.set({ page: PAGE, params: { userId: '7' }, admin: true })
      await nextTick()
      expect(h.registered.size).toBe(1)
      expect(
        h.view
          .find('[data-id="hilos-analytics-browser-sessions-table"]')
          .exists(),
      ).toBe(true)
    },
  )
})
