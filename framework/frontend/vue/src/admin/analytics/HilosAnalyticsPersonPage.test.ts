import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  createAppPageRouter,
  createHilosRouter,
  HilosPages,
  PageSubscription,
  resolveHilosPath,
  ScopeManager,
  sessionUserName,
  USER_ENTITY_TYPE,
  type HilosConnection,
  type HilosRouter,
  type TableViewportController,
} from '@hilos/core/base'
import {
  formatAnalyticsTime,
  type HilosAnalyticsBrowserSessionRow,
} from '@hilos/core/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'

import HilosAnalyticsPersonPage from './HilosAnalyticsPersonPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

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
  const subscription = new PageSubscription(
    {
      state: 'connected',
      reconnectDragging: false,
      send: () => true,
      on: () => () => {},
      forgetPageFrames: () => {
        heldPageResponse = undefined
      },
      tableWindowDescriptors: () => ({}),
    },
    scopes,
  )
  let path = `/hilos/analytics/people/${param ?? ''}`
  const navigation = createHilosRouter(createAppPageRouter({}), subscription, {
    pathname: () => path,
    pushState: (next) => {
      path = next
    },
    replaceState: (next) => {
      path = next
    },
    onPopState: () => () => {},
  })
  navigation.start()
  releases.push(navigation.stop)
  const router: HilosRouter = { ...navigation, resolvePath }
  const view = mount(HilosAnalyticsPersonPage, {
    props: { context: markRaw({ connection, scopes }) },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') heldPageResponse = { type, data }
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
  }

  return {
    view,
    router,
    scopes,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    navigate(id: string) {
      router.navigate(`/hilos/analytics/people/${id}`)
    },
    identity(label = 'Ada (#7)') {
      subscription.ingestPageResponse(PAGE, {
        data: {
          pageLabel: label,
          pageLead: LEAD,
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.ANALYTICS, label: 'Analytics' },
            { page: HilosPages.ANALYTICS_PEOPLE, label: 'People' },
            { page: PAGE, label: 'Person' },
          ],
        },
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

/** The wide branch of the table; the narrow cards repeat the same cells. */
function table(view: View) {
  return view.find('[data-id="hilos-analytics-browser-sessions-table"] table')
}

/**
 * The texts of one column's cells in the wide branch, in row order.
 *
 * @param view The mounted page.
 * @param id The cell's data-id suffix.
 */
function cells(view: View, id: string) {
  return table(view)
    .findAll(`[data-id="hilos-analytics-session-${id}"]`)
    .map((node) => node.text())
}

describe('HilosAnalyticsPersonPage', () => {
  it('draws the shared identity, the person preset and real cells with session links', async () => {
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
    await nextTick()

    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('h1').text()).toBe('Ada (#7)')
    expect(h.view.text()).toContain(LEAD)
    const breadcrumb = h.view.find('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb.findAll('li').map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Analytics',
      'People',
      'Ada (#7)',
    ])
    expect(
      breadcrumb
        .findAll('a')
        .map((link) => [link.attributes('data-id'), link.attributes('href')]),
    ).toEqual([
      ['hilos-breadcrumb-hilos', '/hilos'],
      ['hilos-breadcrumb-hilos_analytics', '/hilos/analytics'],
      ['hilos-breadcrumb-hilos_analytics_people', '/hilos/analytics/people'],
    ])
    expect(breadcrumb.find('[aria-current="page"]').text()).toBe('Ada (#7)')
    expect(breadcrumb.find('[aria-current="page"] a').exists()).toBe(false)
    expect(h.router.pageIdentity.get()?.pageHeadOwner).toBeUndefined()
    expect(h.scopes.page()!.data.signal('pageBreadcrumb').get()).toEqual([
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.ANALYTICS, label: 'Analytics' },
      { page: HilosPages.ANALYTICS_PEOPLE, label: 'People' },
      { page: PAGE, label: 'Person' },
    ])
    expect(h.view.findAll('h2')).toHaveLength(0)
    expect(table(h.view).attributes('aria-labelledby')).toBe(
      h.view.find('h1').attributes('id'),
    )

    const links = table(h.view).findAll(
      'a[data-id="hilos-analytics-session-link"]',
    )
    expect(links.map((link) => link.text())).toEqual(['#58', '#57'])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
    expect(links.map((link) => link.attributes('data-record'))).toEqual([
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
      table(h.view)
        .findAll('[data-id="hilos-analytics-session-actions"]')
        .map((node) => node.attributes('data-record')),
    ).toEqual(['58', '57'])
    // A narrow screen draws the same rows as cards titled by the session link.
    expect(
      h.view
        .findAll(
          '[data-id="hilos-table-cards"] a[data-id="hilos-analytics-session-link"]',
        )
        .map((link) => link.attributes('href')),
    ).toEqual([
      '/hilos/analytics/browser-sessions/58',
      '/hilos/analytics/browser-sessions/57',
    ])
  })

  it('takes a deleted account label from the page answer', async () => {
    const h = harness()
    h.identity('Deleted user #7')
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Deleted user #7')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Deleted user #7')
  })

  it('draws Hidden in every value without epoch dates, zeros or values in attributes', async () => {
    const h = harness()
    const currentUser = { type: USER_ENTITY_TYPE, id: 99 }
    h.scopes.session.entities.upsert(currentUser, { name: 'Session owner' })
    h.scopes.session.data.set('currentUser', currentUser)
    expect(sessionUserName(h.scopes).get()).toBe('Session owner')
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
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Person')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Person')
    expect(h.view.html()).not.toContain('Session owner')
    for (const id of ['first-seen', 'last-seen', 'browser', 'actions']) {
      expect(cells(h.view, id).length).toBeGreaterThan(0)
      expect(cells(h.view, id).every((text) => text === 'Hidden')).toBe(true)
    }
    expect(
      table(h.view).find('a[data-id="hilos-analytics-session-link"]').text(),
    ).toBe('#57')
    expect(h.view.html()).not.toContain('1970')
    expect(h.view.html()).not.toContain('_hidden')
    expect(cells(h.view, 'actions')).not.toContain('0')
    expect(cells(h.view, 'browser')).not.toContain('—')
  })

  it('writes the session number as text when the application has no address for it', async () => {
    const h = harness('7', () => undefined)
    h.identity()
    h.coldWindow()
    h.window([['57', SESSION]])
    await nextTick()
    expect(h.view.findAll('a[data-id="hilos-analytics-session-link"]')).toEqual(
      [],
    )
    const number = table(h.view).find(
      '[data-id="hilos-analytics-session-link"]',
    )
    expect(number.text()).toBe('#57')
    expect(number.attributes('data-record')).toBe('57')
    expect(number.attributes('href')).toBeUndefined()
  })

  it('uses the table loading, empty and refusal states', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    await nextTick()
    expect(h.view.text()).not.toContain(
      'No browser sessions where this person signed in last',
    )
    h.window([])
    await nextTick()
    expect(h.view.text()).toContain(
      'No browser sessions where this person signed in last',
    )
    h.refuseWindow()
    await nextTick()
    expect(h.view.text()).toContain('List unavailable')
    expect(h.view.text()).not.toContain('No browser sessions')
  })

  it('replaces the controller on a new person, drops old rows and releases every listener on unmount', async () => {
    const h = harness()
    h.identity()
    const previousScope = h.scopes.page()!
    h.coldWindow()
    h.window([['57', SESSION]])
    await nextTick()
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(cells(h.view, 'browser')).toEqual([SESSION.browser])

    h.navigate('8')
    await nextTick()
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
    await nextTick()
    expect(h.view.find('[data-record="57"]').exists()).toBe(false)
    expect(h.view.text()).not.toContain('Ada')
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(h.view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)

    h.identity('Bob (#8)')
    previousScope.data.set('pageLabel', 'Old scope name')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { user: 8 } }),
    )
    h.window([['60', { ...SESSION, browser: 'Chrome 141 / macOS' }]])
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Bob (#8)')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Bob (#8)')
    expect(h.view.text()).not.toContain('Old scope name')
    expect(cells(h.view, 'browser')).toEqual(['Chrome 141 / macOS'])
    expect(h.view.find('[data-record="57"]').exists()).toBe(false)

    h.view.unmount()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('Person')
    h.identity('Changed after unmount (#8)')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('Person')
  })

  it('reacts to the current permitted label, including an empty string', async () => {
    const h = harness()
    h.identity()
    await nextTick()
    h.identity('Renamed (#7)')
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Renamed (#7)')
    h.identity('')
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('')
  })

  it('keeps permitted names separate between scope managers', async () => {
    const first = harness()
    const second = harness('8')
    first.identity()
    second.identity('Bob (#8)')
    await nextTick()
    expect(
      first.view
        .find('[data-id="hilos-breadcrumb"] [aria-current="page"]')
        .text(),
    ).toBe('Ada (#7)')
    expect(
      second.view
        .find('[data-id="hilos-breadcrumb"] [aria-current="page"]')
        .text(),
    ).toBe('Bob (#8)')
  })

  it('keeps the catalog caption outside the current Person page scope', async () => {
    const h = harness()
    h.identity()
    await nextTick()
    h.router.navigate('/hilos/analytics/people')
    const data = h.scopes.page()!.data
    data.set('pageLabel', 'Another page')
    data.set('pageBreadcrumb', [
      { page: HilosPages.ANALYTICS, label: 'Analytics' },
      { page: PAGE, label: 'Person' },
    ])
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Person')
    h.scopes.dropPage()
    await nextTick()
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(h.view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
  })

  it('releases the existing controller when the route loses its person', async () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.router.navigate('/hilos/analytics/people')
    await nextTick()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(
      h.view
        .find('[data-id="hilos-analytics-browser-sessions-table"]')
        .exists(),
    ).toBe(false)
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    async (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        h.view
          .find('[data-id="hilos-analytics-browser-sessions-table"]')
          .exists(),
      ).toBe(false)
      h.navigate('7')
      await nextTick()
      expect(h.registered.size).toBe(1)
      expect(
        h.view
          .find('[data-id="hilos-analytics-browser-sessions-table"]')
          .exists(),
      ).toBe(true)
    },
  )
})
