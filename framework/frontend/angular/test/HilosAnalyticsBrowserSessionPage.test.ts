// The Angular peer of vue/src/admin/analytics/HilosAnalyticsBrowserSessionPage.test.ts
// and react/test/HilosAnalyticsBrowserSessionPage.test.tsx, under the same case
// names: the session's header and the actions table, the Hidden view, the
// skeleton and the table states, and one controller per session number in the
// address.
import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  formatAnalyticsTime,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosAnalyticsActionRow,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type TableViewportController,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsBrowserSessionPage } from '../src/admin/analytics/HilosAnalyticsBrowserSessionPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const PAGE = HilosPages.ANALYTICS_BROWSER_SESSION
const TABLE = 'hilosAnalyticsActions'
const HEADER = {
  id: 57,
  browser: 'Firefox 143 / Linux',
  lastSignedInUserId: 7,
  lastSignedInUserLabel: 'Ada',
}
const ACTION = {
  createdTs: Date.UTC(2026, 9, 9, 10, 11, 12),
  pageName: null,
  actionName: 'rename',
}
type Listener = (signal: { type?: string; data: unknown }) => void

function harness(param: string | undefined = '57') {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE,
    params: param === undefined ? {} : { browserSessionId: param },
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal('/hilos/analytics/browser-sessions/57'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: resolveHilosPath,
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
    TableViewportController<HilosAnalyticsActionRow>
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
      source: TableViewportController<HilosAnalyticsActionRow>,
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
  const fixture = TestBed.createComponent(HilosAnalyticsBrowserSessionPage)
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
    scopes,
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
      currentRoute.set({
        page: PAGE,
        params: { browserSessionId: id },
        admin: true,
      })
      scopes.openPage(PAGE)
      heldPageResponse = undefined
      fixture.detectChanges()
    },
    header(value: unknown, label = 'Browser session #57') {
      const data = scopes.page()!.data
      data.set('pageLabel', label)
      data.set(
        'pageLead',
        'Actions recorded in one browser session, in source-time order.',
      )
      data.set('pageBreadcrumb', [
        { page: HilosPages.ANALYTICS, label: 'Analytics' },
      ])
      data.set('browserSession', value)
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
                sort: [{ field: 'createdTs', direction: 'asc' }],
              },
            },
          },
        },
        'page_response',
      )
    },
    window(actions: Record<string, unknown>[], rowKey = '9') {
      emit('tableWindow', {
        page: PAGE,
        tableKey: TABLE,
        rows: actions.map((action) => ({ rowKey, slots: { action } })),
        totalCount: actions.length,
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

function text(h: Harness, selector: string): string | undefined {
  return find(h, selector)?.textContent?.trim()
}

describe('HilosAnalyticsBrowserSessionPage', () => {
  it('draws the received identity, header and real cells in the shared table', () => {
    const h = harness()
    h.header(HEADER)
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { browserSession: 57 },
        limit: 25,
      }),
    )
    h.window([ACTION])

    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    expect(text(h, 'h1')).toBe('Browser session #57')
    expect(text(h, '[data-id="hilos-analytics-browser-session-browser"]')).toBe(
      HEADER.browser,
    )
    expect(text(h, '[data-id="hilos-analytics-browser-session-person"]')).toBe(
      'Last signed in: Ada (#7)',
    )
    expect(text(h, '[data-id="hilos-analytics-action-when"]')).toBe(
      formatAnalyticsTime(ACTION.createdTs),
    )
    expect(text(h, '[data-id="hilos-analytics-action-page"]')).toBe('—')
    expect(text(h, '[data-id="hilos-analytics-action-name"]')).toBe('rename')
    expect(find(h, '[data-created-ts]')?.getAttribute('data-created-ts')).toBe(
      String(ACTION.createdTs),
    )
    expect(
      find(h, '[data-id="hilos-analytics-action-name"]')?.getAttribute(
        'data-record',
      ),
    ).toBe('9')
    expect(
      find(h, '[data-id="hilos-analytics-actions-table"] table')?.getAttribute(
        'aria-labelledby',
      ),
    ).toBe(find(h, 'h1')?.getAttribute('id'))
    expect(h.root.querySelectorAll('h2')).toHaveLength(0)
  })

  it('shows the guest sentence only for the received null account and a dash for no browser', () => {
    const h = harness()
    h.header({
      ...HEADER,
      browser: null,
      lastSignedInUserId: null,
      lastSignedInUserLabel: null,
    })
    expect(text(h, '[data-id="hilos-analytics-browser-session-browser"]')).toBe(
      '—',
    )
    expect(text(h, '[data-id="hilos-analytics-browser-session-person"]')).toBe(
      'No one has signed in in this browser session',
    )
  })

  it('takes the current deleted-account label from each page answer', () => {
    const h = harness()
    h.header(HEADER)
    h.header({ ...HEADER, lastSignedInUserLabel: 'Deleted user #7' })
    expect(text(h, '[data-id="hilos-analytics-browser-session-person"]')).toBe(
      'Last signed in: Deleted user #7',
    )
    expect(h.root.textContent).not.toContain('Ada')
  })

  it('draws Hidden for the whole header and every cell without epoch dates or personal attributes', () => {
    const h = harness()
    h.header({ _hidden: true }, 'Browser session')
    h.coldWindow()
    h.window([
      {
        createdTs: { _hidden: true },
        pageName: { _hidden: true },
        actionName: { _hidden: true },
      },
    ])
    expect(text(h, 'h1')).toBe('Browser session')
    expect(text(h, '[data-id="hilos-analytics-browser-session-header"]')).toBe(
      'Hidden',
    )
    expect(
      find(h, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBeNull()
    for (const cell of ['when', 'page', 'name']) {
      const nodes = h.root.querySelectorAll(
        `[data-id="hilos-analytics-action-${cell}"]`,
      )
      expect(nodes.length).toBeGreaterThan(0)
      for (const node of nodes) {
        expect(node.textContent?.trim()).toBe('Hidden')
      }
    }
    expect(h.root.querySelectorAll('[data-created-ts]')).toHaveLength(0)
    expect(h.root.innerHTML).not.toContain('1970')
    expect(h.root.innerHTML).not.toContain('No one has signed in')
    expect(h.root.innerHTML).not.toContain('_hidden')
  })

  it('keeps absent and malformed headers as skeletons and uses the table loading, empty and refusal states', () => {
    const h = harness()
    const skeleton =
      '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]'
    expect(find(h, skeleton)).not.toBeNull()
    expect(h.root.textContent).not.toContain('No actions recorded')
    h.header({ id: 57 })
    h.coldWindow()
    expect(
      find(h, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBeNull()
    expect(find(h, skeleton)).not.toBeNull()
    expect(h.root.textContent).not.toContain('No actions recorded')
    h.header(HEADER)
    h.window([])
    expect(h.root.textContent).toContain(
      'No actions recorded in this browser session yet',
    )
    h.refuseWindow()
    expect(h.root.textContent).toContain('List unavailable')
    expect(h.root.textContent).not.toContain('No actions recorded')
  })

  it('replaces the controller on a new route, drops old rows and releases every listener on unmount', () => {
    const h = harness()
    h.header(HEADER)
    h.coldWindow()
    h.window([ACTION])
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(text(h, '[data-id="hilos-analytics-action-name"]')).toBe('rename')

    h.navigate('58')
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
    expect(find(h, '[data-id="hilos-analytics-action-name"]')).toBeNull()
    expect(h.root.textContent).not.toContain('Ada')

    h.header({ ...HEADER, id: 58 }, 'Browser session #58')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { browserSession: 58 } }),
    )
    h.window([{ ...ACTION, actionName: 'open' }], '10')
    expect(text(h, '[data-id="hilos-analytics-action-name"]')).toBe('open')
    expect(find(h, '[data-record="9"]')).toBeNull()

    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
  })

  it('releases the existing controller when the route loses its session number', () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.route({})
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(find(h, '[data-id="hilos-analytics-actions-table"]')).toBeNull()
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(find(h, '[data-id="hilos-analytics-actions-table"]')).toBeNull()
      h.route({ browserSessionId: '57' })
      expect(h.registered.size).toBe(1)
      expect(
        find(h, '[data-id="hilos-analytics-actions-table"]'),
      ).not.toBeNull()
    },
  )
})
