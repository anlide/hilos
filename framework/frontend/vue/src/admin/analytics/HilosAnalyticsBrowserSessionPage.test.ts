import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  createAppPageRouter,
  createHilosRouter,
  HilosPages,
  PageSubscription,
  ScopeManager,
  sessionUserName,
  USER_ENTITY_TYPE,
  type HilosConnection,
  type TableViewportController,
} from '@hilos/core/base'
import {
  formatAnalyticsTime,
  type HilosAnalyticsActionRow,
} from '@hilos/core/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'

import HilosAnalyticsBrowserSessionPage from './HilosAnalyticsBrowserSessionPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

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

function harness(param: string | undefined = '57') {
  const scopes = new ScopeManager()
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
  let path = `/hilos/analytics/browser-sessions/${param ?? ''}`
  const router = createHilosRouter(createAppPageRouter({}), subscription, {
    pathname: () => path,
    pushState: (next) => {
      path = next
    },
    replaceState: (next) => {
      path = next
    },
    onPopState: () => () => {},
  })
  router.start()
  releases.push(router.stop)
  const view = mount(HilosAnalyticsBrowserSessionPage, {
    props: { context: markRaw({ connection, scopes }) },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  function emit(event: string, data: unknown, type?: string) {
    if (type === 'page_response') heldPageResponse = { type, data }
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
  }

  return {
    view,
    scopes,
    router,
    registered,
    listeners,
    sendTableViewport,
    unregisterTableWindow,
    navigate(id: string) {
      router.navigate(`/hilos/analytics/browser-sessions/${id}`)
    },
    header(
      value: unknown,
      label = 'Browser session #57',
      owner: typeof OWNER | null = OWNER,
    ) {
      subscription.ingestPageResponse(PAGE, {
        data: {
          pageLabel: label,
          pageLead: LEAD,
          pageHeadOwner: owner ?? undefined,
          pageBreadcrumb: BREADCRUMB,
          pageTabs: owner ? [{ page: PAGE, label: 'Overview' }] : undefined,
          pageChildren: [],
          browserSession: value,
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

/**
 * The adopted identity stays ready independently of the body and table window.
 *
 * @param h The mounted page and its live subscription.
 * @param label The permitted instance name.
 * @param owner The catalog metadata from the current answer.
 */
function expectHead(
  h: ReturnType<typeof harness>,
  label: string,
  owner = OWNER,
) {
  expect(h.view.findAll('h1')).toHaveLength(1)
  const title = h.view.find('[data-id="hilos-page-head-title"]')
  expect(title.element.tagName).toBe('H1')
  expect(title.text()).toBe(label)
  expect(h.router.pageIdentity.get()?.pageHeadOwner).toEqual({
    ...owner,
    label,
  })
  const icon = h.view.find('[data-id="analytics-browser-session-head-icon"]')
  expect(icon.classes()).toContain('rounded-circle')
  expect(icon.attributes('aria-hidden')).toBe('true')
  expect(icon.find('i').classes()).toEqual(['bi', owner.icon])
  expect(
    h.view
      .find('[data-id="analytics-browser-session-head-description"]')
      .text(),
  ).toBe(owner.lead)
  for (const id of [
    'hilos-admin-title',
    'hilos-admin-title-skeleton',
    'hilos-admin-children',
    'hilos-page-tabs',
    'hilos-page-head-skeleton',
  ]) {
    expect(h.view.find(`[data-id="${id}"]`).exists()).toBe(false)
  }
}

/**
 * A pending identity has only a blank circle and the shared head's two bars.
 *
 * @param h The mounted page and its live subscription.
 */
function expectHeadSkeleton(h: ReturnType<typeof harness>) {
  const skeleton = h.view.find('[data-id="hilos-page-head-skeleton"]')
  expect(skeleton.attributes('aria-hidden')).toBe('true')
  const icon = skeleton.find(
    '[data-id="analytics-browser-session-head-skeleton-icon"]',
  )
  expect(icon.classes()).toEqual([
    'hilos-avatar-md',
    'rounded-circle',
    'bg-secondary-subtle',
    'flex-shrink-0',
  ])
  expect(icon.text()).toBe('')
  expect(icon.element.children).toHaveLength(0)
  expect(skeleton.findAll('.placeholder')).toHaveLength(2)
  expect(h.view.find('h1').exists()).toBe(false)
  expect(h.view.find('nav').exists()).toBe(false)
  expect(h.view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
  expect(
    h.view.find('[data-id="analytics-browser-session-head-icon"]').exists(),
  ).toBe(false)
  expect(
    h.view
      .find('[data-id="analytics-browser-session-head-description"]')
      .exists(),
  ).toBe(false)
  expect(h.view.text()).not.toContain(LEAD)
}

describe('HilosAnalyticsBrowserSessionPage', () => {
  it('draws the received identity, header and real cells in the shared table', async () => {
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
    await nextTick()

    expectHead(h, 'Browser session #57')
    const breadcrumb = h.view.find('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb.findAll('li').map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Analytics',
      'Browser sessions',
      'Browser session #57',
    ])
    expect(
      breadcrumb.findAll('a').map((link) => link.attributes('href')),
    ).toEqual([
      '/hilos',
      '/hilos/analytics',
      '/hilos/analytics/browser-sessions',
    ])
    expect(breadcrumb.find('[aria-current="page"]').text()).toBe(
      'Browser session #57',
    )
    expect(breadcrumb.find('[aria-current="page"] a').exists()).toBe(false)
    expect(h.scopes.page()!.data.signal('pageBreadcrumb').get()).toEqual(
      BREADCRUMB,
    )
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-browser"]').text(),
    ).toBe(HEADER.browser)
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-person"]').text(),
    ).toBe('Last signed in: Ada (#7)')
    expect(h.view.find('[data-id="hilos-analytics-action-when"]').text()).toBe(
      formatAnalyticsTime(ACTION.createdTs),
    )
    expect(h.view.find('[data-id="hilos-analytics-action-page"]').text()).toBe(
      '—',
    )
    expect(h.view.find('[data-id="hilos-analytics-action-name"]').text()).toBe(
      'rename',
    )
    expect(h.view.find('[data-created-ts]').attributes('data-created-ts')).toBe(
      String(ACTION.createdTs),
    )
    expect(
      h.view
        .find('[data-id="hilos-analytics-action-name"]')
        .attributes('data-record'),
    ).toBe('9')
    expect(
      h.view
        .find('[data-id="hilos-analytics-actions-table"] table')
        .attributes('aria-labelledby'),
    ).toBe(h.view.find('h1').attributes('id'))
    expect(h.view.find('h1').attributes('id')).toBeTruthy()
    expect(h.view.findAll('h2')).toHaveLength(0)
  })

  it('takes the icon and description from the owner identity', async () => {
    const h = harness()
    const owner = {
      ...OWNER,
      lead: 'Catalog description for this browser session.',
      icon: 'bi-browser-firefox',
    }
    h.header(HEADER, 'Permitted label', owner)
    await nextTick()
    expectHead(h, 'Permitted label', owner)
    expect(h.view.text()).not.toContain(LEAD)
    expect(h.view.find('.bi-window').exists()).toBe(false)
  })

  it('keeps the legacy shell for an answer without an adopted owner', async () => {
    const h = harness()
    h.header(HEADER, 'Browser session #57', null)
    h.coldWindow()
    h.window([ACTION])
    await nextTick()
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('[data-id="hilos-admin-title"]').text()).toBe(
      'Browser session #57',
    )
    expect(h.view.text()).toContain(LEAD)
    expect(h.view.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    expect(
      h.view.find('[data-id="analytics-browser-session-head-icon"]').exists(),
    ).toBe(false)
    expect(
      h.view
        .find('[data-id="analytics-browser-session-head-description"]')
        .exists(),
    ).toBe(false)
    expect(h.view.find('[data-id="hilos-analytics-action-name"]').text()).toBe(
      'rename',
    )
  })

  it('shows the guest sentence only for the received null account and a dash for no browser', async () => {
    const h = harness()
    h.header({
      ...HEADER,
      browser: null,
      lastSignedInUserId: null,
      lastSignedInUserLabel: null,
    })
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-browser"]').text(),
    ).toBe('—')
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-person"]').text(),
    ).toBe('No one has signed in in this browser session')
  })

  it('takes the current deleted-account label from each page answer', async () => {
    const h = harness()
    h.header(HEADER)
    await nextTick()
    h.header({ ...HEADER, lastSignedInUserLabel: 'Deleted user #7' })
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-person"]').text(),
    ).toBe('Last signed in: Deleted user #7')
    expect(h.view.text()).not.toContain('Ada')
  })

  it('draws Hidden for the whole header and every cell without epoch dates or personal attributes', async () => {
    const h = harness()
    const currentUser = { type: USER_ENTITY_TYPE, id: 99 }
    h.scopes.session.entities.upsert(currentUser, { name: 'Session owner' })
    h.scopes.session.data.set('currentUser', currentUser)
    expect(sessionUserName(h.scopes).get()).toBe('Session owner')
    h.header(HEADER)
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Browser session #57')
    h.header({ _hidden: true }, 'Browser session')
    h.coldWindow()
    h.window([
      {
        createdTs: { _hidden: true },
        pageName: { _hidden: true },
        actionName: { _hidden: true },
      },
    ])
    await nextTick()
    expectHead(h, 'Browser session')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Browser session')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Browser session',
    )
    expect(h.view.html()).not.toContain('Browser session #57')
    expect(h.view.html()).not.toContain('Session owner')
    expect(h.view.html()).not.toContain('Ada')
    expect(h.view.html()).not.toContain(HEADER.browser)
    expect(
      h.view.find('[data-id="hilos-analytics-browser-session-header"]').text(),
    ).toBe('Hidden')
    expect(
      h.view
        .find('[data-id="hilos-analytics-browser-session-person"]')
        .exists(),
    ).toBe(false)
    for (const cell of ['when', 'page', 'name']) {
      const nodes = h.view.findAll(`[data-id="hilos-analytics-action-${cell}"]`)
      expect(nodes.length).toBeGreaterThan(0)
      for (const node of nodes) {
        expect(node.text()).toBe('Hidden')
      }
    }
    expect(h.view.findAll('[data-created-ts]')).toHaveLength(0)
    expect(h.view.html()).not.toContain('1970')
    expect(h.view.html()).not.toContain('No one has signed in')
    expect(h.view.html()).not.toContain('_hidden')
  })

  it('shows a blank circle and two head bars with the two body bars before identity arrives', async () => {
    const h = harness()
    await nextTick()
    expectHeadSkeleton(h)
    const body = h.view.find(
      '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]',
    )
    const bars = body.findAll('.placeholder')
    expect(bars).toHaveLength(2)
    expect(bars[0]!.classes()).toContain('col-6')
    expect(bars[1]!.classes()).toContain('col-8')
    expect(bars.every((bar) => bar.attributes('aria-hidden') === 'true')).toBe(
      true,
    )
    expect(h.view.text()).not.toContain('Browser session')
    expect(h.view.text()).not.toContain(HEADER.browser)
    expect(h.view.text()).not.toContain('Ada')
  })

  it('keeps absent and malformed headers as skeletons and uses the table loading, empty and refusal states', async () => {
    const h = harness()
    expect(
      h.view
        .find(
          '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]',
        )
        .exists(),
    ).toBe(true)
    expect(h.view.text()).not.toContain('No actions recorded')
    h.header({ id: 57 })
    h.coldWindow()
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(
      h.view
        .find('[data-id="hilos-analytics-browser-session-person"]')
        .exists(),
    ).toBe(false)
    expect(
      h.view
        .find(
          '[data-id="hilos-analytics-browser-session-header"] [data-id="hilos-skeleton"]',
        )
        .exists(),
    ).toBe(true)
    expect(
      h.view.find('[data-id="hilos-table-loading"]').attributes('aria-busy'),
    ).toBe('true')
    expect(h.view.text()).not.toContain('No actions recorded')
    h.header(HEADER)
    h.window([])
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(h.view.text()).toContain(
      'No actions recorded in this browser session yet',
    )
    h.refuseWindow()
    await nextTick()
    expectHead(h, 'Browser session #57')
    expect(h.view.text()).toContain('List unavailable')
    expect(h.view.text()).not.toContain('No actions recorded')
  })

  it('replaces the controller on a new route, drops old rows and releases every listener on unmount', async () => {
    const h = harness()
    h.header(HEADER)
    const previousScope = h.scopes.page()!
    h.coldWindow()
    h.window([ACTION])
    await nextTick()
    const old = h.registered.get(TABLE)
    const oldListeners = [...h.listeners.values()].flatMap((set) => [...set])
    expect(h.view.find('[data-id="hilos-analytics-action-name"]').text()).toBe(
      'rename',
    )

    h.navigate('58')
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
    expect(
      h.view.find('[data-id="hilos-analytics-action-name"]').exists(),
    ).toBe(false)
    expect(h.view.text()).not.toContain('Ada')

    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(h.view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expectHeadSkeleton(h)
    expect(h.view.text()).not.toContain('Browser session #57')
    h.header({ ...HEADER, id: 58 }, 'Browser session #58')
    previousScope.data.set('pageLabel', 'Old scope label')
    h.coldWindow()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { browserSession: 58 } }),
    )
    h.window([{ ...ACTION, actionName: 'open' }], '10')
    await nextTick()
    expectHead(h, 'Browser session #58')
    expect(h.view.find('[data-id="hilos-analytics-action-name"]').text()).toBe(
      'open',
    )
    expect(h.view.find('[data-record="9"]').exists()).toBe(false)
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Browser session #58')
    expect(h.view.text()).not.toContain('Old scope label')

    h.view.unmount()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(2)
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Browser session',
    )
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session',
    )
    h.header({ ...HEADER, id: 58 }, 'Changed after unmount')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Browser session',
    )
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Browser session',
    )
  })

  it('reacts to permitted label updates without deriving a name from the route or header', async () => {
    const h = harness()
    h.header(HEADER, 'Permitted label')
    await nextTick()
    expectHead(h, 'Permitted label')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Permitted label')
    h.scopes.page()!.data.set('pageLabel', 'Updated permitted label')
    await nextTick()
    expectHead(h, 'Updated permitted label')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('Updated permitted label')
    h.scopes.page()!.data.set('pageLabel', '')
    await nextTick()
    expectHead(h, '')
    expect(
      h.view.find('[data-id="hilos-breadcrumb"] [aria-current="page"]').text(),
    ).toBe('')
  })

  it.each([undefined, null, 57, { _hidden: true }])(
    'clears the identity when the current label is not a string: %j',
    async (label) => {
      const h = harness()
      h.header(HEADER)
      await nextTick()
      h.scopes.page()!.data.set('pageLabel', label)
      await nextTick()
      expect(h.router.pageIdentity.get()).toBeUndefined()
      expect(h.view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
      expectHeadSkeleton(h)
      h.header(HEADER, 'Browser session')
      await nextTick()
      expectHead(h, 'Browser session')
      expect(
        h.view
          .find('[data-id="hilos-breadcrumb"] [aria-current="page"]')
          .text(),
      ).toBe('Browser session')
    },
  )

  it('keeps permitted names separate between scope managers', async () => {
    const first = harness()
    const second = harness('58')
    first.header(HEADER)
    second.header({ ...HEADER, id: 58 }, 'Browser session #58')
    await nextTick()
    expectHead(first, 'Browser session #57')
    expectHead(second, 'Browser session #58')
    expect(
      first.view
        .find('[data-id="hilos-breadcrumb"] [aria-current="page"]')
        .text(),
    ).toBe('Browser session #57')
    expect(
      second.view
        .find('[data-id="hilos-breadcrumb"] [aria-current="page"]')
        .text(),
    ).toBe('Browser session #58')
  })

  it('keeps the catalog caption on another page scope and clears it without a scope', async () => {
    const h = harness()
    h.header(HEADER)
    await nextTick()
    h.router.navigate('/hilos/analytics/browser-sessions')
    const data = h.scopes.page()!.data
    data.set('pageLabel', 'Another page label')
    data.set('pageBreadcrumb', BREADCRUMB)
    data.set('pageHeadOwner', OWNER)
    data.set('pageTabs', [{ page: PAGE, label: 'Overview' }])
    await nextTick()
    expectHead(h, 'Browser session')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Browser session',
    )
    expect(
      h.view.findAll('[data-id="hilos-breadcrumb"] li').at(-1)?.text(),
    ).toBe('Browser session')
    h.scopes.dropPage()
    await nextTick()
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(h.view.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expectHeadSkeleton(h)
  })

  it('releases the existing controller when the route loses its session number', async () => {
    const h = harness()
    expect(h.registered.size).toBe(1)
    h.router.navigate('/hilos/analytics/browser-sessions')
    await nextTick()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(
      h.view.find('[data-id="hilos-analytics-actions-table"]').exists(),
    ).toBe(false)
  })

  it.each(['', '0', '-1', 'abc', '1.5', '1e2', 'Infinity'])(
    'creates no controller for invalid route parameter %j',
    async (param) => {
      const h = harness(param)
      expect(h.registered.size).toBe(0)
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(
        h.view.find('[data-id="hilos-analytics-actions-table"]').exists(),
      ).toBe(false)
      h.navigate('57')
      await nextTick()
      expect(h.registered.size).toBe(1)
      expect(
        h.view.find('[data-id="hilos-analytics-actions-table"]').exists(),
      ).toBe(true)
    },
  )
})
