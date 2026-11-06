// The React peer of vue/src/admin/analytics/HilosAnalyticsBrowserSessionPage.test.ts:
// the shared entity head under the permitted name, the session's header and the
// actions table, the Hidden view, the skeletons and the table states, one
// controller per session number in the address, and its StrictMode lifecycle.
import { StrictMode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  createAppPageRouter,
  createHilosRouter,
  createSignal,
  formatAnalyticsTime,
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  resolvePageTitle,
  ScopeManager,
  sessionUserName,
  USER_ENTITY_TYPE,
  type HilosAnalyticsActionRow,
  type HilosAnalyticsContext,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
  type Scope,
  type TableViewportController,
} from '@hilos/core'

import { HilosAnalyticsBrowserSessionPage } from '../src/admin/analytics/HilosAnalyticsBrowserSessionPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const PAGE = HilosPages.ANALYTICS_BROWSER_SESSION
const TABLE = 'hilosAnalyticsActions'
const LEAD = 'Actions recorded in one browser session, in source-time order.'
const OWNER = {
  page: PAGE,
  label: 'Browser session',
  lead: LEAD,
  icon: 'bi-window',
}
const BREADCRUMB = [
  { page: HilosPages.DASHBOARD, label: 'Hilos' },
  { page: HilosPages.ANALYTICS, label: 'Analytics' },
  { page: HilosPages.ANALYTICS_BROWSER_SESSIONS, label: 'Browser sessions' },
  { page: PAGE, label: 'Browser session' },
]
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
type Controller = TableViewportController<HilosAnalyticsActionRow>

/** The page answer's head and header data, as bindPageScope writes it. */
function writeAnswer(
  scope: Scope,
  value: unknown,
  label: unknown,
  owner: typeof OWNER | null,
) {
  const data = scope.data
  data.set('pageLabel', label)
  data.set('pageLead', LEAD)
  data.set('pageHeadOwner', owner ?? undefined)
  data.set('pageBreadcrumb', BREADCRUMB)
  data.set('pageTabs', owner ? [{ page: PAGE, label: 'Overview' }] : undefined)
  data.set('pageChildren', [])
  data.set('browserSession', value)
}

/** The page answer's first window of the actions table. */
function coldAnswer() {
  return {
    type: 'page_response',
    data: {
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
            sort: [{ field: 'createdTs', direction: 'asc' }],
          },
        },
      },
    },
  }
}

