// The Angular peer of vue/src/admin/analytics/HilosAnalyticsPersonPage.test.ts
// and react/test/HilosAnalyticsPersonPage.test.tsx, under the same case names:
// the person's browser sessions with links to each session, the Hidden view, the
// table states, and one controller per person in the address.
import { TestBed } from '@angular/core/testing'
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
import { describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsPersonPage } from '../src/admin/analytics/HilosAnalyticsPersonPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosAnalyticsPersonPage)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') heldPageResponse = { type, data }
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
    fixture.detectChanges()
  }

  return {
    fixture,
    root: fixture.nativeElement as HTMLElement,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    route(params: Record<string, string>) {
      currentRoute.set({ page: PAGE, params, admin: true })
      fixture.detectChanges()
    },
    navigate(id: string) {
      // HilosRouter publishes the route before PageSubscription opens the new
      // scope and forgets the connection's buffered page_response.
      currentRoute.set({ page: PAGE, params: { userId: id }, admin: true })
      scopes.openPage(PAGE)
      heldPageResponse = undefined
      fixture.detectChanges()
    },
    identity(label = 'Ada (#7)') {
      const data = scopes.page()!.data
      data.set('pageLabel', label)
      data.set('pageLead', LEAD)
      data.set('pageBreadcrumb', [
        { page: HilosPages.ANALYTICS, label: 'Analytics' },
      ])
      fixture.detectChanges()
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

type Harness = ReturnType<typeof harness>

function find(h: Harness, selector: string): HTMLElement | null {
  return h.root.querySelector<HTMLElement>(selector)
}

/** The wide branch of the table; the narrow cards repeat the same cells. */
function table(h: Harness): HTMLElement {
  const found = find(
    h,
    '[data-id="hilos-analytics-browser-sessions-table"] table',
  )
  expect(found).not.toBeNull()
  return found as HTMLElement
}

/**
 * The texts of one column's cells in the wide branch, in row order.
 *
 * @param h The mounted page.
 * @param id The cell's data-id suffix.
 */
function cells(h: Harness, id: string): string[] {
  return [
    ...table(h).querySelectorAll(`[data-id="hilos-analytics-session-${id}"]`),
  ].map((node) => node.textContent?.trim() ?? '')
}

describe('HilosAnalyticsPersonPage', () => {
  it('draws the shared identity, the person preset and real cells with session links', () => {
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

    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Ada (#7)')
    expect(h.root.textContent).toContain(LEAD)
    expect(h.root.querySelectorAll('h2')).toHaveLength(0)
    expect(table(h).getAttribute('aria-labelledby')).toBe(
      find(h, 'h1')?.getAttribute('id'),
    )

    const links = [
      ...table(h).querySelectorAll('a[data-id="hilos-analytics-session-link"]'),
    ]
    expect(links.map((link) => link.textContent)).toEqual(['#58', '#57'])
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
    expect(links.map((link) => link.getAttribute('data-record'))).toEqual([
      '58',
      '57',
    ])
    expect(cells(h, 'first-seen')).toEqual([
      formatAnalyticsTime(SESSION.firstSeenTs),
      formatAnalyticsTime(SESSION.firstSeenTs),
    ])
    expect(cells(h, 'last-seen')).toEqual([
      formatAnalyticsTime(SESSION.lastSeenTs),
      formatAnalyticsTime(SESSION.lastSeenTs),
    ])
    expect(cells(h, 'browser')).toEqual([SESSION.browser, '—'])
    expect(cells(h, 'actions')).toEqual(['7', '0'])
    expect(
      [
        ...table(h).querySelectorAll(
          '[data-id="hilos-analytics-session-actions"]',
        ),
      ].map((node) => node.getAttribute('data-record')),
    ).toEqual(['58', '57'])
    // A narrow screen draws the same rows as cards titled by the session link.
    expect(
      [
        ...h.root.querySelectorAll(
          '[data-id="hilos-table-cards"] a[data-id="hilos-analytics-session-link"]',
        ),
      ].map((link) => link.getAttribute('href')),
    ).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
  })

  it('takes a deleted account label from the page answer', () => {
    const h = harness()
    h.identity('Deleted user #7')
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Deleted user #7')
  })

  it('draws Hidden in every value without epoch dates, zeros or values in attributes', () => {
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
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Person')
    for (const id of ['first-seen', 'last-seen', 'browser', 'actions']) {
      expect(cells(h, id).length).toBeGreaterThan(0)
      expect(cells(h, id).every((text) => text === 'Hidden')).toBe(true)
    }
    expect(
      table(h).querySelector('a[data-id="hilos-analytics-session-link"]')
        ?.textContent,
    ).toBe('#57')
    expect(h.root.innerHTML).not.toContain('1970')
    expect(h.root.innerHTML).not.toContain('_hidden')
    expect(cells(h, 'actions')).not.toContain('0')
    expect(cells(h, 'browser')).not.toContain('—')
  })

  it('writes the session number as text when the application has no address for it', () => {
    const h = harness('7', () => undefined)
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    expect(
      h.root.querySelectorAll('a[data-id="hilos-analytics-session-link"]'),
    ).toHaveLength(0)
    const number = table(h).querySelector(
      '[data-id="hilos-analytics-session-link"]',
    )
    expect(number?.textContent).toBe('#57')
    expect(number?.getAttribute('data-record')).toBe('57')
    expect(number?.hasAttribute('href')).toBe(false)
  })

  it('uses the table loading, empty and refusal states', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    expect(h.root.textContent).not.toContain(
      'No browser sessions where this person signed in last',
    )
    h.window([])
    expect(h.root.textContent).toContain(
      'No browser sessions where this person signed in last',
    )
    h.refuseWindow()
    expect(h.root.textContent).toContain('List unavailable')
    expect(h.root.textContent).not.toContain('No browser sessions')
  })

  it('replaces the controller on a new person, drops old rows and releases every listener on unmount', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(cells(h, 'browser')).toEqual([SESSION.browser])

    h.navigate('8')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.unregisterTableWindow).toHaveBeenCalledWith(TABLE, old)
    expect(h.registered.get(TABLE)).toBeDefined()
    expect(h.registered.get(TABLE)).not.toBe(old)
    const currentListeners = [...h.listeners.values()].flatMap((set) => [
      ...set,
    ])
    expect(currentListeners).toHaveLength(oldListeners.length)
    expect(
      currentListeners.some((listener) => oldListeners.includes(listener)),
    ).toBe(false)
    expect(find(h, '[data-record="57"]')).toBeNull()
    expect(h.root.textContent).not.toContain('Ada')

    h.identity('Bob (#8)')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { user: 8 } }),
    )
    h.window([['60', { ...SESSION, browser: 'Chrome 141 / macOS' }]])
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Bob (#8)')
    expect(cells(h, 'browser')).toEqual(['Chrome 141 / macOS'])
    expect(find(h, '[data-record="57"]')).toBeNull()

    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
  })

  it('releases the existing controller when the route loses its person', () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.route({})
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(
      find(h, '[data-id="hilos-analytics-browser-sessions-table"]'),
    ).toBeNull()
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        find(h, '[data-id="hilos-analytics-browser-sessions-table"]'),
      ).toBeNull()
      h.route({ userId: '7' })
      expect(h.registered.size).toBe(1)
      expect(
        find(h, '[data-id="hilos-analytics-browser-sessions-table"]'),
      ).not.toBeNull()
    },
  )
})
