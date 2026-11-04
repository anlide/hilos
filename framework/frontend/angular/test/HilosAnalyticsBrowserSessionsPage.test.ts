// Browser sessions over the real Angular table, head, links and hideable cells.
import { TestBed } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  bindTimeZoneState,
  computedSignal,
  createAppPageRouter,
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
  formatAnalyticsBrowserSessionListTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsContext,
  type HilosAnalyticsBrowserSessionListRow,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsBrowserSessionsPage as exportedPage } from '@hilos/angular/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsBrowserSessionsPage } from '../src/admin/analytics/HilosAnalyticsBrowserSessionsPage.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
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
const SUFFIXES = [
  'session',
  'person',
  'browser',
  'first-seen',
  'last-seen',
  'actions',
]
const ROOT = '/hilos/analytics/browser-sessions'
const paths = createAppPageRouter({})
type Listener = (signal: { type?: string; data: unknown }) => void

/** The complete cold window, also replayed for a late mount. */
function pageAnswer(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
  return {
    page: PAGE,
    payload: {
      windows: {
        [TABLE]: {
          filter: {},
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
  const unregisterTableWindow = vi.fn(
    (
      key: string,
      source: TableViewportController<HilosAnalyticsBrowserSessionListRow>,
    ) => {
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
      source: TableViewportController<HilosAnalyticsBrowserSessionListRow>,
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

function harness({
  resolvePath = resolveHilosPath,
  buffered = false,
  tail = '',
}: {
  resolvePath?: HilosRouter['resolvePath']
  buffered?: boolean
  tail?: string
} = {}) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const identity = createSignal(pageIdentity(scopes))
  const currentRoute = createSignal(paths.match(`${ROOT}${tail}`))
  const currentPath = createSignal(`${ROOT}${tail}`)
  const router: HilosRouter = {
    currentRoute,
    currentPath,
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
    replacePath: vi.fn((path: string) => {
      currentPath.set(path)
      currentRoute.set(paths.match(path))
    }),
    start: () => {},
    stop: () => {},
  }
  const transport = channel(buffered)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosAnalyticsBrowserSessionsPage)
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
    visit(path: string) {
      currentPath.set(path)
      currentRoute.set(paths.match(path))
      fixture.detectChanges()
    },
    setContext(context: HilosAnalyticsContext) {
      identity.set(pageIdentity(context.scopes))
      fixture.componentRef.setInput('context', context)
      fixture.detectChanges()
    },
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
      fixture.detectChanges()
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
        requestId: transport.sendTableViewport.mock.calls.at(-1)?.[2].requestId,
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
  return get(
    h.root,
    '[data-id="hilos-analytics-browser-session-list-table"] table',
  )
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
      `[data-id="hilos-analytics-browser-session-list-${suffix}"]`,
    ),
  ].map((node) => node.textContent?.trim() ?? '')
}

function click(
  h: Harness,
  target: string | HTMLElement,
  root: ParentNode = h.root,
) {
  const element = typeof target === 'string' ? get(root, target) : target
  element.click()
  h.fixture.detectChanges()
}

function column(root: ParentNode, suffix: string): HTMLElement[] {
  return [
    ...root.querySelectorAll<HTMLElement>(
      `[data-id="hilos-analytics-browser-session-list-${suffix}"]`,
    ),
  ]
}

function attributeValues(h: Harness, name: string): string[] {
  return [...h.root.querySelectorAll(`[${name}]`)].map(
    (node) => node.getAttribute(name) ?? '',
  )
}

describe('HilosAnalyticsBrowserSessionsPage', () => {
  it('exports the contextual page and draws the six cells for signed-in, guest and deleted sessions in both projections', () => {
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
      [72, { ...SESSION, lastSignedInUserId: 610, lastSignedInUserName: null }],
    ])

    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    const heading = h.root.querySelector<HTMLElement>('h1')!
    expect(heading.textContent?.trim()).toBe('Analytics')
    expect(
      table(h).querySelector<HTMLElement>('[aria-sort="descending"]')
        ?.textContent,
    ).toContain('Last seen')

    for (const projection of [table(h), get(h.root, '[role="list"]')]) {
      expect(projection.getAttribute('aria-labelledby')).toBe(
        heading.getAttribute('id'),
      )
      const sessions = column(projection, 'session')
      expect(sessions.map((node) => node.textContent?.trim())).toEqual([
        '#64',
        '#71',
        '#72',
      ])
      expect(sessions.map((node) => node.getAttribute('href'))).toEqual([
        `${ROOT}/64`,
        `${ROOT}/71`,
        `${ROOT}/72`,
      ])
      const people = column(projection, 'person')
      expect(people.map((node) => node.textContent?.trim())).toEqual([
        'Ada (#12)',
        'Not signed in',
        'Deleted user #610',
      ])
      expect(
        people.map((node) =>
          node.querySelector<HTMLElement>('a')?.getAttribute('href'),
        ),
      ).toEqual([
        '/hilos/analytics/people/12',
        undefined,
        '/hilos/analytics/people/610',
      ])
      for (const suffix of SUFFIXES) {
        expect(
          column(projection, suffix).map((node) =>
            node.getAttribute('data-record'),
          ),
        ).toEqual(['64', '71', '72'])
      }
    }
    expect(cells(h, 'browser')).toEqual([
      'Firefox 131 · Windows',
      '—',
      'Firefox 131 · Windows',
    ])
    expect(cells(h, 'actions')).toEqual(['7', '0', '7'])
    const periodContext =
      h.controller.filter.get() as unknown as AnalyticsPeriodContext
    expect(cells(h, 'first-seen')).toEqual(
      Array(3).fill(
        formatAnalyticsBrowserSessionListTime(
          SESSION.firstSeenTs,
          periodContext,
        ),
      ),
    )
    expect(cells(h, 'last-seen')).toEqual(
      Array(3).fill(
        formatAnalyticsBrowserSessionListTime(
          SESSION.lastSeenTs,
          periodContext,
        ),
      ),
    )
    expect(new Set(attributeValues(h, 'data-record'))).toEqual(
      new Set(['64', '71', '72']),
    )
    click(h, column(table(h), 'session')[0]!)
    expect(h.router.navigate).toHaveBeenLastCalledWith(`${ROOT}/64`)
    click(h, get(column(table(h), 'person')[0]!, 'a'))
    expect(h.router.navigate).toHaveBeenLastCalledWith(
      '/hilos/analytics/people/12',
    )
  })

  it('hides either account field without an account address and keeps public session links', () => {
    const h = harness()
    const time = vi.spyOn(
      h.fixture.componentInstance as unknown as {
        time: typeof formatAnalyticsBrowserSessionListTime
      },
      'time',
    )
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

    for (const projection of [table(h), get(h.root, '[role="list"]')]) {
      const people = column(projection, 'person')
      expect(people.map((node) => node.textContent?.trim())).toEqual([
        'Hidden',
        'Hidden',
        'Hidden',
      ])
      expect(
        people.every((node) => node.querySelector<HTMLElement>('a') === null),
      ).toBe(true)
      expect(
        column(projection, 'session').map((node) => node.getAttribute('href')),
      ).toEqual([`${ROOT}/64`, `${ROOT}/65`, `${ROOT}/66`])
      for (const suffix of ['browser', 'first-seen', 'last-seen', 'actions']) {
        expect(column(projection, suffix)[0]?.textContent?.trim()).toBe(
          'Hidden',
        )
      }
    }
    expect(new Set(attributeValues(h, 'data-record'))).toEqual(
      new Set(['64', '65', '66']),
    )
    expect(attributeValues(h, 'href').join(' ')).not.toContain('/people/')
    expect(h.root.innerHTML).not.toContain('_hidden')
    expect(h.root.textContent).not.toContain('Ada')
    expect(h.root.textContent).not.toContain('#12')
    expect(h.root.textContent).not.toContain('1970')
    expect(time).toHaveBeenCalled()
    expect(time.mock.calls.every(([value]) => typeof value === 'number')).toBe(
      true,
    )
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toBeNull()
  })

  it('keeps visible text when the project does not serve the target pages', () => {
    const h = harness({ resolvePath: () => undefined })
    h.identity()
    h.coldWindow()
    h.window([
      [64, SESSION],
      [72, { ...SESSION, lastSignedInUserId: 610, lastSignedInUserName: null }],
    ])

    expect(cells(h, 'session')).toEqual(['#64', '#72'])
    expect(cells(h, 'person')).toEqual(['Ada (#12)', 'Deleted user #610'])
    expect(table(h).querySelectorAll('a')).toHaveLength(0)
    expect(h.root.innerHTML).not.toContain('href="#"')
    expect(
      column(table(h), 'session').map((node) =>
        node.getAttribute('data-record'),
      ),
    ).toEqual(['64', '72'])
  })

  it('formats against the captured zone after the reader changes zones', () => {
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
      h.window([[64, SESSION]])
      const filter = h.controller.filter.get()
      const first = cells(h, 'first-seen')
      const before = cells(h, 'last-seen')
      expect(filter['timeZone']).toBe('Europe/Warsaw')
      expect(first).toEqual([
        formatAnalyticsBrowserSessionListTime(
          SESSION.firstSeenTs,
          filter as unknown as AnalyticsPeriodContext,
        ),
      ])
      expect(before).toEqual([
        formatAnalyticsBrowserSessionListTime(
          SESSION.lastSeenTs,
          filter as unknown as AnalyticsPeriodContext,
        ),
      ])
      setZone('America/New_York')
      h.fixture.detectChanges()
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(cells(h, 'first-seen')).toEqual(first)
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

  it.each([
    ['a bare address', '', 'today'],
    ['an explicit period', '/this-week', 'this-week'],
    ['an unknown period', '/yesterday', 'today'],
    ['Any time', '/any-time', undefined],
  ] as const)('asks its first viewport once for %s', (_name, tail, period) => {
    const h = harness({ tail })
    h.identity()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.coldWindow()

    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    const filter = h.sendTableViewport.mock.calls[0]?.[2].filter
    if (period === undefined) expect(filter).not.toHaveProperty('period')
    else expect(filter).toMatchObject({ period })
    expect(filter).toMatchObject({
      timeZone: expect.any(String),
      weekStartTs: expect.any(Number),
      dayStartTs: expect.any(Number),
      hourStartTs: expect.any(Number),
    })
    expect(h.router.replacePath).not.toHaveBeenCalled()
    h.window([[64, SESSION]])
    expect(cells(h, 'session')).toEqual(['#64'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
  })

  it('replays one viewport of the addressed period from a buffered answer', () => {
    const h = harness({ buffered: true, tail: '/this-hour' })
    h.identity()

    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: expect.objectContaining({ period: 'this-hour' }),
      }),
    )
    expect(h.unregisterTableWindow).not.toHaveBeenCalled()
    h.window([[64, SESSION]])
    expect(cells(h, 'session')).toEqual(['#64'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.router.replacePath).not.toHaveBeenCalled()
  })

  it('drives Period, facets and Reset through the controller and keeps the address in step without loops', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([[64, SESSION]])
    h.facets()
    expect(h.sendTableFacets).toHaveBeenCalledWith(PAGE, TABLE, {
      period: ['this-hour', 'today', 'this-week'],
    })

    const { period, ...calendar } = h.controller.filter.get()
    expect(period).toBe('today')
    const control = h.root.querySelector<HTMLElement>(
      '[data-id="hilos-table-filter-period"]',
    )!
    const toggle = control.querySelector<HTMLElement>(
      '[data-id="hilos-dropdown-toggle"]',
    )!
    expect(toggle.textContent).toContain('Period: Today')
    click(h, toggle)
    expect(
      get(control, '[role="option"][aria-selected="true"]').textContent,
    ).toContain('Today')
    for (const [value, count] of [
      ['any', '500+'],
      ['this-hour', '0'],
      ['today', '500'],
      ['this-week', '500+'],
    ]) {
      expect(
        control.querySelector<HTMLElement>(
          `[data-id="hilos-table-facet-period-${value}"]`,
        )?.textContent,
      ).toBe(count)
    }
    click(
      h,
      control.querySelector<HTMLElement>(
        '[data-id="hilos-dropdown-option-2"]',
      )!,
    )
    expect(h.controller.filter.get().period).toBe('this-week')
    expect(h.router.replacePath).toHaveBeenCalledTimes(1)
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/this-week`)
    expect(h.router.currentRoute.get().params).toEqual({ period: 'this-week' })
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toMatchObject({
      period: 'this-week',
    })

    click(
      h,
      h.root.querySelector<HTMLElement>(
        '[data-id="hilos-table-filter-reset"]',
      )!,
    )
    expect(h.controller.filter.get()).toEqual(calendar)
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/any-time`)
    expect(h.sendTableViewport).toHaveBeenCalledTimes(3)
    expect(
      h.sendTableViewport.mock.calls.at(-1)?.[2].filter,
    ).not.toHaveProperty('period')

    // Back to an address of the same page moves the filter, not the address.
    const controller = h.controller
    h.visit(`${ROOT}/this-hour`)
    expect(h.controller.filter.get().period).toBe('this-hour')
    expect(toggle.textContent).toContain('Period: This hour')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(4)
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
    h.visit(ROOT)
    h.visit(`${ROOT}/today`)
    expect(h.registered.get(TABLE)).toBe(controller)
    expect(h.unregisterTableWindow).not.toHaveBeenCalled()
    expect(h.controller.filter.get().period).toBe('today')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(5)
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
  })

  it('uses paging and order on the same controller', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window(
      Array.from(
        { length: 25 },
        (_, index): [number, Record<string, unknown>] => [index + 1, SESSION],
      ),
      128,
    )
    const controller = h.controller
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-table-count"]')
        ?.textContent?.trim(),
    ).toBe('1 – 25 of 128')
    click(
      h,
      h.root.querySelector<HTMLElement>('[data-id="hilos-table-page-2"]')!,
    )
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    h.window([[26, SESSION]], 128, true, 25)
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-table-count"]')
        ?.textContent?.trim(),
    ).toBe('26 – 26 of 128')
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-table-page-2"]')
        ?.getAttribute('aria-current'),
    ).toBe('page')

    const sort = table(h).querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-sort-lastSeenTs"]',
    )!
    sort.focus()
    expect(document.activeElement).toBe(sort)
    click(h, sort)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2]).toMatchObject({
      filter: expect.objectContaining({ period: 'today' }),
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    h.window([[64, SESSION]], 500, false)
    expect(
      table(h).querySelector<HTMLElement>('[aria-sort="ascending"]')
        ?.textContent,
    ).toContain('Last seen')
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-table-count"]')
        ?.textContent?.trim(),
    ).toBe('1 – 1 of 500+')
    expect(
      h.root.querySelector<HTMLElement>('[data-id="hilos-table-next"]')
        ?.textContent,
    ).toContain('Next')
    expect(h.controller).toBe(controller)
    expect(h.router.replacePath).not.toHaveBeenCalled()
  })

  it('shows loading, empty and refusal states and retries through the window controls', async () => {
    const h = harness()
    vi.useFakeTimers()
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-page-head-skeleton"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.root.querySelector<HTMLElement>('h1')).toBeNull()
    h.identity()
    h.coldWindow()
    await vi.advanceTimersByTimeAsync(400)
    h.fixture.detectChanges()
    expect(
      h.root
        .querySelector<HTMLElement>('[data-id="hilos-table-loading"]')
        ?.getAttribute('aria-busy'),
    ).toBe('true')

    h.window([])
    const noMatches = h.root.querySelector<HTMLElement>(
      '[data-id="hilos-table-no-matches"]',
    )!
    expect(noMatches.textContent).toContain('Nothing found')
    expect(
      noMatches.querySelector<HTMLElement>(
        '[data-id="hilos-table-no-matches-terms"]',
      )?.textContent,
    ).toContain('Period: Today')
    click(
      h,
      noMatches.querySelector<HTMLElement>(
        '[data-id="hilos-table-no-matches-reset"]',
      )!,
    )
    expect(h.controller.filter.get()).not.toHaveProperty('period')
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/any-time`)

    h.window([])
    expect(
      h.root.querySelector<HTMLElement>('[data-id="hilos-table-empty"]')
        ?.textContent,
    ).toContain('No browser sessions in the selected period')
    expect(h.root.textContent).not.toContain('Nothing found')

    h.controller.setFilter('period', 'today')
    h.fixture.detectChanges()
    h.refuseWindow()
    expect(h.root.textContent).toContain('List unavailable')
    expect(
      h.root.querySelector<HTMLElement>('[data-id="hilos-table-empty"]'),
    ).toBeNull()

    // A refused list keeps its controls: changing the window asks again.
    const asked = h.sendTableViewport.mock.calls.length
    const control = h.root.querySelector<HTMLElement>(
      '[data-id="hilos-table-filter-period"]',
    )!
    click(
      h,
      control.querySelector<HTMLElement>('[data-id="hilos-dropdown-toggle"]')!,
    )
    click(
      h,
      control.querySelector<HTMLElement>(
        '[data-id="hilos-dropdown-option-0"]',
      )!,
    )
    expect(h.sendTableViewport).toHaveBeenCalledTimes(asked + 1)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toMatchObject({
      period: 'this-hour',
    })
    h.window([[64, SESSION]])
    expect(h.root.textContent).not.toContain('List unavailable')
    expect(cells(h, 'session')).toEqual(['#64'])
  })

  it('replaces the controller and table on context change and releases old listeners', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([[64, SESSION]])
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
    expect(h.root.querySelector<HTMLElement>('[data-record="64"]')).toBeNull()
    const nextListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(nextListeners).toHaveLength(oldListeners.length)
    expect(
      nextListeners.some((listener) => oldListeners.includes(listener)),
    ).toBe(false)
    h.coldWindow()
    h.window([[8, { ...SESSION, lastSignedInUserName: 'Bob' }]])
    expect(cells(h, 'person')).toEqual(['Bob (#12)'])
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
    h.window([[64, SESSION]])
    const scopes = new ScopeManager()
    scopes.openPage(PAGE)
    const next = channel(true)
    h.setContext({ connection: next.connection, scopes })
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.root.querySelector<HTMLElement>('[data-record="64"]')).toBeNull()
    expect(next.registered.size).toBe(1)
    expect(next.sendTableViewport).toHaveBeenCalledTimes(1)
    h.window([[9, SESSION]])
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
      h.window([[64, SESSION]])
      if (change === 'close') h.scopes.dropPage()
      else h.scopes.openPage(change === 'replace' ? PAGE : HilosPages.ANALYTICS)
      h.fixture.detectChanges()
      expect(h.registered.size).toBe(0)
      expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(
        true,
      )
      expect(h.controller.rows.get()).toEqual([])
      h.window([[8, SESSION]])
      expect(h.root.querySelector<HTMLElement>('[data-record="8"]')).toBeNull()
      h.fixture.destroy()
      expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    },
  )
})
