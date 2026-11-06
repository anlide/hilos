// The React Browser sessions page over the real core table factory, scopes,
// shared head, table, links and hideable values.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindTimeZoneState,
  createAppPageRouter,
  createSignal,
  HilosPages,
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
  ANALYTICS_BROWSER_SESSION_LIST_TABLE,
  formatAnalyticsBrowserSessionListTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsBrowserSessionListRow,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsBrowserSessionsPage as exportedPage } from '@hilos/react/admin/analytics'

import { HilosAnalyticsBrowserSessionsPage } from '../src/admin/analytics/HilosAnalyticsBrowserSessionsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.ANALYTICS_BROWSER_SESSIONS
const TABLE = ANALYTICS_BROWSER_SESSION_LIST_TABLE
const ROOT = '/hilos/analytics/browser-sessions'
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
const paths = createAppPageRouter({})
type Listener = (signal: { type?: string; data: unknown }) => void
type Controller = TableViewportController<HilosAnalyticsBrowserSessionListRow>

/** The first list answer, also replayed for a late table binding. */
function pageAnswer(
  sort: Array<{ field: string; direction: 'asc' | 'desc' }> = [
    { field: 'lastSeenTs', direction: 'desc' },
  ],
) {
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

/**
 * A fake connection carrying the real table registration and listener shape.
 *
 * @param buffered Whether a page answer is replayed to a late listener.
 */
function fakeConnection(buffered: boolean) {
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<string, Controller>()
  const sendTableViewport = vi.fn<
    (
      page: string,
      table: string,
      descriptor: TableViewportDescriptor,
    ) => boolean
  >(() => true)
  const sendTableFacets = vi.fn(() => true)
  // Like the real connection, a stale binding cannot drop its successor.
  const unregisterTableWindow = vi.fn((key: string, source?: Controller) => {
    if (source === undefined || registered.get(key) === source)
      registered.delete(key)
  })
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
    registerTableWindow(key: string, source: Controller) {
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
    listening(): number {
      return [...listeners.values()].reduce((sum, set) => sum + set.size, 0)
    },
  }
}

/**
 * A navigator whose same-page address rewrite publishes the matched route, as
 * the core router's replacePath does.
 *
 * @param scopes The page scopes the head reads its identity from.
 * @param tail The list address tail the page opens on.
 * @param resolvePath The project's path resolver.
 */
function fakeRouter(
  scopes: ScopeManager,
  tail: string,
  resolvePath: HilosRouter['resolvePath'],
) {
  const currentRoute = createSignal(paths.match(`${ROOT}${tail}`))
  const currentPath = createSignal(`${ROOT}${tail}`)
  const router = {
    currentRoute,
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
      currentRoute.set(paths.match(path))
    }),
    start: () => {},
    stop: () => {},
  } satisfies HilosRouter

  return {
    router,
    /** Same-page history move, such as Back or Forward. */
    visit(path: string) {
      act(() => {
        currentPath.set(path)
        currentRoute.set(paths.match(path))
      })
    },
  }
}

function harness({
  resolvePath = resolveHilosPath,
  buffered = false,
  strictMode = false,
  tail = '',
}: {
  resolvePath?: HilosRouter['resolvePath']
  buffered?: boolean
  strictMode?: boolean
  tail?: string
} = {}) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const wire = fakeConnection(buffered)
  const navigator = fakeRouter(scopes, tail, resolvePath)
  const context: HilosAnalyticsContext = { connection: wire.connection, scopes }
  function tree(nextContext: HilosAnalyticsContext, router: HilosRouter) {
    const app = (
      <HilosRouterContext.Provider value={router}>
        <HilosAnalyticsBrowserSessionsPage context={nextContext} />
      </HilosRouterContext.Provider>
    )
    return strictMode ? <StrictMode>{app}</StrictMode> : app
  }
  const view = render(tree(context, navigator.router))

  function emit(event: string, data: unknown, type?: string) {
    act(() => {
      for (const listener of wire.listeners.get(event) ?? [])
        listener({ type, data })
    })
  }

  return {
    ...wire,
    ...navigator,
    view,
    scopes,
    context,
    rerender(nextContext: HilosAnalyticsContext, router: HilosRouter) {
      view.rerender(tree(nextContext, router))
    },
    get controller() {
      return wire.registered.get(TABLE)
    },
    identity() {
      act(() => {
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
      })
    },
    coldWindow(sort?: Parameters<typeof pageAnswer>[0]) {
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
        requestId: wire.sendTableViewport.mock.calls.at(-1)?.[2].requestId,
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
        requestId: wire.sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        errorCode: 'unavailable',
      })
    },
  }
}

