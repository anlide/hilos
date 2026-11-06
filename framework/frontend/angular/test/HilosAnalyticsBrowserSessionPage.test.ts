// The Angular peer of vue/src/admin/analytics/HilosAnalyticsBrowserSessionPage.test.ts
// and react/test/HilosAnalyticsBrowserSessionPage.test.tsx, under the same case
// names: the session's header and the actions table, the Hidden view, the
// skeleton and the table states, and one controller per session number in the
// address.
import { TestBed } from '@angular/core/testing'
import {
  bindAdminAccess,
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  hilosAdminAccess,
  PAGE_SIGNAL_SCHEMAS,
  resolvePageTitle,
  ScopeManager,
  type WebSocketLike,
} from '@hilos/core/base'
import { formatAnalyticsTime } from '@hilos/core/admin/analytics'
import { HilosLayout } from '@hilos/angular/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsBrowserSessionPage } from '../src/admin/analytics/HilosAnalyticsBrowserSessionPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const PAGE = HilosPages.ANALYTICS_BROWSER_SESSION
const TABLE = 'hilosAnalyticsActions'
const HEAD_LEAD =
  'Actions recorded in one browser session, in source-time order.'
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
function harness(param: string | undefined = '57') {
  const socketListeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (event, listener) => socketListeners.set(event, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const scopes = new ScopeManager()
  const pages = bindPageScope(connection, scopes)
  const initialPath =
    param === undefined
      ? '/hilos/analytics/browser-session'
      : `/hilos/analytics/browser-session/${encodeURIComponent(param)}`
  const router = createHilosRouter(
    createAppPageRouter({}),
    pages,
    {
      pathname: () => initialPath,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    },
    (page) =>
      resolvePageTitle(
        page,
        {},
        'Test',
        pages.pageIdentity.get()?.label,
        !pages.pageLoading.get(),
        pages.pageIdentity.get()?.pageHeadOwner?.label,
      ),
  )
  const sendTableViewport = vi.spyOn(connection, 'sendTableViewport')
  const registerTableWindow = vi.spyOn(connection, 'registerTableWindow')
  const unregisterTableWindow = vi.spyOn(connection, 'unregisterTableWindow')

  function receive(type: string, data: unknown): void {
    socketListeners.get('message')?.({ data: JSON.stringify({ type, data }) })
  }

  connection.connect()
  socketListeners.get('open')?.({})
  receive('handshake', { build: 'test' })
  pages.releaseOnSession()
  router.start()
  const context = { connection, scopes }
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosAnalyticsBrowserSessionPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  let destroyed = false
  const destroy = () => {
    if (!destroyed) {
      fixture.destroy()
      destroyed = true
    }
  }
  const release = () => {
    destroy()
    router.stop()
    pages.unsubscribe()
    connection.close()
  }
  cleanup.push(release)

  let currentHeader: unknown
  let currentLabel = 'Browser session #57'
  function answer(
    value: unknown,
    label: string,
    actions: Record<string, unknown>[] = [],
    rowKey = '9',
  ) {
    currentHeader = value
    currentLabel = label
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: label,
          pageLead: HEAD_LEAD,
          pageHeadOwner: {
            page: PAGE,
            label: 'Browser session',
            lead: HEAD_LEAD,
            icon: 'bi-window',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.ANALYTICS, label: 'Analytics' },
            {
              page: HilosPages.ANALYTICS_BROWSER_SESSIONS,
              label: 'Browser sessions',
            },
            { page: PAGE, label: 'Browser session' },
          ],
          pageTabs: [{ page: PAGE, label: 'Overview' }],
          pageChildren: [],
          browserSession: value,
        },
        windows: {
          [TABLE]: {
            filter: {},
            rows: actions.map((action) => ({ rowKey, slots: { action } })),
            limit: 25,
            totalCount: actions.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            rowsBefore: 0,
            sort: [{ field: 'createdTs', direction: 'asc' }],
          },
        },
      },
    })
    fixture.detectChanges()
  }

  const sessionPath = (id: string | undefined) =>
    id === undefined
      ? '/hilos/analytics/browser-session'
      : `/hilos/analytics/browser-session/${encodeURIComponent(id)}`

  return {
    fixture,
    root: fixture.nativeElement as HTMLElement,
    scopes,
    context,
    router,
    sent,
    registerTableWindow,
    sendTableViewport,
    unregisterTableWindow,
    destroy,
    route(params: Record<string, string>) {
      router.navigate(sessionPath(params.browserSessionId))
      fixture.detectChanges()
    },
    navigate(id: string) {
      router.navigate(sessionPath(id))
      fixture.detectChanges()
    },
    header(value: unknown, label = 'Browser session #57') {
      answer(value, label)
    },
    coldWindow() {
      answer(currentHeader, currentLabel)
    },
    window(actions: Record<string, unknown>[], rowKey = '9') {
      receive('table_window', {
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
      fixture.detectChanges()
    },
    refuseWindow() {
      receive('table_window_refused', {
        page: PAGE,
        tableKey: TABLE,
        errorCode: 'unavailable',
      })
      fixture.detectChanges()
    },
  }
}

const cleanup: Array<() => void> = []
afterEach(() => {
  for (const release of cleanup.splice(0).reverse()) release()
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

type Harness = ReturnType<typeof harness>

function find(h: Harness, selector: string): HTMLElement | null {
  return h.root.querySelector<HTMLElement>(selector)
}

function text(h: Harness, selector: string): string | undefined {
  const copy = find(h, selector)?.cloneNode(true) as HTMLElement | undefined
  copy
    ?.querySelectorAll('[aria-hidden="true"]')
    .forEach((node) => node.remove())
  return copy?.textContent?.trim()
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
    expect(text(h, '[aria-current="page"]')).toBe('Browser session #57')
    expect(h.router.currentTitle.get()).toBe('Browser session #57 · Test')
    expect(
      text(h, '[data-id="analytics-browser-session-head-description"]'),
    ).toBe(HEAD_LEAD)
    expect(
      find(h, '[data-id="analytics-browser-session-head-icon"]')?.getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
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

    h.header(HEADER, 'Browser session name from the current answer')
    expect(text(h, 'h1')).toBe('Browser session name from the current answer')
    expect(text(h, '[aria-current="page"]')).toBe(
      'Browser session name from the current answer',
    )
    expect(h.router.currentTitle.get()).toBe(
      'Browser session name from the current answer · Test',
    )
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

  it('replaces a named personal answer with Hidden without retaining its name, body or personal attributes', () => {
    const h = harness()
    const previousTitle = document.title
    const layout = TestBed.createComponent(HilosLayout)
    layout.componentRef.setInput('connection', h.context.connection)
    cleanup.push(() => {
      layout.destroy()
      document.title = previousTitle
    })
    h.header(HEADER)
    h.coldWindow()
    h.window([ACTION])
    layout.detectChanges()
    expect(text(h, 'h1')).toBe('Browser session #57')
    expect(text(h, 'nav[aria-label="Breadcrumb"] [aria-current="page"]')).toBe(
      'Browser session #57',
    )
    expect(document.title).toBe('Browser session #57 · Test')
    expect(text(h, '[data-id="hilos-analytics-browser-session-browser"]')).toBe(
      HEADER.browser,
    )
    expect(text(h, '[data-id="hilos-analytics-browser-session-person"]')).toBe(
      'Last signed in: Ada (#7)',
    )
    expect(text(h, '[data-id="hilos-analytics-action-name"]')).toBe('rename')
    expect(find(h, '[data-created-ts]')?.getAttribute('data-created-ts')).toBe(
      String(ACTION.createdTs),
    )

    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    cleanup.push(release)
    expect(hilosAdminAccess.get()).toBe('view')
    h.header({ _hidden: true }, 'Browser session')
    h.coldWindow()
    h.window([
      {
        createdTs: { _hidden: true },
        pageName: { _hidden: true },
        actionName: { _hidden: true },
      },
    ])
    layout.detectChanges()
    expect(text(h, 'h1')).toBe('Browser session')
    expect(text(h, 'nav[aria-label="Breadcrumb"] [aria-current="page"]')).toBe(
      'Browser session',
    )
    expect(h.router.currentTitle.get()).toBe('Browser session · Test')
    expect(document.title).toBe('Browser session · Test')
    expect(h.root.innerHTML).not.toContain('57')
    expect(h.root.innerHTML).not.toContain(HEADER.browser)
    expect(h.root.innerHTML).not.toContain(HEADER.lastSignedInUserLabel)
    expect(h.root.innerHTML).not.toContain('rename')
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
    expect(find(h, '[data-id="hilos-page-head-skeleton"]')).not.toBeNull()
    expect(
      find(
        h,
        '[data-id="analytics-browser-session-head-skeleton-icon"]',
      )?.closest('[aria-hidden="true"]'),
    ).not.toBeNull()
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

  it('replaces the controller on a new route, drops old rows and releases its table registration on unmount', () => {
    const h = harness()
    h.header(HEADER)
    h.coldWindow()
    h.window([ACTION])
    const old = h.registerTableWindow.mock.calls[0]?.[1]
    expect(old).toBeDefined()
    expect(text(h, '[data-id="hilos-analytics-action-name"]')).toBe('rename')

    h.navigate('58')
    expect(h.unregisterTableWindow).toHaveBeenCalledWith(TABLE, old)
    expect(h.registerTableWindow.mock.calls[1]?.[1]).toBeDefined()
    expect(h.registerTableWindow.mock.calls[1]?.[1]).not.toBe(old)
    expect(find(h, '[data-id="hilos-analytics-action-name"]')).toBeNull()
    expect(h.root.textContent).not.toContain('Ada')
    expect(h.root.innerHTML).not.toContain('Browser session #57')

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

    h.destroy()
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session',
    )
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
  })

  it('releases the existing controller when the route loses its session number', () => {
    const h = harness()
    expect(h.registerTableWindow).toHaveBeenCalledTimes(1)
    h.route({})
    expect(h.unregisterTableWindow.mock.calls[0]?.[0]).toBe(TABLE)
    expect(find(h, '[data-id="hilos-analytics-actions-table"]')).toBeNull()
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    (param) => {
      const h = harness(param)
      expect(h.registerTableWindow).not.toHaveBeenCalled()
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(find(h, '[data-id="hilos-analytics-actions-table"]')).toBeNull()
      h.route({ browserSessionId: '57' })
      expect(h.registerTableWindow).toHaveBeenCalledTimes(1)
      expect(
        find(h, '[data-id="hilos-analytics-actions-table"]'),
      ).not.toBeNull()
    },
  )
})
