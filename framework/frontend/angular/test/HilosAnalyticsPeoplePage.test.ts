// The real Angular table, head, links and hideable cells over the People core.
import { TestBed } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  bindTimeZoneState,
  computedSignal,
  createSignal,
  HilosPages,
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type TableViewportController,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import {
  formatAnalyticsPeopleTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsContext,
  type HilosAnalyticsPersonRow,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsPeoplePage as exportedPage } from '@hilos/angular/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsPeoplePage } from '../src/admin/analytics/HilosAnalyticsPeoplePage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.ANALYTICS_PEOPLE
const TABLE = 'hilosAnalyticsPeople'
const LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
const PERSON = {
  name: 'Ada',
  sessionCount: 7,
  lastSeenTs: Date.UTC(2026, 9, 14, 20, 51, 7),
}
type Listener = (signal: { type?: string; data: unknown }) => void

/** The complete cold window, also replayed for a late mount. */
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

function channel(buffered = false) {
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
  const unregisterTableWindow = vi.fn(
    (key: string, source: TableViewportController<HilosAnalyticsPersonRow>) => {
      if (registered.get(key) === source) registered.delete(key)
    },
  )
  const connection = {
    on(event: string, listener: Listener) {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      if (buffered && event === 'projectSignal')
        listener({ type: 'page_response', data: pageAnswer() })
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

  return {
    connection,
    listeners,
    registered,
    sendTableViewport,
    sendTableFacets,
    unregisterTableWindow,
    emit(event: string, data: unknown, type?: string) {
      for (const listener of listeners.get(event) ?? [])
        listener({ type, data })
    },
  }
}

function harness(
  resolvePath: HilosRouter['resolvePath'] = resolveHilosPath,
  buffered = false,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const identity = createSignal(pageIdentity(scopes))
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath: createSignal('/hilos/analytics/people'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: computedSignal(() => identity.get().get()),
    dashboardSections: createSignal(undefined),
    resolvePath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const transport = channel(buffered)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosAnalyticsPeoplePage)
  fixture.componentRef.setInput('context', {
    connection: transport.connection,
    scopes,
  })
  fixture.detectChanges()

  function emit(event: string, data: unknown, type?: string) {
    transport.emit(event, data, type)
    fixture.detectChanges()
  }

  return {
    ...transport,
    fixture,
    scopes,
    router,
    root: fixture.nativeElement as HTMLElement,
    controller: transport.registered.get(TABLE)!,
    setContext(context: HilosAnalyticsContext) {
      identity.set(pageIdentity(context.scopes))
      fixture.componentRef.setInput('context', context)
      fixture.detectChanges()
    },
    identity() {
      const data = scopes.page()!.data
      data.set('pageLabel', 'People')
      data.set('pageLead', 'People in the selected period.')
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
      fixture.detectChanges()
    },
    coldWindow(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
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
        requestId: transport.sendTableViewport.mock.calls.at(-1)?.[2].requestId,
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
        requestId: transport.sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        errorCode: 'unavailable',
      })
    },
  }
}

type Harness = ReturnType<typeof harness>

function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector<HTMLElement>(selector)
  expect(found, selector).not.toBeNull()
  return found!
}

function table(h: Harness): HTMLElement {
  return get(h.root, '[data-id="hilos-analytics-people-table"] table')
}

/**
 * Read a column from the wide projection only.
 *
 * @param h The mounted page.
 * @param suffix The cell's data-id suffix.
 */
function cells(h: Harness, suffix: string): string[] {
  return [
    ...table(h).querySelectorAll(
      `[data-id="hilos-analytics-person-${suffix}"]`,
    ),
  ].map((node) => node.textContent?.trim() ?? '')
}

function click(h: Harness, selector: string, root: ParentNode = h.root) {
  get(root, selector).click()
  h.fixture.detectChanges()
}