type View = ReturnType<typeof harness>['view']

/** The wide projection of the shared table. */
function table(view: View): HTMLElement {
  const found = view.container.querySelector<HTMLElement>(
    '[data-id="hilos-analytics-browser-session-list-table"] table',
  )
  if (!found) throw new Error('The Browser sessions table is not rendered.')
  return found
}

/** The narrow projection: the same cells drawn as a list of cards. */
function cards(view: View): HTMLElement {
  const found = view.container.querySelector<HTMLElement>(
    '[data-id="hilos-table-cards"] [role="list"]',
  )
  if (!found) throw new Error('The Browser sessions cards are not rendered.')
  return found
}

/**
 * Read one column of a projection by its cell handle.
 *
 * @param root The projection, or the whole page.
 * @param suffix The cell's data-id suffix.
 */
function column(root: ParentNode, suffix: string): HTMLElement[] {
  return [
    ...root.querySelectorAll<HTMLElement>(
      `[data-id="hilos-analytics-browser-session-list-${suffix}"]`,
    ),
  ]
}

/**
 * Read the visible text of one column in the wide projection.
 *
 * @param view The rendered page.
 * @param suffix The cell's data-id suffix.
 */
function cells(view: View, suffix: string): string[] {
  return column(table(view), suffix).map(
    (node) => node.textContent?.trim() ?? '',
  )
}

