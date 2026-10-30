// The React People page over the real core table factory, scopes and controls.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindTimeZoneState,
  createSignal,
  HilosPages,
  hilosTimeZone,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosPageKey,
  type HilosRouter,
  type TableViewportController,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import {
  ANALYTICS_PEOPLE_TABLE,
  formatAnalyticsPeopleTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsContext,
  type HilosAnalyticsPersonRow,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsPeoplePage as exportedPage } from '@hilos/react/admin/analytics'

import { HilosAnalyticsPeoplePage } from '../src/admin/analytics/HilosAnalyticsPeoplePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.ANALYTICS_PEOPLE
const TABLE = ANALYTICS_PEOPLE_TABLE
const LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
const PERSON = {
  name: 'Ada',
  sessionCount: 7,
  lastSeenTs: Date.UTC(2026, 9, 14, 20, 51, 7),
}
type Listener = (signal: { type?: string; data: unknown }) => void

/** The first People page answer, also replayed for a late table binding. */
function pageAnswer(
  sort = [{ field: 'lastSeenTs', direction: 'desc' as const }],
) {
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
  strictMode = false,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath: createSignal('/hilos/analytics/people'),
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
    TableViewportController<HilosAnalyticsPersonRow>
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
      source: TableViewportController<HilosAnalyticsPersonRow>,
    ) {
      registered.set(key, source)
    },
    unregisterTableWindow,
    sendTableViewport,
    sendTableFacets,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection
  const context: HilosAnalyticsContext = { connection, scopes }
  function App() {
    return (
      <HilosRouterContext.Provider value={router}>
        <HilosAnalyticsPeoplePage context={context} />
      </HilosRouterContext.Provider>
    )
  }
  const view = render(
    strictMode ? (
      <StrictMode>
        <App />
      </StrictMode>
    ) : (
      <App />
    ),
  )

  function emit(event: string, data: unknown, type?: string) {
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener({ type, data })
    })
  }

  return {
    view,
    scopes,
    registered,
    listeners,
    sendTableViewport,
    sendTableFacets,
    unregisterTableWindow,
    get controller() {
      return registered.get(TABLE)
    },
    identity() {
      act(() => {
        const data = scopes.page()!.data
        data.set('pageLabel', 'People')
        data.set(
          'pageLead',
          'People who last signed into browser sessions seen in the selected period.',
        )
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
          { page: PAGE, label: 'People' },
        ])
        data.set('pageChildren', [])
      })
    },
    coldWindow(sort?: Parameters<typeof pageAnswer>[0]) {
      emit('projectSignal', pageAnswer(sort), 'page_response')
    },
    window(
      people: Array<[number, Record<string, unknown>]>,
      totalCount = people.length,
      totalExact = true,
      rowsBefore = 0,
    ) {
      emit('tableWindow', {
        page: PAGE,
        tableKey: TABLE,
        requestId: sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        rows: people.map(([rowKey, person]) => ({
          rowKey,
          slots: { person },
        })),
        totalCount,
        totalExact,
        limit: 25,
        firstAnchor: people.length ? { rowKey: people[0]![0] } : null,
        lastAnchor: people.length ? { rowKey: people.at(-1)![0] } : null,
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

/** The wide projection of the shared table. */
function table(view: View): HTMLElement {
  const found = view.container.querySelector<HTMLElement>(
    '[data-id="hilos-analytics-people-table"] table',
  )
  if (!found) throw new Error('The People table is not rendered.')
  return found
}

/** Read one column from the wide table projection. */
function cells(view: View, suffix: string): string[] {
  return [
    ...table(view).querySelectorAll(
      `[data-id="hilos-analytics-person-${suffix}"]`,
    ),
  ].map((node) => node.textContent?.trim() ?? '')
}

/** Flush the page's effect-owned controller start. */
async function flushStart(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

describe('HilosAnalyticsPeoplePage', () => {
  it('exports the contextual page and draws identity, names, links and values', async () => {
    expect(exportedPage).toBe(HilosAnalyticsPeoplePage)
    const h = harness()
    await flushStart()
    h.identity()
    h.coldWindow()
    h.window([
      [7, PERSON],
      [610, { ...PERSON, name: null, sessionCount: 0 }],
    ])

    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    const heading = h.view.container.querySelector('h1')!
    expect(heading.textContent?.trim()).toBe('Analytics')
    expect(h.view.container.textContent).toContain(LEAD)
    expect(h.view.container.querySelectorAll('h2')).toHaveLength(0)
    const crumbs = h.view.container.querySelector(
      'nav[aria-label="Breadcrumb"]',
    )!
    expect(crumbs.querySelector('a')?.getAttribute('href')).toBe('/hilos')
    expect(
      crumbs.querySelector('[aria-current="page"]')?.textContent,
    ).toContain('Analytics')
    const tabs = h.view.container.querySelector(
      'nav[aria-label="Analytics pages"]',
    )!
    expect(
      [...tabs.querySelectorAll('a')].map((link) => link.textContent?.trim()),
    ).toEqual(['Overview', 'People'])
    expect(tabs.querySelector('[aria-current="page"]')?.textContent).toBe(
      'People',
    )
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(tabs.querySelector('[role="tablist"]')).toBeNull()

    const personTable = table(h.view)
    expect(personTable.getAttribute('aria-labelledby')).toBe(
      heading.getAttribute('id'),
    )
    expect(
      [...personTable.querySelectorAll('thead th')]
        .slice(0, 3)
        .map((column) => column.textContent?.trim()),
    ).toEqual(['Person', 'Sessions', 'Last seen'])
    expect(
      personTable.querySelector('[aria-sort="descending"]')?.textContent,
    ).toContain('Last seen')
    expect(cells(h.view, 'name')).toEqual(['Ada (#7)', 'Deleted user #610'])
    expect(cells(h.view, 'sessions')).toEqual(['7', '0'])
    const filter = h.controller!.filter.get()
    const periodContext = filter as unknown as AnalyticsPeriodContext
    expect(cells(h.view, 'last-seen')).toEqual([
      formatAnalyticsPeopleTime(PERSON.lastSeenTs, periodContext),
      formatAnalyticsPeopleTime(PERSON.lastSeenTs, periodContext),
    ])
    const links = [
      ...personTable.querySelectorAll(
        'a[data-id="hilos-analytics-person-link"]',
      ),
    ]
    expect(links.map((link) => link.textContent?.trim())).toEqual([
      'Ada (#7)',
      'Deleted user #610',
    ])
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/hilos/analytics/people/7',
      '/hilos/analytics/people/610',
    ])
    for (const suffix of ['link', 'name', 'sessions', 'last-seen']) {
      expect(
        [
          ...personTable.querySelectorAll(
            `[data-id="hilos-analytics-person-${suffix}"]`,
          ),
        ].map((node) => node.getAttribute('data-record')),
      ).toEqual(['7', '610'])
    }
  })

  it('keeps a public number by Hidden and keeps deleted names when links are unavailable', async () => {
    const h = harness((page, params) =>
      page === HilosPages.ANALYTICS_PERSON
        ? undefined
        : resolveHilosPath(page as HilosPageKey, params),
    )
    await flushStart()
    h.identity()
    h.coldWindow()
    h.window([
      [
        7,
        {
          ...PERSON,
          name: { _hidden: true },
          sessionCount: { _hidden: true },
          lastSeenTs: { _hidden: true },
        },
      ],
      [610, { ...PERSON, name: null, sessionCount: 0 }],
    ])

    const nameCells = cells(h.view, 'name')
    expect(nameCells[0]).toContain('#7')
    expect(nameCells[0]).toContain('Hidden')
    expect(nameCells[1]).toBe('Deleted user #610')
    expect(cells(h.view, 'sessions')).toEqual(['Hidden', '0'])
    expect(cells(h.view, 'last-seen')[0]).toBe('Hidden')
    expect(cells(h.view, 'last-seen')[0]).not.toContain('1970')
    expect(h.view.container.innerHTML).not.toContain('_hidden')
    expect(h.view.container.textContent).not.toContain('Ada')
    expect(
      table(h.view).querySelectorAll(
        'a[data-id="hilos-analytics-person-link"]',
      ),
    ).toHaveLength(0)
    expect(
      table(h.view)
        .querySelector('[data-id="hilos-analytics-person-link"]')
        ?.textContent?.trim(),
    ).toBe('#7')
  })

  it('starts Today once and drives Period, facets, Any time and Reset through the controller', async () => {
    const h = harness()
    await flushStart()
    h.identity()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.coldWindow()
    h.window([[7, PERSON]])
    h.facets()

    const { period, ...context } = h.controller!.filter.get()
    expect(period).toBe('today')
    expect(context).toEqual({
      timeZone: expect.any(String),
      weekStartTs: expect.any(Number),
      dayStartTs: expect.any(Number),
      hourStartTs: expect.any(Number),
    })
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { ...context, period: 'today' },
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
        limit: 25,
      }),
    )
    expect(h.sendTableFacets).toHaveBeenCalledWith(PAGE, TABLE, {
      period: ['this-hour', 'today', 'this-week'],
    })

    const control = h.view.container.querySelector(
      '[data-id="hilos-table-filter-period"]',
    )!
    const toggle = control.querySelector<HTMLElement>(
      '[data-id="hilos-dropdown-toggle"]',
    )!
    expect(toggle.textContent).toContain('Period: Today')
    fireEvent.click(toggle)
    expect(
      [...control.querySelectorAll('[role="option"] > span:first-child')].map(
        (option) => option.textContent,
      ),
    ).toEqual(['Any time', 'This hour', 'Today', 'This week'])
    for (const [value, count] of [
      ['any', '500+'],
      ['this-hour', '0'],
      ['today', '500'],
      ['this-week', '500+'],
    ]) {
      expect(
        control.querySelector(`[data-id="hilos-table-facet-period-${value}"]`)
          ?.textContent,
      ).toBe(count)
    }
    expect(control.querySelectorAll('[aria-selected="true"]')).toHaveLength(1)
    fireEvent.click(
      control.querySelector('[data-id="hilos-dropdown-option-2"]')!,
    )
    expect(h.controller!.filter.get()).toEqual({
      ...context,
      period: 'this-week',
    })
    fireEvent.click(toggle)
    fireEvent.click(
      control.querySelector('[data-id="hilos-dropdown-option--1"]')!,
    )
    expect(h.controller!.filter.get()).toEqual(context)
    expect(toggle.textContent).toContain('Period: Any time')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-filter-badge"]'),
    ).toBeNull()

    fireEvent.click(toggle)
    fireEvent.click(
      control.querySelector('[data-id="hilos-dropdown-option-0"]')!,
    )
    fireEvent.click(
      h.view.container.querySelector('[data-id="hilos-table-filter-reset"]')!,
    )
    expect(h.controller!.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
  })

  it('formats against the captured reader zone after the global zone changes', async () => {
    type TimeZoneSignal = { type: string; data: unknown }
    const listeners = new Map<string, (signal: TimeZoneSignal) => void>()
    const timeZoneConnection = {
      projectTimeZone: 'UTC',
      on(event: string, listener: (signal: TimeZoneSignal) => void) {
        listeners.set(event, listener)
        return () => listeners.delete(event)
      },
    } as unknown as HilosConnection
    const previousZone = hilosTimeZone.get()
    const release = bindTimeZoneState(timeZoneConnection)
    const setZone = (timeZone: string) =>
      listeners.get('projectSignal')?.({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })

    try {
      setZone('Europe/Warsaw')
      const h = harness()
      await flushStart()
      h.identity()
      h.coldWindow()
      h.window([[7, PERSON]])
      const filter = h.controller!.filter.get()
      const before = cells(h.view, 'last-seen')
      expect(filter.timeZone).toBe('Europe/Warsaw')
      expect(before).toEqual([
        formatAnalyticsPeopleTime(
          PERSON.lastSeenTs,
          filter as unknown as AnalyticsPeriodContext,
        ),
      ])

      act(() => setZone('America/New_York'))
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(cells(h.view, 'last-seen')).toEqual(before)
      expect(h.controller!.filter.get()).toEqual(filter)
      expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    } finally {
      act(() => setZone(previousZone))
      release()
    }
  })

  it('shows loading, filtered empty, page empty and refusal states under the shared head', async () => {
    const h = harness()
    await flushStart()
    vi.useFakeTimers()
    expect(
      h.view.container
        .querySelector('[data-id="hilos-page-head-skeleton"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.view.container.querySelector('h1')).toBeNull()
    h.identity()
    h.coldWindow()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(400)
    })
    expect(
      h.view.container
        .querySelector('[data-id="hilos-table-loading"]')
        ?.getAttribute('aria-busy'),
    ).toBe('true')
    expect(
      h.view.container.querySelectorAll('[data-id="hilos-table-skeleton-row"]')
        .length,
    ).toBeGreaterThan(0)
    expect(h.view.container.textContent).not.toContain(
      'No people in the selected period',
    )

    h.window([])
    const noMatches = h.view.container.querySelector(
      '[data-id="hilos-table-no-matches"]',
    )!
    expect(noMatches.textContent).toContain('Nothing found')
    expect(
      noMatches.querySelector('[data-id="hilos-table-no-matches-terms"]')
        ?.textContent,
    ).toContain('Period: Today')
    const filtered = h.controller!.filter.get()
    const { period, ...context } = filtered
    expect(period).toBe('today')
    expect(context).toEqual({
      timeZone: expect.any(String),
      weekStartTs: expect.any(Number),
      dayStartTs: expect.any(Number),
      hourStartTs: expect.any(Number),
    })
    fireEvent.click(
      noMatches.querySelector('[data-id="hilos-table-no-matches-reset"]')!,
    )
    expect(h.controller!.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    expect(
      h.sendTableViewport.mock.calls.at(-1)?.[2].filter,
    ).not.toHaveProperty('period')

    h.window([])
    expect(
      h.view.container.querySelector('[data-id="hilos-table-empty"]')
        ?.textContent,
    ).toContain('No people in the selected period')
    expect(h.view.container.textContent).not.toContain('Nothing found')
    h.controller!.setFilter('period', 'today')
    h.refuseWindow()
    expect(h.view.container.textContent).toContain('List unavailable')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-empty"]'),
    ).toBeNull()
    expect(h.view.container.querySelector('h1')?.textContent).toContain(
      'Analytics',
    )
    expect(
      h.view.container.querySelector(
        'nav[aria-label="Analytics pages"] [aria-current="page"]',
      )?.textContent,
    ).toContain('People')
  })

  it('replays one Today viewport from a buffered answer under StrictMode', async () => {
    const h = harness(resolveHilosPath, true, true)
    await flushStart()
    h.identity()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: expect.objectContaining({ period: 'today' }),
      }),
    )
    h.window([[7, PERSON]])
    expect(cells(h.view, 'name')).toEqual(['Ada (#7)'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
  })

  it('uses paging, filters and order on the same controller', async () => {
    const h = harness()
    await flushStart()
    h.identity()
    h.coldWindow()
    h.window(
      Array.from(
        { length: 25 },
        (_, index): [number, Record<string, unknown>] => [index + 1, PERSON],
      ),
      128,
    )
    const controller = h.controller
    expect(
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('1 – 25 of 128')
    expect(
      h.view.container
        .querySelector('[data-id="hilos-table-page-1"]')
        ?.getAttribute('aria-current'),
    ).toBe('page')
    fireEvent.click(
      h.view.container.querySelector('[data-id="hilos-table-page-2"]')!,
    )
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    h.window([[26, PERSON]], 128, true, 25)
    expect(
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('26 – 26 of 128')
    expect(
      h.view.container
        .querySelector('[data-id="hilos-table-page-2"]')
        ?.getAttribute('aria-current'),
    ).toBe('page')

    fireEvent.click(
      h.view.container.querySelector('[data-id="hilos-table-filters-open"]')!,
    )
    const filterModal = document.querySelector(
      '[data-id="hilos-table-filter-period-modal"]',
    )!
    fireEvent.click(
      filterModal.querySelector('[data-id="hilos-dropdown-toggle"]')!,
    )
    fireEvent.click(
      filterModal.querySelector('[data-id="hilos-dropdown-option-0"]')!,
    )
    fireEvent.click(
      document.querySelector('[data-id="hilos-table-filters-done"]')!,
    )
    expect(h.controller).toBe(controller)
    expect(h.controller!.filter.get().period).toBe('this-hour')
    fireEvent.click(
      table(h.view).querySelector('[data-id="hilos-table-sort-lastSeenTs"]')!,
    )
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2]).toMatchObject({
      filter: expect.objectContaining({ period: 'this-hour' }),
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    h.window([[7, PERSON]], 500, false)
    expect(
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('1 – 1 of 500+')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-page-1"]'),
    ).toBeNull()
    expect(
      h.view.container.querySelector('[data-id="hilos-table-next"]')
        ?.textContent,
    ).toContain('Next')
    expect(h.controller).toBe(controller)
  })

  it('releases the bound controller and listeners on scope change and unmount', async () => {
    const onScopeChange = harness()
    await flushStart()
    onScopeChange.coldWindow()
    const scopedController = onScopeChange.controller!
    expect(onScopeChange.registered.size).toBe(1)
    act(() => onScopeChange.scopes.openPage(HilosPages.ANALYTICS))
    expect(onScopeChange.registered.size).toBe(0)
    expect(
      [...onScopeChange.listeners.values()].every((set) => set.size === 0),
    ).toBe(true)
    expect(scopedController.rows.get()).toEqual([])
    onScopeChange.view.unmount()
    expect(onScopeChange.unregisterTableWindow).toHaveBeenCalledTimes(1)

    const onUnmount = harness()
    await flushStart()
    onUnmount.coldWindow()
    expect(onUnmount.registered.size).toBe(1)
    onUnmount.view.unmount()
    await flushStart()
    expect(onUnmount.registered.size).toBe(0)
    expect(
      [...onUnmount.listeners.values()].every((set) => set.size === 0),
    ).toBe(true)
    expect(onUnmount.unregisterTableWindow).toHaveBeenCalledTimes(1)
  })
})
