// The Angular peer of vue/src/admin/analytics/HilosAnalyticsPersonPage.test.ts
// and react/test/HilosAnalyticsPersonPage.test.tsx, under the same case names:
// the person's browser sessions with links to each session, the Hidden view, the
// table states, and one controller per person in the address.
import { TestBed } from '@angular/core/testing'
import {
  computedSignal,
  createSignal,
  formatAnalyticsTime,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  sessionUserName,
  sessionUserPhoto,
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

function personIdentity(
  scopes: ScopeManager,
  label = 'Ada (#7)',
  name: unknown = 'Ada Lovelace',
  photo: unknown = null,
) {
  const data = scopes.page()!.data
  data.set('pageLabel', label)
  data.set('pageLead', LEAD)
  data.set('name', name)
  data.set('photo', photo)
  data.set('pageHeadOwner', { page: PAGE, label: 'Person', lead: LEAD })
  data.set('pageBreadcrumb', [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.ANALYTICS, label: 'Analytics' },
    { page: HilosPages.ANALYTICS_PEOPLE, label: 'People' },
    { page: PAGE, label: 'Person' },
  ])
  data.set('pageTabs', [{ page: PAGE, label: 'Overview' }])
  data.set('pageChildren', [])
}

function harness(
  param: string | undefined = '7',
  resolvePath: HilosRouter['resolvePath'] = resolveHilosPath,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const currentUser = { type: 'user', id: 99 }
  scopes.session.entities.upsert(currentUser, {
    name: 'Session user',
    photo: '/session.jpg',
  })
  scopes.session.data.set('currentUser', currentUser)
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE,
    params: param === undefined ? {} : { userId: param },
    admin: true,
  })
  const identity = createSignal(pageIdentity(scopes))
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal('/hilos/analytics/people/7'),
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
    scopes,
    router,
    connection,
    root: fixture.nativeElement as HTMLElement,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    setContext(nextScopes: ScopeManager) {
      identity.set(pageIdentity(nextScopes))
      fixture.componentRef.setInput('context', {
        connection,
        scopes: nextScopes,
      })
      fixture.detectChanges()
    },
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
    identity(
      label = 'Ada (#7)',
      name: unknown = 'Ada Lovelace',
      photo: unknown = null,
    ) {
      personIdentity(scopes, label, name, photo)
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
  it('creates no real avatar, description or name before the owner answer', () => {
    const h = harness()
    expect(sessionUserName(h.scopes).get()).toBe('Session user')
    expect(sessionUserPhoto(h.scopes).get()).toBe('/session.jpg')
    const data = h.scopes.page()!.data
    data.set('name', 'Unanswered person')
    data.set('photo', '/unanswered.jpg')
    h.fixture.detectChanges()

    expect(find(h, '[data-id="hilos-page-head-skeleton"]')).not.toBeNull()
    expect(find(h, 'h1')).toBeNull()
    expect(find(h, 'hilos-avatar')).toBeNull()
    expect(find(h, '[data-id="analytics-person-head-avatar"]')).toBeNull()
    expect(find(h, '[data-id="analytics-person-head-description"]')).toBeNull()
    expect(find(h, 'nav')).toBeNull()
    expect(h.root.innerHTML).not.toContain('/session.jpg')
    expect(h.root.innerHTML).not.toContain('/unanswered.jpg')
    expect(h.root.textContent).not.toContain('Unanswered person')
    expect(h.root.textContent).not.toContain('Session user')
  })

  it('uses the target raw name and photo while the permitted label names the head and breadcrumb', () => {
    const h = harness()
    expect(sessionUserName(h.scopes).get()).toBe('Session user')
    expect(sessionUserPhoto(h.scopes).get()).toBe('/session.jpg')
    h.identity('Permitted label (#7)', 'Ada Lovelace', '/ada.jpg')

    expect(find(h, 'h1')?.textContent?.trim()).toBe('Permitted label (#7)')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Permitted label (#7)',
    )
    const crumbs = [
      ...h.root.querySelectorAll('nav[aria-label="Breadcrumb"] li'),
    ]
    const lastCrumb = crumbs.at(-1)!.cloneNode(true) as HTMLElement
    lastCrumb.querySelector('[aria-hidden="true"]')?.remove()
    expect(lastCrumb.textContent?.trim()).toBe('Permitted label (#7)')
    expect(crumbs.at(-1)?.getAttribute('aria-current')).toBe('page')
    expect(crumbs.at(-1)?.querySelector('a')).toBeNull()
    expect(
      find(h, `a[href="/hilos/analytics/people"]`)?.textContent?.trim(),
    ).toBe('People')
    const avatar = find(h, '[data-id="analytics-person-head-avatar"]')
    expect(avatar?.getAttribute('aria-hidden')).toBe('true')
    const photo = avatar?.querySelector('img')
    expect(photo?.getAttribute('src')).toBe('/ada.jpg')
    expect(photo?.getAttribute('alt')).toBe('')
    expect(h.root.innerHTML).not.toContain('/session.jpg')
    expect(h.root.textContent).not.toContain('Session user')
    expect(
      find(
        h,
        '[data-id="analytics-person-head-description"]',
      )?.textContent?.trim(),
    ).toBe(LEAD)
    expect(find(h, '[data-id="hilos-page-tabs"]')).toBeNull()

    photo?.dispatchEvent(new Event('error'))
    h.fixture.detectChanges()
    expect(find(h, '[data-id="hilos-avatar-photo"]')).toBeNull()
    expect(find(h, '[data-id="hilos-avatar"]')?.textContent?.trim()).toBe('AL')
  })

  it('uses initials from the raw name without a photo or the label number', () => {
    const h = harness()
    h.identity('Different permitted label (#7)', 'Ada Lovelace')
    expect(find(h, '[data-id="hilos-avatar"]')?.textContent?.trim()).toBe('AL')
    expect(find(h, '[data-id="hilos-avatar-photo"]')).toBeNull()
  })

  it('keeps a live empty name and its photo distinct from a deleted person', () => {
    const h = harness()
    h.identity(' (#7)', '', '/empty-name.jpg')
    expect(find(h, 'h1')?.textContent?.trim()).toBe('(#7)')
    expect(find(h, '[data-id="hilos-avatar-photo"]')?.getAttribute('src')).toBe(
      '/empty-name.jpg',
    )
    expect(find(h, '[data-id="hilos-avatar-icon"]')).toBeNull()
    expect(h.root.textContent).not.toContain('Deleted')
  })

  it.each([
    ['hidden name', { _hidden: true }, '/private.jpg', ''],
    ['hidden photo', 'Ada Lovelace', { _hidden: true }, 'AL'],
  ])('does not use a %s as an image source', (_case, name, photo, initials) => {
    const h = harness()
    h.identity('Person', name, photo)
    expect(find(h, '[data-id="hilos-avatar-photo"]')).toBeNull()
    expect(find(h, '[data-id="hilos-avatar"]')?.textContent?.trim()).toBe(
      initials,
    )
    expect(h.root.innerHTML).not.toContain('/private.jpg')
    expect(h.root.innerHTML).not.toContain('_hidden')
  })

  it('keeps an empty permitted label and reads the description from the owner lead', () => {
    const h = harness()
    h.identity('', 'Ada Lovelace')
    h.scopes.page()!.data.set('pageHeadOwner', {
      page: PAGE,
      label: 'Person',
      lead: 'Project catalog lead',
    })
    h.fixture.detectChanges()
    expect(find(h, 'h1')?.textContent?.trim()).toBe('')
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('')
    expect(
      find(
        h,
        '[data-id="analytics-person-head-description"]',
      )?.textContent?.trim(),
    ).toBe('Project catalog lead')
  })

  it('drops the name and photo outside the Person scope and on a cold replacement', () => {
    const h = harness()
    h.identity('Ada (#7)', 'Ada Lovelace', '/ada.jpg')
    const other = h.scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
    personIdentity(h.scopes, 'Other page label', 'Other person', '/other.jpg')
    h.fixture.detectChanges()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Person')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('Person')
    expect(find(h, '[data-id="hilos-avatar-photo"]')).toBeNull()
    expect(find(h, '[data-id="hilos-avatar-icon"]')).not.toBeNull()
    other.data.set('pageLabel', 'Another unrelated label')
    h.fixture.detectChanges()
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Person')

    h.scopes.dropPage()
    h.fixture.detectChanges()
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(find(h, 'h1')).toBeNull()
    expect(find(h, 'hilos-avatar')).toBeNull()
    h.scopes.openPage(PAGE)
    h.fixture.detectChanges()
    expect(find(h, 'h1')).toBeNull()
    expect(h.root.innerHTML).not.toContain('/ada.jpg')
    expect(h.root.innerHTML).not.toContain('/other.jpg')
  })

  it('releases the former context mirror and name binding, then releases both on destroy', () => {
    const h = harness()
    h.identity('Ada (#7)', 'Ada Lovelace', '/ada.jpg')
    const next = new ScopeManager()
    next.openPage(PAGE)
    h.setContext(next)
    expect(pageIdentity(h.scopes).get()?.pageHeadOwner?.label).toBe('Person')
    expect(pageIdentity(h.scopes).get()?.breadcrumb.at(-1)?.label).toBe(
      'Person',
    )
    expect(find(h, 'h1')).toBeNull()
    expect(find(h, 'hilos-avatar')).toBeNull()

    personIdentity(next, 'Bob (#7)', 'Bob Target', '/bob.jpg')
    h.fixture.detectChanges()
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Bob (#7)')
    expect(find(h, '[data-id="hilos-avatar-photo"]')?.getAttribute('src')).toBe(
      '/bob.jpg',
    )
    h.identity('Former context update', 'Former person', '/former.jpg')
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Bob (#7)')
    expect(find(h, '[data-id="hilos-avatar-photo"]')?.getAttribute('src')).toBe(
      '/bob.jpg',
    )
    expect(pageIdentity(next).get()?.breadcrumb.at(-1)?.label).toBe('Bob (#7)')

    h.fixture.destroy()
    expect(pageIdentity(next).get()?.pageHeadOwner?.label).toBe('Person')
    expect(pageIdentity(next).get()?.breadcrumb.at(-1)?.label).toBe('Person')
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })

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
    h.identity('Deleted user #7', null, '/should-not-load.jpg')
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Deleted user #7')
    expect(find(h, '[data-id="hilos-avatar-photo"]')).toBeNull()
    expect(find(h, '[data-id="hilos-avatar-icon"]')).not.toBeNull()
  })

  it('draws Hidden in every value without epoch dates, zeros or values in attributes', () => {
    const h = harness()
    h.identity('Person', { _hidden: true }, { _hidden: true })
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
    h.identity('Ada (#7)', 'Ada Lovelace', '/ada.jpg')
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
    expect(find(h, 'hilos-avatar')).toBeNull()
    expect(find(h, '[data-id="analytics-person-head-description"]')).toBeNull()
    expect(h.root.innerHTML).not.toContain('/ada.jpg')

    h.identity('Bob (#8)', 'Bob Target', '/bob.jpg')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { user: 8 } }),
    )
    h.window([['60', { ...SESSION, browser: 'Chrome 141 / macOS' }]])
    expect(find(h, 'h1')?.textContent?.trim()).toBe('Bob (#8)')
    expect(find(h, '[data-id="hilos-avatar-photo"]')?.getAttribute('src')).toBe(
      '/bob.jpg',
    )
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