/** Flush the page's effect-owned controller start and deferred cleanup. */
async function flush(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

/** Every value of a named attribute on the rendered page. */
function attributeValues(view: View, name: string): string[] {
  return [...view.container.querySelectorAll(`[${name}]`)].map(
    (node) => node.getAttribute(name) ?? '',
  )
}

describe('HilosAnalyticsBrowserSessionsPage', () => {
  it('exports the contextual page and draws the six cells for signed-in, guest and deleted sessions in both projections', async () => {
    expect(exportedPage).toBe(HilosAnalyticsBrowserSessionsPage)
    const h = harness()
    await flush()
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

    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    const heading = h.view.container.querySelector('h1')!
    expect(heading.textContent?.trim()).toBe('Analytics')
    expect(h.view.container.textContent).toContain(LEAD)
    const tabs = h.view.container.querySelector(
      'nav[aria-label="Analytics pages"]',
    )!
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    const current = tabs.querySelector('[aria-current="page"]')!
    expect(current.textContent).toBe('Browser sessions')
    expect(current.getAttribute('href')).toBe(ROOT)
    expect(tabs.querySelector('[role="tablist"]')).toBeNull()
    expect(
      table(h.view).querySelector('[aria-sort="descending"]')?.textContent,
    ).toContain('Last seen')

    for (const projection of [table(h.view), cards(h.view)]) {
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
        '/hilos/analytics/browser-session/64',
        '/hilos/analytics/browser-session/71',
        '/hilos/analytics/browser-session/72',
      ])
      const people = column(projection, 'person')
      expect(people.map((node) => node.textContent?.trim())).toEqual([
        'Ada (#12)',
        'Not signed in',
        'Deleted user #610',
      ])
      expect(
        people.map((node) => node.querySelector('a')?.getAttribute('href')),
      ).toEqual([
        '/hilos/analytics/person/12',
        undefined,
        '/hilos/analytics/person/610',
      ])
      for (const suffix of SUFFIXES) {
        expect(
          column(projection, suffix).map((node) =>
            node.getAttribute('data-record'),
          ),
        ).toEqual(['64', '71', '72'])
      }
    }
    expect(cells(h.view, 'browser')).toEqual([
      'Firefox 131 · Windows',
      '—',
      'Firefox 131 · Windows',
    ])
    expect(cells(h.view, 'actions')).toEqual(['7', '0', '7'])
    const periodContext =
      h.controller!.filter.get() as unknown as AnalyticsPeriodContext
    expect(cells(h.view, 'first-seen')).toEqual(
      Array(3).fill(
        formatAnalyticsBrowserSessionListTime(
          SESSION.firstSeenTs,
          periodContext,
        ),
      ),
    )
    expect(cells(h.view, 'last-seen')).toEqual(
      Array(3).fill(
        formatAnalyticsBrowserSessionListTime(
          SESSION.lastSeenTs,
          periodContext,
        ),
      ),
    )
    expect(new Set(attributeValues(h.view, 'data-record'))).toEqual(
      new Set(['64', '71', '72']),
    )
  })

  it('hides either account field without an account address and keeps public session links', async () => {
    const h = harness()
    await flush()
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

    for (const projection of [table(h.view), cards(h.view)]) {
      const people = column(projection, 'person')
      expect(people.map((node) => node.textContent?.trim())).toEqual([
        'Hidden',
        'Hidden',
        'Hidden',
      ])
      expect(people.every((node) => node.querySelector('a') === null)).toBe(
        true,
      )
      expect(
        column(projection, 'session').map((node) => node.getAttribute('href')),
      ).toEqual([
        '/hilos/analytics/browser-session/64',
        '/hilos/analytics/browser-session/65',
        '/hilos/analytics/browser-session/66',
      ])
      for (const suffix of ['browser', 'first-seen', 'last-seen', 'actions']) {
        expect(column(projection, suffix)[0]?.textContent?.trim()).toBe(
          'Hidden',
        )
      }
    }
    expect(new Set(attributeValues(h.view, 'data-record'))).toEqual(
      new Set(['64', '65', '66']),
    )
    expect(attributeValues(h.view, 'href').join(' ')).not.toContain('/people/')
    expect(h.view.container.innerHTML).not.toContain('_hidden')
    expect(h.view.container.textContent).not.toContain('Ada')
    expect(h.view.container.textContent).not.toContain('#12')
    expect(h.view.container.textContent).not.toContain('1970')
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toBeNull()
  })

  it('keeps visible text when the project does not serve the target pages', async () => {
    const h = harness({ resolvePath: () => undefined })
    await flush()
    h.identity()
    h.coldWindow()
    h.window([
      [64, SESSION],
      [72, { ...SESSION, lastSignedInUserId: 610, lastSignedInUserName: null }],
    ])

    expect(cells(h.view, 'session')).toEqual(['#64', '#72'])
    expect(cells(h.view, 'person')).toEqual(['Ada (#12)', 'Deleted user #610'])
    expect(table(h.view).querySelectorAll('a')).toHaveLength(0)
    expect(h.view.container.innerHTML).not.toContain('href="#"')
    expect(
      column(table(h.view), 'session').map((node) =>
        node.getAttribute('data-record'),
      ),
    ).toEqual(['64', '72'])
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
      await flush()
      h.identity()
      h.coldWindow()
      h.window([[64, SESSION]])
      const filter = h.controller!.filter.get()
      const captured = filter as unknown as AnalyticsPeriodContext
      expect(filter.timeZone).toBe('Europe/Warsaw')
      const before = {
        first: cells(h.view, 'first-seen'),
        last: cells(h.view, 'last-seen'),
      }
      expect(before).toEqual({
        first: [
          formatAnalyticsBrowserSessionListTime(SESSION.firstSeenTs, captured),
        ],
        last: [
          formatAnalyticsBrowserSessionListTime(SESSION.lastSeenTs, captured),
        ],
      })

      act(() => setZone('America/New_York'))
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(cells(h.view, 'first-seen')).toEqual(before.first)
      expect(cells(h.view, 'last-seen')).toEqual(before.last)
      expect(h.controller!.filter.get()).toEqual(filter)
      expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    } finally {
      act(() => setZone(previousZone))
      release()
    }
  })

  it.each([
    ['a bare address', '', 'today'],
    ['an explicit period', '/this-week', 'this-week'],
    ['an unknown period', '/yesterday', 'today'],
    ['Any time', '/any-time', undefined],
  ])('asks its first viewport once for %s', async (_name, tail, period) => {
    const h = harness({ tail })
    await flush()
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
    expect(cells(h.view, 'session')).toEqual(['#64'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
  })

  it('replays one viewport of the addressed period from a buffered answer under StrictMode', async () => {
    const h = harness({ buffered: true, strictMode: true, tail: '/this-hour' })
    await flush()
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
    expect(cells(h.view, 'session')).toEqual(['#64'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.router.replacePath).not.toHaveBeenCalled()
  })

  it('drives Period, facets and Reset through the controller and keeps the address in step without loops', async () => {
    const h = harness()
    await flush()
    h.identity()
    h.coldWindow()
    h.window([[64, SESSION]])
    h.facets()
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
    fireEvent.click(
      control.querySelector('[data-id="hilos-dropdown-option-2"]')!,
    )
    expect(h.controller!.filter.get().period).toBe('this-week')
    expect(h.router.replacePath).toHaveBeenCalledTimes(1)
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/this-week`)
    expect(h.router.currentRoute.get().params).toEqual({ period: 'this-week' })
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toMatchObject({
      period: 'this-week',
    })

    fireEvent.click(
      h.view.container.querySelector('[data-id="hilos-table-filter-reset"]')!,
    )
    expect(h.controller!.filter.get()).not.toHaveProperty('period')
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/any-time`)
    expect(h.sendTableViewport).toHaveBeenCalledTimes(3)
    expect(
      h.sendTableViewport.mock.calls.at(-1)?.[2].filter,
    ).not.toHaveProperty('period')

    // Back to an address of the same page moves the filter, not the address.
    h.visit(`${ROOT}/this-hour`)
    expect(h.controller!.filter.get().period).toBe('this-hour')
    expect(toggle.textContent).toContain('Period: This hour')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(4)
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
    h.visit(ROOT)
    h.visit(`${ROOT}/today`)
    expect(h.controller!.filter.get().period).toBe('today')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(5)
    expect(h.router.replacePath).toHaveBeenCalledTimes(2)
  })

  it('uses paging and order on the same controller', async () => {
    const h = harness()
    await flush()
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
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('1 – 25 of 128')
    fireEvent.click(
      h.view.container.querySelector('[data-id="hilos-table-page-2"]')!,
    )
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    h.window([[26, SESSION]], 128, true, 25)
    expect(
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('26 – 26 of 128')
    expect(
      h.view.container
        .querySelector('[data-id="hilos-table-page-2"]')
        ?.getAttribute('aria-current'),
    ).toBe('page')

    const sort = table(h.view).querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-sort-lastSeenTs"]',
    )!
    expect(sort.tagName).toBe('BUTTON')
    sort.focus()
    expect(document.activeElement).toBe(sort)
    fireEvent.click(sort)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2]).toMatchObject({
      filter: expect.objectContaining({ period: 'today' }),
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    h.window([[64, SESSION]], 500, false)
    expect(
      table(h.view).querySelector('[aria-sort="ascending"]')?.textContent,
    ).toContain('Last seen')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-count"]')
        ?.textContent,
    ).toBe('1 – 1 of 500+')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-next"]')
        ?.textContent,
    ).toContain('Next')
    expect(h.controller).toBe(controller)
    expect(h.router.replacePath).not.toHaveBeenCalled()
  })

  it('shows loading, filtered empty, page empty and refusal under the shared head, and asks again from the controls', async () => {
    const h = harness()
    await flush()
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

    h.window([])
    const noMatches = h.view.container.querySelector(
      '[data-id="hilos-table-no-matches"]',
    )!
    expect(noMatches.textContent).toContain('Nothing found')
    expect(
      noMatches.querySelector('[data-id="hilos-table-no-matches-terms"]')
        ?.textContent,
    ).toContain('Period: Today')
    fireEvent.click(
      noMatches.querySelector('[data-id="hilos-table-no-matches-reset"]')!,
    )
    expect(h.controller!.filter.get()).not.toHaveProperty('period')
    expect(h.router.replacePath).toHaveBeenLastCalledWith(`${ROOT}/any-time`)

    h.window([])
    expect(
      h.view.container.querySelector('[data-id="hilos-table-empty"]')
        ?.textContent,
    ).toContain('No browser sessions in the selected period')
    expect(h.view.container.textContent).not.toContain('Nothing found')

    act(() => h.controller!.setFilter('period', 'today'))
    h.refuseWindow()
    expect(h.view.container.textContent).toContain('List unavailable')
    expect(
      h.view.container.querySelector('[data-id="hilos-table-empty"]'),
    ).toBeNull()

    // A refused list keeps its controls: changing the window asks again.
    const asked = h.sendTableViewport.mock.calls.length
    const control = h.view.container.querySelector(
      '[data-id="hilos-table-filter-period"]',
    )!
    fireEvent.click(control.querySelector('[data-id="hilos-dropdown-toggle"]')!)
    fireEvent.click(
      control.querySelector('[data-id="hilos-dropdown-option-0"]')!,
    )
    expect(h.sendTableViewport).toHaveBeenCalledTimes(asked + 1)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toMatchObject({
      period: 'this-hour',
    })
    h.window([[64, SESSION]])
    expect(h.view.container.textContent).not.toContain('List unavailable')
    expect(cells(h.view, 'session')).toEqual(['#64'])
  })

  it('keeps the live controller through the StrictMode replay and releases it on scope change and unmount', async () => {
    const replay = harness({ strictMode: true })
    await flush()
    replay.coldWindow()
    const live = replay.controller!
    expect(replay.registered.size).toBe(1)
    expect(replay.unregisterTableWindow).not.toHaveBeenCalled()
    replay.window([[64, SESSION]])
    expect(cells(replay.view, 'session')).toEqual(['#64'])
    expect(replay.controller).toBe(live)
    replay.view.unmount()
    await flush()
    expect(replay.registered.size).toBe(0)
    expect(replay.listening()).toBe(0)
    expect(replay.unregisterTableWindow).toHaveBeenCalledTimes(1)

    const onScopeChange = harness()
    await flush()
    onScopeChange.coldWindow()
    const scoped = onScopeChange.controller!
    act(() => onScopeChange.scopes.openPage(HilosPages.ANALYTICS))
    expect(onScopeChange.registered.size).toBe(0)
    expect(onScopeChange.listening()).toBe(0)
    expect(scoped.rows.get()).toEqual([])
    onScopeChange.view.unmount()
    await flush()
    expect(onScopeChange.unregisterTableWindow).toHaveBeenCalledTimes(1)
  })

  it('replaces its controller with the router or the context and releases the old one', async () => {
    const h = harness()
    await flush()
    h.coldWindow()
    const first = h.controller!
    const listening = h.listening()
    expect(listening).toBeGreaterThan(0)

    const next = fakeRouter(h.scopes, '/this-week', resolveHilosPath)
    h.rerender(h.context, next.router)
    await flush()
    const second = h.controller!
    expect(second).not.toBe(first)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(h.unregisterTableWindow).toHaveBeenLastCalledWith(TABLE, first)
    expect(h.listening()).toBe(listening)
    expect(second.filter.get().period).toBe('this-week')
    const asked = h.sendTableViewport.mock.calls.length
    h.visit(`${ROOT}/this-hour`)
    expect(h.sendTableViewport).toHaveBeenCalledTimes(asked)
    expect(second.filter.get().period).toBe('this-week')
    next.visit(`${ROOT}/this-hour`)
    expect(second.filter.get().period).toBe('this-hour')

    const wire = fakeConnection(false)
    const context: HilosAnalyticsContext = {
      connection: wire.connection,
      scopes: h.scopes,
    }
    h.rerender(context, next.router)
    await flush()
    expect(h.registered.size).toBe(0)
    expect(h.listening()).toBe(0)
    expect(h.unregisterTableWindow).toHaveBeenLastCalledWith(TABLE, second)
    expect(wire.registered.size).toBe(1)
    expect(wire.listening()).toBe(listening)

    h.view.unmount()
    await flush()
    expect(wire.registered.size).toBe(0)
    expect(wire.listening()).toBe(0)
  })
})