describe('HilosAnalyticsPeoplePage', () => {
  it('exports the context page and draws real links and cells under the shared head', () => {
    expect(exportedPage).toBe(HilosAnalyticsPeoplePage)
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([
      [7, PERSON],
      [610, { ...PERSON, name: null, sessionCount: 0 }],
    ])

    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    const heading = get(h.root, 'h1')
    expect(heading.textContent?.trim()).toBe('Analytics')
    expect(h.root.querySelectorAll('h2')).toHaveLength(0)
    expect(h.root.textContent).toContain(LEAD)
    const crumbs = get(h.root, 'nav[aria-label="Breadcrumb"]')
    expect(get(crumbs, 'a').getAttribute('href')).toBe('/hilos')
    expect(get(crumbs, '[aria-current="page"]').textContent).toContain(
      'Analytics',
    )
    const tabs = get(h.root, 'nav[aria-label="Analytics pages"]')
    expect(
      [...tabs.querySelectorAll('a')].map((link) => link.textContent?.trim()),
    ).toEqual(['Overview', 'People'])
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(get(tabs, '[aria-current="page"]').textContent?.trim()).toBe(
      'People',
    )
    expect(tabs.querySelector('[role="tablist"]')).toBeNull()
    const list = get(h.root, '[data-id="hilos-table-cards"] [role="list"]')
    for (const projection of [table(h), list]) {
      const id = projection.getAttribute('aria-labelledby')
      expect(id).toBe(heading.id)
      expect(document.getElementById(id!)).toBe(heading)
      expect(projection.hasAttribute('aria-label')).toBe(false)
      const links = [
        ...projection.querySelectorAll(
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
            ...projection.querySelectorAll(
              `[data-id="hilos-analytics-person-${suffix}"]`,
            ),
          ].map((node) => node.getAttribute('data-record')),
        ).toEqual(['7', '610'])
      }
    }
    expect(
      [...table(h).querySelectorAll('thead th')]
        .slice(0, 3)
        .map((node) => node.textContent?.trim()),
    ).toEqual(['Person', 'Sessions', 'Last seen'])
    expect(get(table(h), '[aria-sort="descending"]').textContent).toContain(
      'Last seen',
    )
    expect(cells(h, 'name')).toEqual(['Ada (#7)', 'Deleted user #610'])
    expect(cells(h, 'sessions')).toEqual(['7', '0'])
    const context =
      h.controller.filter.get() as unknown as AnalyticsPeriodContext
    expect(cells(h, 'last-seen')).toEqual([
      formatAnalyticsPeopleTime(PERSON.lastSeenTs, context),
      formatAnalyticsPeopleTime(PERSON.lastSeenTs, context),
    ])
    expect(h.root.querySelector('[data-id="hilos-table-search"]')).toBeNull()
    expect(
      h.root.querySelector('[data-id="hilos-table-main-action"]'),
    ).toBeNull()
    click(h, 'a[data-id="hilos-analytics-person-link"]')
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/analytics/people/7')
  })

  it('keeps a viewer number linked beside Hidden and never formats hidden values', () => {
    const h = harness()
    const time = vi.spyOn(
      h.fixture.componentInstance as unknown as {
        time: typeof formatAnalyticsPeopleTime
      },
      'time',
    )
    h.identity()
    h.coldWindow([])
    h.window([
      [
        7,
        {
          name: { _hidden: true },
          sessionCount: { _hidden: true },
          lastSeenTs: { _hidden: true },
        },
      ],
    ])
    for (const projection of [table(h), get(h.root, '[role="list"]')]) {
      const link = get(projection, 'a[data-id="hilos-analytics-person-link"]')
      expect(link.textContent?.trim()).toBe('#7')
      expect(link.getAttribute('href')).toBe('/hilos/analytics/people/7')
      expect(
        get(projection, '[data-id="hilos-analytics-person-name"]').textContent,
      ).toContain('Hidden')
      for (const suffix of ['sessions', 'last-seen']) {
        expect(
          get(
            projection,
            `[data-id="hilos-analytics-person-${suffix}"]`,
          ).textContent?.trim(),
        ).toBe('Hidden')
      }
    }
    expect(time).not.toHaveBeenCalled()
    expect(h.root.innerHTML).not.toContain('_hidden')
    expect(h.root.innerHTML).not.toContain('1970')
    expect(h.root.innerHTML).not.toContain('Ada')
    expect(h.root.innerHTML).not.toContain(String(PERSON.lastSeenTs))
    expect(get(table(h), '[aria-sort]').getAttribute('aria-sort')).toBe('none')
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toBeNull()
  })

  it('keeps the person text when the project has no address for their page', () => {
    const h = harness(() => undefined)
    h.identity()
    h.coldWindow()
    h.window([[7, PERSON]])
    expect(
      h.root.querySelector('a[data-id="hilos-analytics-person-link"]'),
    ).toBeNull()
    const name = get(table(h), '[data-id="hilos-analytics-person-link"]')
    expect(name.textContent?.trim()).toBe('Ada (#7)')
    expect(name.hasAttribute('href')).toBe(false)
  })

  it('starts Today with one viewport and uses standard facets, choices and reset', () => {
    const h = harness()
    h.identity()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.coldWindow()
    h.window([[7, PERSON]])
    h.facets()
    const { period, ...context } = h.controller.filter.get()
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
    const control = get(h.root, '[data-id="hilos-table-filter-period"]')
    expect(
      get(control, '[data-id="hilos-dropdown-toggle"]').textContent?.trim(),
    ).toBe('Period: Today')
    click(h, '[data-id="hilos-dropdown-toggle"]', control)
    expect(
      [...control.querySelectorAll('[role="option"] > span:first-child')].map(
        (node) => node.textContent?.trim(),
      ),
    ).toEqual(['Any time', 'This hour', 'Today', 'This week'])
    for (const [value, count] of [
      ['any', '500+'],
      ['this-hour', '0'],
      ['today', '500'],
      ['this-week', '500+'],
    ]) {
      expect(
        get(
          control,
          `[data-id="hilos-table-facet-period-${value}"]`,
        ).textContent?.trim(),
      ).toBe(count)
    }
    expect(control.querySelectorAll('[aria-selected="true"]')).toHaveLength(1)
    expect(get(control, '[aria-selected="true"]').textContent).toContain(
      'Today',
    )
    click(h, '[data-id="hilos-dropdown-option-2"]', control)
    expect(h.controller.filter.get()).toEqual({
      ...context,
      period: 'this-week',
    })
    click(h, '[data-id="hilos-dropdown-toggle"]', control)
    click(h, '[data-id="hilos-dropdown-option--1"]', control)
    expect(h.controller.filter.get()).toEqual(context)
    expect(
      get(control, '[data-id="hilos-dropdown-toggle"]').textContent?.trim(),
    ).toBe('Period: Any time')
    expect(
      h.root.querySelector('[data-id="hilos-table-filter-badge"]'),
    ).toBeNull()
    click(h, '[data-id="hilos-dropdown-toggle"]', control)
    click(h, '[data-id="hilos-dropdown-option-0"]', control)
    click(h, '[data-id="hilos-table-filter-reset"]')
    expect(h.controller.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    expect(h.registered.get(TABLE)).toBe(h.controller)
  })

  it('keeps the captured zone and filter after the reader changes zones', () => {
    const previousZone = hilosTimeZone.get()
    const previousOwnZone = hilosOwnTimeZone.get()
    const previousBrowserZone = hilosBrowserTimeZone.get()
    const zoneChannel = channel()
    Object.assign(zoneChannel.connection, { projectTimeZone: 'UTC' })
    const release = bindTimeZoneState(zoneChannel.connection)
    const setZone = (timeZone: string | null) =>
      zoneChannel.emit(
        'projectSignal',
        {
          entities: { currentUser: { id: 99, timeZone } },
        },
        'handshake_response',
      )
    try {
      setZone('Europe/Warsaw')
      const h = harness()
      h.identity()
      h.coldWindow()
      h.window([[7, PERSON]])
      const filter = h.controller.filter.get()
      const before = cells(h, 'last-seen')
      expect(filter['timeZone']).toBe('Europe/Warsaw')
      expect(before).toEqual([
        formatAnalyticsPeopleTime(
          PERSON.lastSeenTs,
          filter as unknown as AnalyticsPeriodContext,
        ),
      ])
      setZone('America/New_York')
      h.fixture.detectChanges()
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(cells(h, 'last-seen')).toEqual(before)
      expect(h.controller.filter.get()).toEqual(filter)
      expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
      h.setContext({ connection: h.connection, scopes: h.scopes })
      expect(h.registered.get(TABLE)?.filter.get()['timeZone']).toBe(
        'America/New_York',
      )
    } finally {
      const options = new Intl.DateTimeFormat().resolvedOptions()
      const restoreBrowser = vi
        .spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions')
        .mockReturnValue({ ...options, timeZone: previousBrowserZone ?? '' })
      setZone(previousOwnZone)
      release()
      restoreBrowser.mockRestore()
      expect(hilosTimeZone.get()).toBe(previousZone)
    }
  })

  it('uses the standard skeleton, filtered reset, page empty and refusal with window recovery', async () => {
    vi.useFakeTimers()
    const h = harness()
    expect(
      get(h.root, '[data-id="hilos-page-head-skeleton"]').getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(h.root.querySelector('h1')).toBeNull()
    h.identity()
    h.coldWindow()
    await vi.advanceTimersByTimeAsync(400)
    h.fixture.detectChanges()
    expect(
      get(h.root, '[data-id="hilos-table-loading"]').getAttribute('aria-busy'),
    ).toBe('true')
    expect(
      h.root.querySelectorAll('[data-id="hilos-table-skeleton-row"]').length,
    ).toBeGreaterThan(0)
    expect(h.root.textContent).not.toContain('No people in the selected period')
    h.window([])
    const noMatches = get(h.root, '[data-id="hilos-table-no-matches"]')
    expect(noMatches.textContent).toContain('Nothing found')
    expect(
      get(noMatches, '[data-id="hilos-table-no-matches-terms"]').textContent,
    ).toContain('Period: Today')
    expect(h.root.querySelector('[data-id="hilos-table-empty"]')).toBeNull()
    expect(h.root.querySelector('[data-id="hilos-table-count"]')).toBeNull()
    const { period, ...context } = h.controller.filter.get()
    expect(period).toBe('today')
    click(h, '[data-id="hilos-table-no-matches-reset"]', noMatches)
    expect(h.controller.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    h.window([])
    expect(
      h.root.querySelector('[data-id="hilos-table-no-matches"]'),
    ).toBeNull()
    expect(get(h.root, '[data-id="hilos-table-empty"]').textContent).toContain(
      'No people in the selected period',
    )
    h.controller.setFilter('period', 'today')
    h.refuseWindow()
    expect(h.root.textContent).toContain('List unavailable')
    expect(h.root.querySelector('[data-id="hilos-table-empty"]')).toBeNull()
    expect(
      h.root.querySelector('[data-id="hilos-table-no-matches"]'),
    ).toBeNull()
    expect(get(h.root, 'h1').textContent?.trim()).toBe('Analytics')
    expect(
      get(
        h.root,
        'nav[aria-label="Analytics pages"] [aria-current="page"]',
      ).textContent?.trim(),
    ).toBe('People')
    const beforeReset = h.sendTableViewport.mock.calls.length
    click(h, '[data-id="hilos-table-filter-reset"]')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(beforeReset + 1)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    h.window([[7, PERSON]])
    expect(cells(h, 'name')).toEqual(['Ada (#7)'])
  })

  it('sends one Today viewport when the first page answer is replayed during mounting', () => {
    const h = harness(resolveHilosPath, true)
    h.identity()
    h.fixture.detectChanges()
    h.fixture.detectChanges()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: expect.objectContaining({ period: 'today' }),
      }),
    )
    h.window([[7, PERSON]])
    expect(cells(h, 'name')).toEqual(['Ada (#7)'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
  })

  it('uses the pager, mobile filters and order on the same controller', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window(
      Array.from({ length: 25 }, (_, index) => [index + 1, PERSON]),
      128,
    )
    expect(
      get(h.root, '[data-id="hilos-table-count"]').textContent?.trim(),
    ).toBe('1 – 25 of 128')
    expect(
      get(h.root, '[data-id="hilos-table-page-1"]').getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    click(h, '[data-id="hilos-table-page-2"]')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    h.window([[26, PERSON]], 128, true, 25)
    expect(
      get(h.root, '[data-id="hilos-table-count"]').textContent?.trim(),
    ).toBe('26 – 26 of 128')
    expect(
      get(h.root, '[data-id="hilos-table-page-2"]').getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    expect(get(h.root, '[role="listitem"] a').textContent?.trim()).toBe(
      'Ada (#26)',
    )
    click(h, '[data-id="hilos-table-filters-open"]')
    const modal = get(document, '[data-id="hilos-table-filter-period-modal"]')
    click(h, '[data-id="hilos-dropdown-toggle"]', modal)
    click(h, '[data-id="hilos-dropdown-option-0"]', modal)
    expect(h.controller.filter.get()['period']).toBe('this-hour')
    click(h, '[data-id="hilos-table-filters-done"]', document)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    click(h, '[data-id="hilos-table-sort-lastSeenTs"]')
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2]).toMatchObject({
      filter: expect.objectContaining({ period: 'this-hour' }),
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    h.window([[7, PERSON]], 500, false)
    expect(
      get(h.root, '[data-id="hilos-table-count"]').textContent?.trim(),
    ).toBe('1 – 1 of 500+')
    expect(h.root.querySelector('[data-id="hilos-table-page-1"]')).toBeNull()
    expect(
      get(h.root, '[data-id="hilos-table-next"]').textContent?.trim(),
    ).toBe('Next')
    expect(h.registered.get(TABLE)).toBe(h.controller)
  })

  it('replaces the controller and table on context change and releases old listeners', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([[7, PERSON]])
    const oldView = h.fixture.debugElement.query(
      By.directive(HilosViewportTable),
    ).componentInstance
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    h.setContext({ connection: h.connection, scopes: h.scopes })
    const next = h.registered.get(TABLE)!
    const nextView = h.fixture.debugElement.query(
      By.directive(HilosViewportTable),
    ).componentInstance
    expect(next).not.toBe(h.controller)
    expect(nextView).not.toBe(oldView)
    expect(h.unregisterTableWindow).toHaveBeenCalledWith(TABLE, h.controller)
    expect(h.controller.rows.get()).toEqual([])
    expect(h.root.querySelector('[data-record="7"]')).toBeNull()
    const nextListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(nextListeners).toHaveLength(oldListeners.length)
    expect(
      nextListeners.some((listener) => oldListeners.includes(listener)),
    ).toBe(false)
    h.coldWindow()
    h.window([[8, { ...PERSON, name: 'Bob' }]])
    expect(cells(h, 'name')).toEqual(['Bob (#8)'])
    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(next.rows.get()).toEqual([])
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
  })

  it('binds replacement scopes and connection and ignores the old transport', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([[7, PERSON]])
    const scopes = new ScopeManager()
    scopes.openPage(PAGE)
    const next = channel(true)
    h.setContext({ connection: next.connection, scopes })
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.root.querySelector('[data-record="7"]')).toBeNull()
    expect(next.registered.size).toBe(1)
    expect(next.sendTableViewport).toHaveBeenCalledTimes(1)
    h.window([[9, PERSON]])
    h.scopes.dropPage()
    expect(next.registered.size).toBe(1)
    expect(next.registered.get(TABLE)?.rows.get()).toEqual([])
    h.fixture.destroy()
    expect(next.registered.size).toBe(0)
    expect([...next.listeners.values()].every((set) => set.size === 0)).toBe(
      true,
    )
  })

  it.each(['replace', 'leave', 'close'] as const)(
    'releases the real binding when the page scope changes: %s',
    (change) => {
      const h = harness()
      h.identity()
      h.coldWindow()
      h.window([[7, PERSON]])
      if (change === 'close') h.scopes.dropPage()
      else h.scopes.openPage(change === 'replace' ? PAGE : HilosPages.ANALYTICS)
      h.fixture.detectChanges()
      expect(h.registered.size).toBe(0)
      expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(
        true,
      )
      expect(h.controller.rows.get()).toEqual([])
      h.window([[8, PERSON]])
      expect(h.root.querySelector('[data-record="8"]')).toBeNull()
      h.fixture.destroy()
      expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    },
  )
})
