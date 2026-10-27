import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  createSignal,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type TableViewportController,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import {
  formatAnalyticsBrowserSessionListTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsBrowserSessionListRow,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsBrowserSessionsPage as exportedPage } from '@hilos/vue/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosAnalyticsBrowserSessionsPage from './HilosAnalyticsBrowserSessionsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  document.documentElement.removeAttribute('data-bs-theme')
})

const PAGE = HilosPages.ANALYTICS_BROWSER_SESSIONS
const TABLE = 'hilosAnalyticsBrowserSessionList'
const LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
const SESSION = {
  lastSignedInUserId: 12,
  lastSignedInUserName: 'Ada',
  browser: 'Firefox 131 · Windows',
  firstSeenTs: Date.UTC(2026, 9, 14, 20, 40, 51),
  actionCount: 7,
  lastSeenTs: Date.UTC(2026, 9, 14, 20, 51, 7),
}
type Listener = (signal: { type?: string; data: unknown }) => void

/** The complete first window, also replayed by a connection to a late mount. */
function pageAnswer(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
  return {
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
          sort,
        },
      },
    },
  }
}

function harness(
  resolvePath: HilosRouter['resolvePath'] = resolveHilosPath,
  buffered = false,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const currentPath = createSignal('/hilos/analytics/browser-sessions')
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath,
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
    replacePath: vi.fn((path: string) => {
      currentPath.set(path)
    }),
    start: () => {},
    stop: () => {},
  }
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<
    string,
    TableViewportController<HilosAnalyticsBrowserSessionListRow>
  >()
  const sendTableViewport = vi.fn<
    (
      page: string,
      table: string,
      descriptor: TableViewportDescriptor,
    ) => boolean
  >(() => true)
  const sendTableFacets = vi.fn(() => true)
  const unregisterTableWindow = vi.fn((key: string) => registered.delete(key))
  const connection = {
    on(event: string, listener: Listener) {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      if (buffered && event === 'projectSignal') {
        listener({ type: 'page_response', data: pageAnswer() })
      }
      return () => set.delete(listener)
    },
    registerTableWindow(
      key: string,
      source: TableViewportController<HilosAnalyticsBrowserSessionListRow>,
    ) {
      registered.set(key, source)
    },
    unregisterTableWindow,
    sendTableViewport,
    sendTableFacets,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection
  const view = mount(HilosAnalyticsBrowserSessionsPage, {
    props: { context: { connection, scopes } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  function emit(event: string, data: unknown, type?: string) {
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
  }

  return {
    view,
    router,
    scopes,
    registered,
    listeners,
    sendTableViewport,
    sendTableFacets,
    unregisterTableWindow,
    controller: registered.get(TABLE)!,
    identity() {
      const data = scopes.page()!.data
      data.set('pageLabel', 'Browser sessions')
      data.set('pageLead', 'Browser sessions seen in the selected period.')
      data.set('pageHeadOwner', {
        page: HilosPages.ANALYTICS,
        label: 'Analytics',
        lead: LEAD,
        icon: 'bi-bar-chart',
      })
      data.set('pageBreadcrumb', [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.ANALYTICS, label: 'Analytics' },
      ])
      data.set('pageTabs', [
        { page: HilosPages.ANALYTICS, label: 'Overview' },
        { page: PAGE, label: 'Browser sessions' },
        { page: HilosPages.ANALYTICS_PEOPLE, label: 'People' },
      ])
      data.set('pageChildren', [])
    },
    coldWindow(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
      emit('projectSignal', pageAnswer(sort), 'page_response')
    },
    window(
      sessions: Array<[number, Record<string, unknown>]>,
      totalCount = sessions.length,
      totalExact = true,
      rowsBefore = 0,
    ) {
      emit('tableWindow', {
        page: PAGE,
        tableKey: TABLE,
        requestId: sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        rows: sessions.map(([rowKey, browserSession]) => ({
          rowKey,
          slots: { browserSession },
        })),
        totalCount,
        totalExact,
        limit: 25,
        firstAnchor: sessions.length ? { rowKey: sessions[0]![0] } : null,
        lastAnchor: sessions.length ? { rowKey: sessions.at(-1)![0] } : null,
        rowsBefore,
      })
    },
    facets() {
      emit('tableFacetCounts', {
        page: PAGE,
        tableKey: TABLE,
        facets: {
          period: {
            any: { count: 500, exact: false },
            options: {
              'this-hour': { count: 0, exact: true },
              today: { count: 500, exact: true },
              'this-week': { count: 500, exact: false },
            },
          },
        },
      })
    },
    refuseWindow() {
      emit('tableWindowRefused', {
        page: PAGE,
        tableKey: TABLE,
        requestId: sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        errorCode: 'unavailable',
      })
    },
  }
}

type View = ReturnType<typeof harness>['view']

/** The wide projection; the narrow projection repeats the same cells. */
function table(view: View) {
  return view.get(
    '[data-id="hilos-analytics-browser-session-list-table"] table',
  )
}

/**
 * Read a column from the wide projection only.
 *
 * @param view The mounted browser sessions page.
 * @param suffix The cell's data-id suffix.
 */
function cells(view: View, suffix: string) {
  return table(view)
    .findAll(`[data-id="hilos-analytics-browser-session-list-${suffix}"]`)
    .map((node) => node.text())
}

describe('HilosAnalyticsBrowserSessionsPage', () => {
  it.each(['light', 'dark'])(
    'exports the page with one head and the same session cells in both projections in %s',
    async (theme) => {
      document.documentElement.setAttribute('data-bs-theme', theme)
      expect(exportedPage).toBe(HilosAnalyticsBrowserSessionsPage)
      const h = harness()
      h.identity()
      h.coldWindow()
      h.window([
        [64, SESSION],
        [
          71,
          {
            ...SESSION,
            lastSignedInUserId: null,
            lastSignedInUserName: null,
            browser: null,
            actionCount: 0,
          },
        ],
        [
          72,
          { ...SESSION, lastSignedInUserId: 610, lastSignedInUserName: null },
        ],
      ])
      await nextTick()
      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(h.view.get('h1').text()).toBe('Analytics')
      expect(h.view.findAll('h2')).toHaveLength(0)
      const tabs = h.view.get('nav[aria-label="Analytics pages"]')
      expect(tabs.findAll('a').map((link) => link.text())).toEqual([
        'Overview',
        'Browser sessions',
        'People',
      ])
      expect(tabs.get('[aria-current="page"]').text()).toBe('Browser sessions')
      expect(tabs.find('[role="tablist"]').exists()).toBe(false)
      const list = h.view.get('[data-id="hilos-table-cards"] [role="list"]')
      for (const projection of [table(h.view), list]) {
        expect(projection.attributes('aria-labelledby')).toBe(
          h.view.get('h1').attributes('id'),
        )
        expect(
          projection
            .findAll('[data-id="hilos-analytics-browser-session-list-session"]')
            .map((node) => node.text()),
        ).toEqual(['#64', '#71', '#72'])
        expect(
          projection
            .findAll('[data-id="hilos-analytics-browser-session-list-session"]')
            .map((node) => node.attributes('href')),
        ).toEqual([
          '/hilos/analytics/browser-sessions/64',
          '/hilos/analytics/browser-sessions/71',
          '/hilos/analytics/browser-sessions/72',
        ])
        const people = projection.findAll(
          '[data-id="hilos-analytics-browser-session-list-person"]',
        )
        expect(people.map((node) => node.text())).toEqual([
          'Ada (#12)',
          'Not signed in',
          'Deleted user #610',
        ])
        expect(people[0]!.get('a').attributes('href')).toBe(
          '/hilos/analytics/people/12',
        )
        expect(people[1]!.find('a').exists()).toBe(false)
        expect(people[2]!.get('a').attributes('href')).toBe(
          '/hilos/analytics/people/610',
        )
        for (const suffix of [
          'session',
          'person',
          'browser',
          'first-seen',
          'last-seen',
          'actions',
        ]) {
          expect(
            projection
              .findAll(
                `[data-id="hilos-analytics-browser-session-list-${suffix}"]`,
              )
              .map((node) => node.attributes('data-record')),
          ).toEqual(['64', '71', '72'])
        }
      }
      expect(cells(h.view, 'browser')).toEqual([
        'Firefox 131 · Windows',
        '—',
        'Firefox 131 · Windows',
      ])
      expect(cells(h.view, 'actions')).toEqual(['7', '0', '7'])
      const context =
        h.controller.filter.get() as unknown as AnalyticsPeriodContext
      expect(cells(h.view, 'first-seen')).toEqual(
        Array(3).fill(
          formatAnalyticsBrowserSessionListTime(SESSION.firstSeenTs, context),
        ),
      )
      expect(cells(h.view, 'last-seen')).toEqual(
        Array(3).fill(
          formatAnalyticsBrowserSessionListTime(SESSION.lastSeenTs, context),
        ),
      )
      expect(table(h.view).get('[aria-sort="descending"]').text()).toBe(
        'Last seen',
      )
      expect(h.view.find('[data-id="hilos-table-search"]').exists()).toBe(false)
      expect(h.view.find('style').exists()).toBe(false)
    },
  )

  it('hides either account field without leaking an account address and keeps public session links', async () => {
    const h = harness()
    h.identity()
    h.coldWindow([])
    const hidden = { _hidden: true }
    h.window([
      [
        64,
        {
          lastSignedInUserId: hidden,
          lastSignedInUserName: hidden,
          browser: hidden,
          firstSeenTs: hidden,
          lastSeenTs: hidden,
          actionCount: hidden,
        },
      ],
      [65, { ...SESSION, lastSignedInUserName: hidden }],
      [66, { ...SESSION, lastSignedInUserId: hidden }],
    ])
    await nextTick()
    for (const projection of [table(h.view), h.view.get('[role="list"]')]) {
      const people = projection.findAll(
        '[data-id="hilos-analytics-browser-session-list-person"]',
      )
      expect(people.map((node) => node.text())).toEqual([
        'Hidden',
        'Hidden',
        'Hidden',
      ])
      expect(people.every((node) => !node.find('a').exists())).toBe(true)
      expect(
        projection
          .get('[data-id="hilos-analytics-browser-session-list-session"]')
          .attributes('href'),
      ).toBe('/hilos/analytics/browser-sessions/64')
      for (const suffix of ['browser', 'first-seen', 'last-seen', 'actions']) {
        expect(
          projection
            .get(`[data-id="hilos-analytics-browser-session-list-${suffix}"]`)
            .text(),
        ).toBe('Hidden')
      }
    }
    expect(h.view.html()).not.toContain('/people/12')
    expect(h.view.html()).not.toContain('_hidden')
    expect(h.view.text()).not.toContain('Ada')
    expect(h.view.text()).not.toContain('1970')
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toBeNull()
  })

  it('keeps visible text when a project does not serve the target pages', async () => {
    const h = harness(() => undefined)
    h.identity()
    h.coldWindow()
    h.window([[64, SESSION]])
    await nextTick()
    expect(cells(h.view, 'session')).toEqual(['#64'])
    expect(cells(h.view, 'person')).toEqual(['Ada (#12)'])
    expect(table(h.view).findAll('a')).toHaveLength(0)
    expect(h.view.html()).not.toContain('href="#"')
  })

  it('starts one Today viewport, changes Period through standard controls and rewrites the URL', async () => {
    const h = harness(resolveHilosPath, true)
    h.identity()
    h.window([[64, SESSION]])
    h.facets()
    await nextTick()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport.mock.calls[0]?.[2].filter).toMatchObject({
      period: 'today',
      timeZone: expect.any(String),
    })
    expect(h.sendTableFacets).toHaveBeenCalledWith(PAGE, TABLE, {
      period: ['this-hour', 'today', 'this-week'],
    })
    const control = h.view.get('[data-id="hilos-table-filter-period"]')
    await control.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    expect(control.get('[data-id="hilos-table-facet-period-any"]').text()).toBe(
      '500+',
    )
    expect(
      control.get('[data-id="hilos-table-facet-period-this-hour"]').text(),
    ).toBe('0')
    await control.get('[data-id="hilos-dropdown-option-2"]').trigger('click')
    expect(h.controller.filter.get()['period']).toBe('this-week')
    expect(h.router.replacePath).toHaveBeenLastCalledWith(
      '/hilos/analytics/browser-sessions/this-week',
    )
    await h.view.get('[data-id="hilos-table-filter-reset"]').trigger('click')
    expect(h.controller.filter.get()).not.toHaveProperty('period')
    expect(h.router.replacePath).toHaveBeenLastCalledWith(
      '/hilos/analytics/browser-sessions/any-time',
    )
  })

  it('keeps the shell during loading, empty and refused windows and supports keyboard sort', async () => {
    vi.useFakeTimers()
    const h = harness()
    expect(
      h.view
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    h.identity()
    h.coldWindow()
    await vi.advanceTimersByTimeAsync(400)
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-table-loading"]').attributes('aria-busy'),
    ).toBe('true')
    expect(
      h.view.findAll('[data-id="hilos-table-skeleton-row"]').length,
    ).toBeGreaterThan(0)
    h.window([])
    await nextTick()
    const empty = h.view.get('[data-id="hilos-table-no-matches"]')
    expect(empty.text()).toContain('Period: Today')
    await empty.get('[data-id="hilos-table-no-matches-reset"]').trigger('click')
    h.window([])
    await nextTick()
    expect(h.view.get('[data-id="hilos-table-empty"]').text()).toContain(
      'No browser sessions in the selected period',
    )
    h.controller.setFilter('period', 'today')
    h.refuseWindow()
    await nextTick()
    expect(h.view.text()).toContain('List unavailable')
    expect(h.view.find('[data-id="hilos-table-empty"]').exists()).toBe(false)
    expect(h.view.get('h1').text()).toBe('Analytics')
    expect(
      h.view
        .get('nav[aria-label="Analytics pages"] [aria-current="page"]')
        .text(),
    ).toBe('Browser sessions')
    h.window([[64, SESSION]])
    await nextTick()
    const sort = h.view.get<HTMLButtonElement>(
      '[data-id="hilos-table-sort-lastSeenTs"]',
    )
    sort.element.focus()
    expect(document.activeElement).toBe(sort.element)
    expect(sort.element.tagName).toBe('BUTTON')
    // Enter activates a native button through a click; the table receives the
    // same semantic activation from a pointer or the keyboard.
    sort.element.click()
    await nextTick()
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toEqual([
      { field: 'lastSeenTs', direction: 'asc' },
    ])
    expect(table(h.view).get('[aria-sort="ascending"]').text()).toBe(
      'Last seen',
    )
    expect(h.view.get('[data-id="hilos-table-cards"]').classes()).toContain(
      'd-md-none',
    )
  })

  it('releases its real table binding on page disposal and unmount', async () => {
    const h = harness()
    h.coldWindow()
    h.scopes.openPage(HilosPages.ANALYTICS)
    await nextTick()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    h.view.unmount()
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(h.controller.rows.get()).toEqual([])
  })
})
