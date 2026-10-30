// The React peer of vue/src/admin/analytics/HilosAnalyticsPersonPage.test.ts:
// the person's browser sessions with links to each session, the Hidden view,
// the table states, and one controller per person in the address.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosAnalyticsPersonPage } from '../src/admin/analytics/HilosAnalyticsPersonPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosAnalyticsPersonPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') heldPageResponse = { type, data }
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener({ type, data })
    })
  }

  return {
    view,
    router,
    scopes,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    route(params: Record<string, string>) {
      act(() => currentRoute.set({ page: PAGE, params, admin: true }))
    },
    navigate(id: string) {
      // HilosRouter publishes the route before PageSubscription opens the new
      // scope and forgets the connection's buffered page_response.
      act(() => {
        currentRoute.set({ page: PAGE, params: { userId: id }, admin: true })
        scopes.openPage(PAGE)
        heldPageResponse = undefined
      })
    },
    identity(label = 'Ada (#7)') {
      act(() => {
        const data = scopes.page()!.data
        data.set('pageLabel', label)
        data.set('pageLead', LEAD)
        data.set('pageBreadcrumb', [
          { page: HilosPages.ANALYTICS, label: 'Analytics' },
        ])
      })
    },
    sharedIdentity(
      label = 'Ada (#7)',
      name: unknown = 'Ada',
      photo: unknown = null,
      lead = LEAD,
    ) {
      act(() => {
        const data = scopes.page()!.data
        data.set('pageLabel', label)
        data.set('name', name)
        data.set('photo', photo)
        data.set('pageLead', lead)
        data.set('pageHeadOwner', {
          page: PAGE,
          label: 'Person',
          lead,
          icon: null,
        })
        data.set('pageBreadcrumb', [
          { page: HilosPages.DASHBOARD, label: 'Hilos' },
          { page: HilosPages.ANALYTICS, label: 'Analytics' },
          { page: HilosPages.ANALYTICS_PEOPLE, label: 'People' },
          { page: PAGE, label: 'Person' },
        ])
        data.set('pageTabs', [{ page: PAGE, label: 'Overview' }])
        data.set('pageChildren', [])
      })
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

function find(view: View, selector: string): HTMLElement | null {
  return view.container.querySelector<HTMLElement>(selector)
}

/** The wide branch of the table; the narrow cards repeat the same cells. */
function table(view: View): HTMLElement {
  const found = find(
    view,
    '[data-id="hilos-analytics-browser-sessions-table"] table',
  )
  expect(found).not.toBeNull()
  return found as HTMLElement
}

/**
 * The texts of one column's cells in the wide branch, in row order.
 *
 * @param view The rendered page.
 * @param id The cell's data-id suffix.
 */
function cells(view: View, id: string): string[] {
  return [
    ...table(view).querySelectorAll(
      `[data-id="hilos-analytics-session-${id}"]`,
    ),
  ].map((node) => node.textContent?.trim() ?? '')
}

describe('HilosAnalyticsPersonPage', () => {
  afterEach(() => cleanup())

  it('draws the shared identity, the person preset and real cells with session links', () => {
    const h = harness()
    h.sharedIdentity()
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

    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(find(h.view, 'h1')?.textContent?.trim()).toBe('Ada (#7)')
    expect(h.view.container.textContent).toContain(LEAD)
    expect(find(h.view, '[data-id="hilos-page-head"]')).not.toBeNull()
    expect(find(h.view, '[data-id="hilos-admin-title"]')).toBeNull()
    expect(
      [
        ...h.view.container.querySelectorAll('[data-id="hilos-breadcrumb"] li'),
      ].map((crumb) =>
        (crumb.querySelector('a')?.textContent ?? crumb.lastChild?.textContent)
          ?.trim()
          .replace(/^\//, ''),
      ),
    ).toEqual(['Hilos', 'Analytics', 'People', 'Ada (#7)'])
    expect(
      find(h.view, '[data-id="hilos-breadcrumb"] [aria-current="page"]')
        ?.lastChild?.textContent,
    ).toBe('Ada (#7)')
    expect(h.router.pageIdentity.get()?.pageHeadOwner).toEqual({
      page: PAGE,
      label: 'Ada (#7)',
      lead: LEAD,
      icon: null,
    })
    expect(
      find(h.view, '[data-id="analytics-person-head-avatar"]')?.textContent,
    ).toBe('A')
    expect(
      find(h.view, '[data-id="analytics-person-head-avatar"]')?.getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(
      find(h.view, '[data-id="analytics-person-head-description"]')
        ?.textContent,
    ).toBe(LEAD)
    expect(find(h.view, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(find(h.view, '[data-id="hilos-admin-children"]')).toBeNull()
    expect(h.view.container.querySelectorAll('h2')).toHaveLength(0)
    expect(table(h.view).getAttribute('aria-labelledby')).toBe(
      find(h.view, 'h1')?.getAttribute('id'),
    )

    const links = [
      ...table(h.view).querySelectorAll(
        'a[data-id="hilos-analytics-session-link"]',
      ),
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
      [
        ...table(h.view).querySelectorAll(
          '[data-id="hilos-analytics-session-actions"]',
        ),
      ].map((node) => node.getAttribute('data-record')),
    ).toEqual(['58', '57'])
    // A narrow screen draws the same rows as cards titled by the session link.
    expect(
      [
        ...h.view.container.querySelectorAll(
          '[data-id="hilos-table-cards"] a[data-id="hilos-analytics-session-link"]',
        ),
      ].map((link) => link.getAttribute('href')),
    ).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
  })

  it('draws only the blank head skeleton until the page answer arrives', () => {
    const h = harness()
    const skeleton = find(h.view, '[data-id="hilos-page-head-skeleton"]')

    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(
      skeleton?.querySelector('[data-id="analytics-person-head-avatar"]'),
    ).toBeNull()
    expect(find(h.view, 'h1')).toBeNull()
    expect(find(h.view, '[data-id="hilos-breadcrumb"]')).toBeNull()
    expect(find(h.view, '[data-id="hilos-page-tabs"]')).toBeNull()
    expect(
      find(h.view, '[data-id="analytics-person-head-description"]'),
    ).toBeNull()
    expect(h.view.container.querySelector('img')).toBeNull()
    expect(h.view.container.textContent).not.toContain('Ada')

    h.sharedIdentity('Ada (#7)', 'Ada Lovelace', '/ada-avatar')

    expect(find(h.view, 'h1')?.textContent).toBe('Ada (#7)')
    expect(find(h.view, '[data-id="hilos-page-head-skeleton"]')).toBeNull()
    expect(
      find(
        h.view,
        '[data-id="analytics-person-head-avatar"] img',
      )?.getAttribute('src'),
    ).toBe('/ada-avatar')
  })

  it('uses the target name, published photo and built-in image-error fallback', () => {
    const h = harness()
    h.sharedIdentity('Ada Lovelace (#7)', 'Ada Lovelace', '/first-avatar')

    const avatar = find(h.view, '[data-id="analytics-person-head-avatar"]')
    const photo = avatar?.querySelector('img')
    expect(find(h.view, 'h1')?.textContent).toBe('Ada Lovelace (#7)')
    expect(photo?.getAttribute('src')).toBe('/first-avatar')
    expect(photo?.getAttribute('alt')).toBe('')
    expect(avatar?.getAttribute('aria-hidden')).toBe('true')

    if (photo === null || photo === undefined) {
      throw new Error('The published person photo is rendered.')
    }
    fireEvent.error(photo)
    expect(avatar?.querySelector('img')).toBeNull()
    expect(avatar?.textContent).toBe('AL')

    h.sharedIdentity('Grace Hopper (#7)', 'Grace Hopper', '/second-avatar')
    expect(avatar?.querySelector('img')?.getAttribute('src')).toBe(
      '/second-avatar',
    )
    h.sharedIdentity('Grace Hopper (#7)', 'Grace Hopper')
    expect(avatar?.querySelector('img')).toBeNull()
    expect(avatar?.textContent).toBe('GH')
    expect(avatar?.hasAttribute('title')).toBe(false)
    expect(avatar?.hasAttribute('aria-label')).toBe(false)
  })

  it('preserves a live empty name and hides null or hidden identity data', () => {
    const h = harness()
    h.sharedIdentity('(#7)', '', '/empty-name-avatar')
    const avatar = find(h.view, '[data-id="analytics-person-head-avatar"]')

    expect(find(h.view, 'h1')?.textContent).toBe('(#7)')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('(#7)')
    expect(avatar?.querySelector('img')?.getAttribute('src')).toBe(
      '/empty-name-avatar',
    )

    h.sharedIdentity('', '', '/empty-name-avatar')
    expect(find(h.view, 'h1')?.textContent).toBe('')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('')

    h.sharedIdentity('(#7)', '', null)
    expect(avatar?.querySelector('img')).toBeNull()

    h.sharedIdentity('Ada (#7)', 'Ada', { _hidden: true })
    expect(avatar?.querySelector('img')).toBeNull()
    expect(avatar?.textContent).toBe('A')

    for (const name of [null, { _hidden: true }]) {
      h.sharedIdentity('Person', name, '/unpermitted-avatar')
      expect(avatar?.textContent).toBe('')
      expect(
        avatar?.querySelector('[data-id="hilos-avatar-icon"]'),
      ).not.toBeNull()
      expect(h.view.container.innerHTML).not.toContain('/unpermitted-avatar')
      expect(h.view.container.innerHTML).not.toContain('_hidden')
    }
  })

  it('reads the current owner lead from the catalog identity', () => {
    const h = harness()
    h.sharedIdentity('Ada (#7)', 'Ada', null, 'Project catalog description')
    const description = find(
      h.view,
      '[data-id="analytics-person-head-description"]',
    )

    expect(description?.tagName).toBe('P')
    expect(description?.textContent).toBe('Project catalog description')
    h.sharedIdentity('Ada (#7)', 'Ada', null, 'Updated catalog description')
    expect(
      find(h.view, '[data-id="analytics-person-head-description"]')
        ?.textContent,
    ).toBe('Updated catalog description')
    expect(h.view.container.textContent).not.toContain(LEAD)
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('uses only the active Person scope and releases its name binding on unmount', () => {
    const h = harness()
    h.sharedIdentity()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Ada (#7)')

    const outsidePerson = h.scopes.openPage(HilosPages.ANALYTICS_PEOPLE)
    outsidePerson.data.set('pageLabel', 'Another page')
    outsidePerson.data.set('pageHeadOwner', {
      page: PAGE,
      label: 'Person',
      lead: LEAD,
      icon: null,
    })
    outsidePerson.data.set('pageBreadcrumb', [
      { page: HilosPages.ANALYTICS, label: 'Analytics' },
      { page: PAGE, label: 'Person' },
    ])
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Person')

    h.scopes.openPage(PAGE)
    h.sharedIdentity()
    const activePerson = h.scopes.page()!
    h.view.unmount()
    activePerson.data.set('pageLabel', 'Changed after unmount')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Person')
  })

  it('takes a deleted account label from the page answer', () => {
    const h = harness()
    h.identity('Deleted user #7')
    expect(find(h.view, 'h1')?.textContent?.trim()).toBe('Deleted user #7')
    expect(find(h.view, '[data-id="hilos-page-head"]')).toBeNull()
    expect(find(h.view, '[data-id="hilos-admin-title"]')?.textContent).toBe(
      'Deleted user #7',
    )
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
    expect(find(h.view, 'h1')?.textContent?.trim()).toBe('Person')
    for (const id of ['first-seen', 'last-seen', 'browser', 'actions']) {
      expect(cells(h.view, id).length).toBeGreaterThan(0)
      expect(cells(h.view, id).every((text) => text === 'Hidden')).toBe(true)
    }
    expect(
      table(h.view).querySelector('a[data-id="hilos-analytics-session-link"]')
        ?.textContent,
    ).toBe('#57')
    expect(h.view.container.innerHTML).not.toContain('1970')
    expect(h.view.container.innerHTML).not.toContain('_hidden')
    expect(cells(h.view, 'actions')).not.toContain('0')
    expect(cells(h.view, 'browser')).not.toContain('—')
  })

  it('writes the session number as text when the application has no address for it', () => {
    const h = harness('7', () => undefined)
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    expect(
      h.view.container.querySelectorAll(
        'a[data-id="hilos-analytics-session-link"]',
      ),
    ).toHaveLength(0)
    const number = table(h.view).querySelector(
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
    expect(h.view.container.textContent).not.toContain(
      'No browser sessions where this person signed in last',
    )
    h.window([])
    expect(h.view.container.textContent).toContain(
      'No browser sessions where this person signed in last',
    )
    h.refuseWindow()
    expect(h.view.container.textContent).toContain('List unavailable')
    expect(h.view.container.textContent).not.toContain('No browser sessions')
  })

  it('replaces the controller on a new person, drops old rows and releases every listener on unmount', () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(cells(h.view, 'browser')).toEqual([SESSION.browser])

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
    expect(find(h.view, '[data-record="57"]')).toBeNull()
    expect(h.view.container.textContent).not.toContain('Ada')

    h.identity('Bob (#8)')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { user: 8 } }),
    )
    h.window([['60', { ...SESSION, browser: 'Chrome 141 / macOS' }]])
    expect(find(h.view, 'h1')?.textContent?.trim()).toBe('Bob (#8)')
    expect(cells(h.view, 'browser')).toEqual(['Chrome 141 / macOS'])
    expect(find(h.view, '[data-record="57"]')).toBeNull()

    h.view.unmount()
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
      find(h.view, '[data-id="hilos-analytics-browser-sessions-table"]'),
    ).toBeNull()
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        find(h.view, '[data-id="hilos-analytics-browser-sessions-table"]'),
      ).toBeNull()
      h.route({ userId: '7' })
      expect(h.registered.size).toBe(1)
      expect(
        find(h.view, '[data-id="hilos-analytics-browser-sessions-table"]'),
      ).not.toBeNull()
    },
  )
})