/** A connection that replays its held page answer to a late listener. */
function fakeConnection() {
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<string, Controller>()
  const sendTableViewport = vi.fn(() => true)
  // Like the real connection, a stale binding cannot drop its successor.
  const unregisterTableWindow = vi.fn((key: string, source?: Controller) => {
    if (source === undefined || registered.get(key) === source)
      registered.delete(key)
  })
  const held: { pageResponse?: Parameters<Listener>[0] } = {}
  const connection = {
    on(event: string, listener: Listener) {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      if (event === 'projectSignal' && held.pageResponse)
        listener(held.pageResponse)
      return () => set.delete(listener)
    },
    registerTableWindow(key: string, source: Controller) {
      registered.set(key, source)
    },
    unregisterTableWindow,
    sendTableViewport,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection

  return {
    connection,
    listeners,
    registered,
    sendTableViewport,
    unregisterTableWindow,
    held,
    listening(): number {
      return [...listeners.values()].reduce((sum, set) => sum + set.size, 0)
    },
  }
}

function harness(
  param: string | undefined = '57',
  {
    strictMode = false,
    buffered = false,
    icon = OWNER.icon,
  }: { strictMode?: boolean; buffered?: boolean; icon?: string } = {},
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const routes = createAppPageRouter({
    [PAGE]: { ...HILOS_ROUTE_DECLARATIONS[PAGE], icon },
  })
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE,
    params: param === undefined ? {} : { browserSessionId: param },
    admin: true,
  })
  const sourceRouter: HilosRouter = {
    currentRoute,
    currentPath: createSignal('/hilos/analytics/browser-session/57'),
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
  const router: HilosRouter = {
    ...sourceRouter,
    pageIdentity: createHilosRouter(
      routes,
      {
        ...sourceRouter,
        pageError: createSignal(null),
        subscribe: () => {},
        unsubscribe: () => {},
        refuseUnbuilt: () => {},
      },
      {
        pathname: () => sourceRouter.currentPath.get(),
        pushState: () => {},
        replaceState: () => {},
        onPopState: () => () => {},
      },
    ).pageIdentity,
  }
  const wire = fakeConnection()
  if (buffered) {
    // The router has ingested the answer and the connection still holds it
    // when the page mounts.
    writeAnswer(scopes.page()!, HEADER, 'Browser session #57', OWNER)
    wire.held.pageResponse = coldAnswer()
  }
  const context: HilosAnalyticsContext = { connection: wire.connection, scopes }
  function tree(nextContext: HilosAnalyticsContext) {
    const app = (
      <HilosRouterContext.Provider value={router}>
        <HilosAnalyticsBrowserSessionPage context={nextContext} />
      </HilosRouterContext.Provider>
    )
    return strictMode ? <StrictMode>{app}</StrictMode> : app
  }
  const view = render(tree(context))
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') wire.held.pageResponse = { type, data }
    act(() => {
      for (const listener of wire.listeners.get(event) ?? [])
        listener({ type, data })
    })
  }

  return {
    ...wire,
    view,
    scopes,
    router,
    get controller() {
      return wire.registered.get(TABLE)
    },
    rerender(nextContext: HilosAnalyticsContext) {
      view.rerender(tree(nextContext))
    },
    route(params: Record<string, string>) {
      act(() => currentRoute.set({ page: PAGE, params, admin: true }))
    },
    navigate(id: string) {
      // HilosRouter publishes the route before PageSubscription opens the new
      // scope and forgets the connection's buffered page_response.
      act(() => {
        currentRoute.set({
          page: PAGE,
          params: { browserSessionId: id },
          admin: true,
        })
        scopes.openPage(PAGE)
        wire.held.pageResponse = undefined
      })
    },
    header(
      value: unknown,
      label: unknown = 'Browser session #57',
      owner: typeof OWNER | null = OWNER,
    ) {
      act(() => writeAnswer(scopes.page()!, value, label, owner))
    },
    coldWindow() {
      const answer = coldAnswer()
      emit('projectSignal', answer.data, answer.type)
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
type View = Harness['view']

function find(view: View, selector: string): HTMLElement | null {
  return view.container.querySelector<HTMLElement>(selector)
}

function text(view: View, selector: string): string | undefined {
  return find(view, selector)?.textContent?.trim()
}

/** Flush the page's deferred table cleanup. */
async function flush(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

/**
 * Each breadcrumb entry's readable text, without its aria-hidden separator.
 *
 * @param view The rendered page.
 */
function crumbs(view: View): string[] {
  return [
    ...view.container.querySelectorAll('[data-id="hilos-breadcrumb"] li'),
  ].map((crumb) =>
    [...crumb.childNodes]
      .filter(
        (node) =>
          !(
            node instanceof Element &&
            node.getAttribute('aria-hidden') === 'true'
          ),
      )
      .map((node) => node.textContent ?? '')
      .join('')
      .trim(),
  )
}

/**
 * The browser title the boot sequence computes from the router's identity.
 *
 * @param h The mounted page.
 */
function title(h: Harness): string {
  const identity = h.router.pageIdentity.get()
  return resolvePageTitle(
    PAGE,
    {},
    '',
    identity?.label,
    true,
    identity?.pageHeadOwner?.label,
  )
}

/**
 * The adopted head under one permitted name: one heading, the current crumb, the
 * owner identity and the title agree, and the block takes the catalog's lead.
 *
 * @param h The mounted page.
 * @param label The permitted instance name.
 * @param owner The catalog metadata from the current answer.
 */
function expectHead(h: Harness, label: string, owner = OWNER) {
  const headings = h.view.container.querySelectorAll('h1')
  expect(headings).toHaveLength(1)
  expect(headings[0]!.textContent).toBe(label)
  expect(crumbs(h.view).at(-1)).toBe(label)
  expect(h.router.pageIdentity.get()?.pageHeadOwner).toEqual({
    ...owner,
    label,
  })
  expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(label)
  expect(title(h)).toBe(label)
  expect(
    find(
      h.view,
      '[data-id="analytics-browser-session-head-icon"]',
    )?.getAttribute('aria-hidden'),
  ).toBe('true')
  const description = find(
    h.view,
    '[data-id="analytics-browser-session-head-description"]',
  )
  expect(description?.textContent).toBe(owner.lead)
}

/**
 * A pending identity draws only the hidden skeleton, with no name, crumb,
 * picture or lead.
 *
 * @param h The mounted page.
 */
function expectHeadSkeleton(h: Harness) {
  const skeleton = find(h.view, '[data-id="hilos-page-head-skeleton"]')
  expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
  const circle = skeleton?.querySelector(
    '[data-id="analytics-browser-session-head-skeleton-icon"]',
  )
  expect(circle).not.toBeNull()
  expect(circle?.textContent).toBe('')
  expect(find(h.view, 'h1')).toBeNull()
  expect(find(h.view, 'nav')).toBeNull()
  expect(
    find(h.view, '[data-id="analytics-browser-session-head-icon"]'),
  ).toBeNull()
  expect(
    find(h.view, '[data-id="analytics-browser-session-head-description"]'),
  ).toBeNull()
  expect(h.view.container.textContent).not.toContain(LEAD)
}

describe('HilosAnalyticsBrowserSessionPage', () => {
  afterEach(() => cleanup())

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

    expectHead(h, 'Browser session #57')
    expect(crumbs(h.view)).toEqual([
      'Hilos',
      'Analytics',
      'Browser sessions',
      'Browser session #57',
    ])
    expect(
      [
        ...h.view.container.querySelectorAll('[data-id="hilos-breadcrumb"] a'),
      ].map((link) => link.getAttribute('href')),
    ).toEqual([
      '/hilos',
      '/hilos/analytics',
      '/hilos/analytics/browser-sessions',
    ])
    const current = find(
      h.view,
      '[data-id="hilos-breadcrumb"] [aria-current="page"]',
    )
    expect(current?.querySelector('a')).toBeNull()
    expect(
      h.view.container.querySelectorAll(
        '[data-id="hilos-breadcrumb"] [aria-current="page"]',
      ),
    ).toHaveLength(1)
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-browser"]'),
    ).toBe(HEADER.browser)
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBe('Last signed in: Ada (#7)')
    expect(text(h.view, '[data-id="hilos-analytics-action-when"]')).toBe(
      formatAnalyticsTime(ACTION.createdTs),
    )
    expect(text(h.view, '[data-id="hilos-analytics-action-page"]')).toBe('—')
    expect(text(h.view, '[data-id="hilos-analytics-action-name"]')).toBe(
      'rename',
    )
    expect(
      find(h.view, '[data-created-ts]')?.getAttribute('data-created-ts'),
    ).toBe(String(ACTION.createdTs))
    expect(
      find(h.view, '[data-id="hilos-analytics-action-name"]')?.getAttribute(
        'data-record',
      ),
    ).toBe('9')
    const heading = find(h.view, 'h1')?.getAttribute('id')
    expect(heading).toBeTruthy()
    expect(
      find(
        h.view,
        '[data-id="hilos-analytics-actions-table"] table',
      )?.getAttribute('aria-labelledby'),
    ).toBe(heading)
  })

  it('takes the description from the catalog identity and its name from the permitted label', () => {
    const h = harness('57', { icon: 'bi-browser-firefox' })
    const owner = {
      ...OWNER,
      lead: 'Catalog description for this browser session.',
      icon: 'bi-browser-firefox',
    }
    h.header(HEADER, 'Permitted label', owner)
    expectHead(h, 'Permitted label', owner)
    expect(h.view.container.textContent).not.toContain(LEAD)
    expect(h.view.container.textContent).not.toContain('#57')
  })

  it('keeps the heading, the lead and the actions for an answer without an adopted owner', () => {
    const h = harness()
    h.header(HEADER, 'Browser session #57', null)
    h.coldWindow()
    h.window([ACTION])
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(text(h.view, 'h1')).toBe('Browser session #57')
    expect(h.view.container.textContent).toContain(LEAD)
    expect(text(h.view, '[data-id="hilos-analytics-action-name"]')).toBe(
      'rename',
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
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-browser"]'),
    ).toBe('—')
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBe('No one has signed in in this browser session')
  })

  it('takes the current deleted-account label from each page answer', () => {
    const h = harness()
    h.header(HEADER)
    h.header({ ...HEADER, lastSignedInUserLabel: 'Deleted user #7' })
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBe('Last signed in: Deleted user #7')
    expect(h.view.container.textContent).not.toContain('Ada')
  })

  it('draws Hidden for the whole header and every cell without epoch dates or personal attributes', () => {
    const h = harness()
    const currentUser = { type: USER_ENTITY_TYPE, id: 99 }
    act(() => {
      h.scopes.session.entities.upsert(currentUser, { name: 'Session owner' })
      h.scopes.session.data.set('currentUser', currentUser)
    })
    expect(sessionUserName(h.scopes).get()).toBe('Session owner')
    h.header(HEADER)
    expectHead(h, 'Browser session #57')

    h.header({ _hidden: true }, 'Browser session')
    h.coldWindow()
    h.window([
      {
        createdTs: { _hidden: true },
        pageName: { _hidden: true },
        actionName: { _hidden: true },
      },
    ])
    expectHead(h, 'Browser session')
    expect(h.view.container.innerHTML).not.toContain('#57')
    expect(h.view.container.innerHTML).not.toContain('Session owner')
    expect(h.view.container.innerHTML).not.toContain('Ada')
    expect(h.view.container.innerHTML).not.toContain(HEADER.browser)
    expect(
      text(h.view, '[data-id="hilos-analytics-browser-session-header"]'),
    ).toBe('Hidden')
    expect(
      find(h.view, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBeNull()
    for (const cell of ['when', 'page', 'name']) {
      const nodes = h.view.container.querySelectorAll(
        `[data-id="hilos-analytics-action-${cell}"]`,
      )
      expect(nodes.length).toBeGreaterThan(0)
      for (const node of nodes) {
        expect(node.textContent?.trim()).toBe('Hidden')
      }
    }
    expect(h.view.container.querySelectorAll('[data-created-ts]')).toHaveLength(
      0,
    )
    expect(h.view.container.innerHTML).not.toContain('1970')
    expect(h.view.container.innerHTML).not.toContain('No one has signed in')
    expect(h.view.container.innerHTML).not.toContain('_hidden')
  })

  it('shows only hidden skeletons in the head and the header before identity arrives', () => {
    const h = harness()
    expectHeadSkeleton(h)
    const body = find(
      h.view,
      '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]',
    )
    const bars = [...(body?.querySelectorAll('.placeholder') ?? [])]
    expect(bars.length).toBeGreaterThan(0)
    expect(
      bars.every((bar) => bar.getAttribute('aria-hidden') === 'true'),
    ).toBe(true)
    expect(h.view.container.textContent).not.toContain('Browser session')
    expect(h.view.container.textContent).not.toContain(HEADER.browser)
    expect(h.view.container.textContent).not.toContain('Ada')
  })

  it('keeps absent and malformed headers as skeletons and uses the table loading, empty and refusal states', () => {
    const h = harness()
    const skeleton =
      '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]'
    expect(find(h.view, skeleton)).not.toBeNull()
    expect(h.view.container.textContent).not.toContain('No actions recorded')
    h.header({ id: 57 })
    h.coldWindow()
    expectHead(h, 'Browser session #57')
    expect(
      find(h.view, '[data-id="hilos-analytics-browser-session-person"]'),
    ).toBeNull()
    expect(find(h.view, skeleton)).not.toBeNull()
    expect(
      find(h.view, '[data-id="hilos-table-loading"]')?.getAttribute(
        'aria-busy',
      ),
    ).toBe('true')
    expect(h.view.container.textContent).not.toContain('No actions recorded')
    h.header(HEADER)
    h.window([])
    expectHead(h, 'Browser session #57')
    expect(h.view.container.textContent).toContain(
      'No actions recorded in this browser session yet',
    )
    h.refuseWindow()
    expectHead(h, 'Browser session #57')
    expect(h.view.container.textContent).toContain('List unavailable')
    expect(h.view.container.textContent).not.toContain('No actions recorded')
  })

  it('replaces the controller on a new route, drops old rows and releases every listener on unmount', async () => {
    const h = harness()
    h.header(HEADER)
    const previousScope = h.scopes.page()!
    h.coldWindow()
    h.window([ACTION])
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(text(h.view, '[data-id="hilos-analytics-action-name"]')).toBe(
      'rename',
    )

    h.navigate('58')
    await flush()
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
    expect(find(h.view, '[data-id="hilos-analytics-action-name"]')).toBeNull()
    expect(h.view.container.textContent).not.toContain('Ada')

    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(find(h.view, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expectHeadSkeleton(h)
    expect(h.view.container.textContent).not.toContain('Browser session #57')

    h.header({ ...HEADER, id: 58 }, 'Browser session #58')
    act(() => previousScope.data.set('pageLabel', 'Old scope label'))
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { browserSession: 58 } }),
    )
    h.window([{ ...ACTION, actionName: 'open' }], '10')
    expectHead(h, 'Browser session #58')
    expect(text(h.view, '[data-id="hilos-analytics-action-name"]')).toBe('open')
    expect(find(h.view, '[data-record="9"]')).toBeNull()
    expect(h.view.container.textContent).not.toContain('Old scope label')

    const active = h.scopes.page()!
    h.view.unmount()
    await flush()
    expect(h.registered.size).toBe(0)
    expect(h.listening()).toBe(0)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
    // The released binding gives the crumb and the title back to the catalog.
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Browser session',
    )
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session',
    )
    active.data.set('pageLabel', 'Changed after unmount')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session',
    )
  })

  it('reacts to permitted label updates without deriving a name from the route or header', () => {
    const h = harness()
    h.header(HEADER, 'Permitted label')
    expectHead(h, 'Permitted label')
    act(() => h.scopes.page()!.data.set('pageLabel', 'Updated permitted label'))
    expectHead(h, 'Updated permitted label')
    act(() => h.scopes.page()!.data.set('pageLabel', ''))
    expectHead(h, '')
    expect(h.view.container.textContent).not.toContain('#57')
  })

  it.each([undefined, null, 57, { _hidden: true }])(
    'clears the identity when the current label is not a string: %j',
    (label) => {
      const h = harness()
      h.header(HEADER)
      act(() => h.scopes.page()!.data.set('pageLabel', label))
      expect(h.router.pageIdentity.get()).toBeUndefined()
      expect(find(h.view, '[data-id="hilos-breadcrumb"]')).toBeNull()
      expectHeadSkeleton(h)
      h.header(HEADER, 'Browser session')
      expectHead(h, 'Browser session')
    },
  )

  it('keeps permitted names separate between scope managers', () => {
    const first = harness()
    const second = harness('58')
    first.header(HEADER)
    second.header({ ...HEADER, id: 58 }, 'Browser session #58')
    expect(first.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session #57',
    )
    expect(second.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session #58',
    )
    expect(text(first.view, 'h1')).toBe('Browser session #57')
    expect(text(second.view, 'h1')).toBe('Browser session #58')
    expect(crumbs(first.view).at(-1)).toBe('Browser session #57')
    expect(crumbs(second.view).at(-1)).toBe('Browser session #58')
  })

  it('keeps the catalog caption on another page scope and clears it without a scope', () => {
    const h = harness()
    h.header(HEADER)
    act(() => {
      const other = h.scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSIONS)
      writeAnswer(other, HEADER, 'Another page label', OWNER)
    })
    expectHead(h, 'Browser session')
    expect(h.view.container.textContent).not.toContain('Another page label')
    act(() => h.scopes.dropPage())
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(find(h.view, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expectHeadSkeleton(h)
  })

  it('keeps the bound controller through the StrictMode replay of a buffered answer and releases it on unmount', async () => {
    const h = harness('57', { strictMode: true, buffered: true })
    await flush()
    const live = h.controller
    expect(live).toBeDefined()
    expect(h.registered.size).toBe(1)
    expect(h.unregisterTableWindow).not.toHaveBeenCalled()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { browserSession: 57 } }),
    )
    expectHead(h, 'Browser session #57')

    h.window([ACTION])
    expect(
      h.view.container.querySelectorAll(
        '[data-id="hilos-analytics-actions-table"] table [data-id="hilos-analytics-action-name"]',
      ),
    ).toHaveLength(1)
    expect(text(h.view, '[data-id="hilos-analytics-action-name"]')).toBe(
      'rename',
    )
    expect(h.controller).toBe(live)

    h.view.unmount()
    await flush()
    expect(h.registered.size).toBe(0)
    expect(h.listening()).toBe(0)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(h.unregisterTableWindow).toHaveBeenLastCalledWith(TABLE, live)
  })

  it('moves its controller to a replaced context and releases the old one', async () => {
    const h = harness('57', { strictMode: true })
    await flush()
    const first = h.controller!
    const listening = h.listening()
    expect(listening).toBeGreaterThan(0)

    const wire = fakeConnection()
    h.rerender({ connection: wire.connection, scopes: h.scopes })
    await flush()
    expect(h.registered.size).toBe(0)
    expect(h.listening()).toBe(0)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(h.unregisterTableWindow).toHaveBeenLastCalledWith(TABLE, first)
    expect(wire.registered.size).toBe(1)
    expect(wire.registered.get(TABLE)).not.toBe(first)
    expect(wire.listening()).toBe(listening)

    h.view.unmount()
    await flush()
    expect(wire.registered.size).toBe(0)
    expect(wire.listening()).toBe(0)
  })

  it('releases the existing controller when the route loses its session number', async () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.route({})
    await flush()
    expect(h.registered.size).toBe(0)
    expect(h.listening()).toBe(0)
    expect(find(h.view, '[data-id="hilos-analytics-actions-table"]')).toBeNull()
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        find(h.view, '[data-id="hilos-analytics-actions-table"]'),
      ).toBeNull()
      h.route({ browserSessionId: '57' })
      expect(h.registered.size).toBe(1)
      expect(
        find(h.view, '[data-id="hilos-analytics-actions-table"]'),
      ).not.toBeNull()
    },
  )
})
