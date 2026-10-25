import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
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
  formatAnalyticsPeopleTime,
  type AnalyticsPeriodContext,
  type HilosAnalyticsPersonRow,
} from '@hilos/core/admin/analytics'
import { HilosAnalyticsPeoplePage as exportedPage } from '@hilos/vue/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosAnalyticsPeoplePage from './HilosAnalyticsPeoplePage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'

enableAutoUnmount(afterEach)
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  document.documentElement.removeAttribute('data-bs-theme')
})

const PAGE = HilosPages.ANALYTICS_PEOPLE
const TABLE = 'hilosAnalyticsPeople'
const LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
const PERSON = {
  name: 'Ada',
  sessionCount: 7,
  lastSeenTs: Date.UTC(2026, 9, 14, 20, 51, 7),
}
type Listener = (signal: { type?: string; data: unknown }) => void

/** The complete first window, also replayed by a connection to a late mount. */
function pageAnswer(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
  return {
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
          sort,
        },
      },
    },
  }
}

function harness(
  resolvePath: HilosRouter['resolvePath'] = resolveHilosPath,
  buffered = false,
) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath: createSignal('/hilos/analytics/people'),
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
    TableViewportController<HilosAnalyticsPersonRow>
  >()
  const sendTableViewport = vi.fn<
    (
      page: string,
      table: string,
      descriptor: TableViewportDescriptor,
    ) => boolean
  >(() => true)
  const sendTableFacets = vi.fn(() => true)
  const unregisterTableWindow = vi.fn((key: string) => registered.delete(key))
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
    registerTableWindow(
      key: string,
      source: TableViewportController<HilosAnalyticsPersonRow>,
    ) {
      registered.set(key, source)
    },
    unregisterTableWindow,
    sendTableViewport,
    sendTableFacets,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection
  const view = mount(HilosAnalyticsPeoplePage, {
    props: { context: { connection, scopes } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  function emit(event: string, data: unknown, type?: string) {
    for (const listener of listeners.get(event) ?? []) listener({ type, data })
  }

  return {
    view,
    scopes,
    registered,
    listeners,
    sendTableViewport,
    sendTableFacets,
    unregisterTableWindow,
    controller: registered.get(TABLE)!,
    identity() {
      const data = scopes.page()!.data
      data.set('pageLabel', 'People')
      data.set(
        'pageLead',
        'People who last signed into browser sessions seen in the selected period.',
      )
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
        { page: PAGE, label: 'People' },
      ])
      data.set('pageChildren', [])
    },
    coldWindow(sort = [{ field: 'lastSeenTs', direction: 'desc' }]) {
      emit('projectSignal', pageAnswer(sort), 'page_response')
    },
    window(
      people: Array<[number, Record<string, unknown>]>,
      totalCount = people.length,
      totalExact = true,
      rowsBefore = 0,
    ) {
      emit('tableWindow', {
        page: PAGE,
        tableKey: TABLE,
        requestId: sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        rows: people.map(([rowKey, person]) => ({
          rowKey,
          slots: { person },
        })),
        totalCount,
        totalExact,
        limit: 25,
        firstAnchor: people.length ? { rowKey: people[0]![0] } : null,
        lastAnchor: people.length ? { rowKey: people.at(-1)![0] } : null,
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
        requestId: sendTableViewport.mock.calls.at(-1)?.[2].requestId,
        errorCode: 'unavailable',
      })
    },
  }
}

type View = ReturnType<typeof harness>['view']

/** The wide projection; the narrow projection repeats the same cells. */
function table(view: View) {
  return view.get('[data-id="hilos-analytics-people-table"] table')
}

/**
 * Read a column from the wide projection only.
 *
 * @param view The mounted People page.
 * @param suffix The cell's data-id suffix.
 */
function cells(view: View, suffix: string) {
  return table(view)
    .findAll(`[data-id="hilos-analytics-person-${suffix}"]`)
    .map((node) => node.text())
}

describe('HilosAnalyticsPeoplePage', () => {
  it.each(['light', 'dark'])(
    'exports the context page and draws links, cells and the shared head in %s',
    async (theme) => {
      document.documentElement.setAttribute('data-bs-theme', theme)
      expect(exportedPage).toBe(HilosAnalyticsPeoplePage)
      const h = harness()
      h.identity()
      h.coldWindow()
      h.window([
        [7, PERSON],
        [610, { ...PERSON, name: null, sessionCount: 0 }],
      ])
      await nextTick()

      expect(h.view.findAll('h1')).toHaveLength(1)
      const heading = h.view.get('h1')
      expect(heading.text()).toBe('Analytics')
      expect(h.view.findAll('h2')).toHaveLength(0)
      expect(h.view.text()).toContain(LEAD)
      const crumbs = h.view.get('nav[aria-label="Breadcrumb"]')
      expect(crumbs.get('a').attributes('href')).toBe('/hilos')
      expect(crumbs.get('[aria-current="page"]').text()).toBe('Analytics')
      const tabs = h.view.get('nav[aria-label="Analytics pages"]')
      expect(tabs.findAll('a').map((link) => link.text())).toEqual([
        'Overview',
        'People',
      ])
      expect(tabs.get('[aria-current="page"]').text()).toBe('People')
      expect(tabs.findAll('[aria-current="page"]')).toHaveLength(1)
      expect(tabs.find('[role="tablist"]').exists()).toBe(false)

      // The real native table and mobile list both resolve their name to the
      // one visible heading in the mounted document, without a copied label.
      const list = h.view.get('[data-id="hilos-table-cards"] [role="list"]')
      for (const projection of [table(h.view), list]) {
        const id = projection.attributes('aria-labelledby')
        expect(id).toBe(heading.attributes('id'))
        expect(document.getElementById(id!)).toBe(heading.element)
        expect(document.getElementById(id!)?.textContent).toBe('Analytics')
        expect(projection.attributes('aria-label')).toBeUndefined()
      }
      expect(
        table(h.view)
          .findAll('thead th')
          .slice(0, 3)
          .map((column) => column.text()),
      ).toEqual(['Person', 'Sessions', 'Last seen'])
      expect(table(h.view).get('[aria-sort="descending"]').text()).toBe(
        'Last seen',
      )
      expect(cells(h.view, 'name')).toEqual(['Ada (#7)', 'Deleted user #610'])
      expect(cells(h.view, 'sessions')).toEqual(['7', '0'])
      const context =
        h.controller.filter.get() as unknown as AnalyticsPeriodContext
      expect(cells(h.view, 'last-seen')).toEqual([
        formatAnalyticsPeopleTime(PERSON.lastSeenTs, context),
        formatAnalyticsPeopleTime(PERSON.lastSeenTs, context),
      ])
      for (const projection of [table(h.view), list]) {
        const links = projection.findAll(
          'a[data-id="hilos-analytics-person-link"]',
        )
        expect(links.map((link) => link.text())).toEqual([
          'Ada (#7)',
          'Deleted user #610',
        ])
        expect(links.map((link) => link.attributes('href'))).toEqual([
          '/hilos/analytics/people/7',
          '/hilos/analytics/people/610',
        ])
        for (const suffix of ['link', 'name', 'sessions', 'last-seen']) {
          expect(
            projection
              .findAll(`[data-id="hilos-analytics-person-${suffix}"]`)
              .map((node) => node.attributes('data-record')),
          ).toEqual(['7', '610'])
        }
      }
      expect(h.view.find('[data-id="hilos-table-search"]').exists()).toBe(false)
      expect(h.view.find('[data-id="hilos-table-main-action"]').exists()).toBe(
        false,
      )
      expect(h.view.find('style').exists()).toBe(false)
    },
  )

  it('keeps a viewer number linked beside Hidden and never formats hidden values', async () => {
    const h = harness()
    h.identity()
    h.coldWindow([])
    h.window([
      [
        7,
        {
          name: { _hidden: true },
          sessionCount: { _hidden: true },
          lastSeenTs: { _hidden: true },
        },
      ],
    ])
    await nextTick()
    for (const projection of [table(h.view), h.view.get('[role="list"]')]) {
      const link = projection.get('a[data-id="hilos-analytics-person-link"]')
      expect(link.text()).toBe('#7')
      expect(link.attributes('href')).toBe('/hilos/analytics/people/7')
      expect(
        projection.get('[data-id="hilos-analytics-person-name"]').text(),
      ).toContain('Hidden')
      for (const suffix of ['sessions', 'last-seen']) {
        expect(
          projection.get(`[data-id="hilos-analytics-person-${suffix}"]`).text(),
        ).toBe('Hidden')
      }
    }
    expect(h.view.html()).not.toContain('_hidden')
    expect(h.view.html()).not.toContain('1970')
    expect(h.view.text()).not.toContain('Ada')
    expect(table(h.view).get('[aria-sort]').attributes('aria-sort')).toBe(
      'none',
    )
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].sort).toBeNull()
  })

  it('keeps the person text when the project has no address for their page', async () => {
    const h = harness(() => undefined)
    h.identity()
    h.coldWindow()
    h.window([[7, PERSON]])
    await nextTick()
    expect(
      h.view.find('a[data-id="hilos-analytics-person-link"]').exists(),
    ).toBe(false)
    expect(
      table(h.view).get('[data-id="hilos-analytics-person-link"]').text(),
    ).toBe('Ada (#7)')
  })

  it('starts Today with one viewport and uses standard facets, choices and reset', async () => {
    const h = harness()
    h.identity()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.coldWindow()
    h.window([[7, PERSON]])
    h.facets()
    await nextTick()
    const { period, ...context } = h.controller.filter.get()
    expect(period).toBe('today')
    expect(context).toEqual({
      timeZone: expect.any(String),
      weekStartTs: expect.any(Number),
      dayStartTs: expect.any(Number),
      hourStartTs: expect.any(Number),
    })
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { ...context, period: 'today' },
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
        limit: 25,
      }),
    )
    expect(h.sendTableFacets).toHaveBeenCalledWith(PAGE, TABLE, {
      period: ['this-hour', 'today', 'this-week'],
    })
    const control = h.view.get('[data-id="hilos-table-filter-period"]')
    expect(control.get('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Period: Today',
    )
    expect(h.view.get('[data-id="hilos-table-filter-badge"]').text()).toContain(
      '1 filter',
    )
    await control.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    expect(
      control
        .findAll('[role="option"] > span:first-child')
        .map((node) => node.text()),
    ).toEqual(['Any time', 'This hour', 'Today', 'This week'])
    for (const [value, count] of [
      ['any', '500+'],
      ['this-hour', '0'],
      ['today', '500'],
      ['this-week', '500+'],
    ]) {
      expect(
        control.get(`[data-id="hilos-table-facet-period-${value}"]`).text(),
      ).toBe(count)
    }
    expect(control.findAll('[aria-selected="true"]')).toHaveLength(1)
    expect(control.get('[aria-selected="true"]').text()).toContain('Today')
    await control.get('[data-id="hilos-dropdown-option-2"]').trigger('click')
    expect(h.controller.filter.get()).toEqual({
      ...context,
      period: 'this-week',
    })
    expect(control.get('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Period: This week',
    )
    await control.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    await control.get('[data-id="hilos-dropdown-option--1"]').trigger('click')
    expect(h.controller.filter.get()).toEqual(context)
    expect(control.get('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Period: Any time',
    )
    expect(h.view.find('[data-id="hilos-table-filter-badge"]').exists()).toBe(
      false,
    )
    await control.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    await control.get('[data-id="hilos-dropdown-option-0"]').trigger('click')
    await h.view.get('[data-id="hilos-table-filter-reset"]').trigger('click')
    expect(h.controller.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    expect(control.get('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Period: Any time',
    )
  })

  it('keeps the captured zone and filter after the reader changes zones', async () => {
    const zone = readerTimeZone()
    try {
      zone.choose('Europe/Warsaw')
      const h = harness()
      h.identity()
      h.coldWindow()
      h.window([[7, PERSON]])
      await nextTick()
      const filter = h.controller.filter.get()
      const before = cells(h.view, 'last-seen')
      expect(filter['timeZone']).toBe('Europe/Warsaw')
      expect(before).toEqual([
        formatAnalyticsPeopleTime(
          PERSON.lastSeenTs,
          filter as unknown as AnalyticsPeriodContext,
        ),
      ])
      zone.choose('America/New_York')
      await nextTick()
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(cells(h.view, 'last-seen')).toEqual(before)
      expect(h.controller.filter.get()).toEqual(filter)
      expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    } finally {
      zone.release()
    }
  })

  it('uses the standard skeleton, filtered reset, page empty and refusal under the head', async () => {
    vi.useFakeTimers()
    const h = harness()
    expect(
      h.view
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    expect(h.view.find('h1').exists()).toBe(false)
    h.identity()
    h.coldWindow()
    await vi.advanceTimersByTimeAsync(400)
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-table-loading"]').attributes('aria-busy'),
    ).toBe('true')
    expect(
      h.view.findAll('[data-id="hilos-table-skeleton-row"]').length,
    ).toBeGreaterThan(0)
    expect(h.view.text()).not.toContain('No people in the selected period')
    h.window([])
    await nextTick()
    const noMatches = h.view.get('[data-id="hilos-table-no-matches"]')
    expect(noMatches.text()).toContain('Nothing found')
    expect(
      noMatches.get('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toContain('Period: Today')
    expect(
      noMatches.get('[data-id="hilos-table-no-matches-reset"]').text(),
    ).toBe('Reset filters')
    expect(h.view.find('[data-id="hilos-table-empty"]').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-table-count"]').exists()).toBe(false)
    const { period, ...context } = h.controller.filter.get()
    expect(period).toBe('today')
    expect(context).toEqual({
      timeZone: expect.any(String),
      weekStartTs: expect.any(Number),
      dayStartTs: expect.any(Number),
      hourStartTs: expect.any(Number),
    })
    await noMatches
      .get('[data-id="hilos-table-no-matches-reset"]')
      .trigger('click')
    expect(h.controller.filter.get()).toEqual(context)
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2].filter).toEqual(context)
    expect(
      h.sendTableViewport.mock.calls.at(-1)?.[2].filter,
    ).not.toHaveProperty('period')
    h.window([])
    await nextTick()
    expect(h.view.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
    expect(h.view.get('[data-id="hilos-table-empty"]').text()).toContain(
      'No people in the selected period',
    )
    expect(h.view.text()).not.toContain('Nothing found')
    h.controller.setFilter('period', 'today')
    h.refuseWindow()
    await nextTick()
    expect(h.view.text()).toContain('List unavailable')
    expect(h.view.text()).not.toContain('No people in the selected period')
    expect(h.view.find('[data-id="hilos-table-empty"]').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
    expect(h.view.get('h1').text()).toBe('Analytics')
    expect(
      h.view
        .get('nav[aria-label="Analytics pages"] [aria-current="page"]')
        .text(),
    ).toBe('People')
  })

  it('sends one Today viewport when the first page answer is replayed during mounting', async () => {
    const h = harness(resolveHilosPath, true)
    h.identity()
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.sendTableViewport).toHaveBeenCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: expect.objectContaining({ period: 'today' }),
      }),
    )
    h.window([[7, PERSON]])
    await nextTick()
    expect(cells(h.view, 'name')).toEqual(['Ada (#7)'])
    expect(h.sendTableViewport).toHaveBeenCalledTimes(1)
  })

  it('uses the pager, standard mobile filters and order on the same controller', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window(
      Array.from({ length: 25 }, (_, index) => [index + 1, PERSON]),
      128,
    )
    await nextTick()
    expect(h.view.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 25 of 128',
    )
    expect(
      h.view.get('[data-id="hilos-table-page-1"]').attributes('aria-current'),
    ).toBe('page')
    await h.view.get('[data-id="hilos-table-page-2"]').trigger('click')
    expect(h.sendTableViewport).toHaveBeenCalledTimes(2)
    h.window([[26, PERSON]], 128, true, 25)
    await nextTick()
    expect(h.view.get('[data-id="hilos-table-count"]').text()).toBe(
      '26 – 26 of 128',
    )
    expect(
      h.view.get('[data-id="hilos-table-page-2"]').attributes('aria-current'),
    ).toBe('page')
    expect(h.view.get('[data-id="hilos-table-cards"]').classes()).toContain(
      'd-md-none',
    )
    expect(h.view.get('[role="listitem"] a').text()).toBe('Ada (#26)')
    const open = h.view.get('[data-id="hilos-table-filters-open"]')
    expect(open.text()).toBe('Filters')
    await open.trigger('click')
    const modal = document.querySelector(
      '[data-id="hilos-table-filter-period-modal"]',
    )!
    expect(modal).not.toBeNull()
    modal
      .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
      .click()
    await nextTick()
    modal
      .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-option-0"]')!
      .click()
    await nextTick()
    expect(h.controller.filter.get()['period']).toBe('this-hour')
    expect(
      h.view.get('[data-id="hilos-table-filter-period"] button').text(),
    ).toBe('Period: This hour')
    document
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-filters-done"]')!
      .click()
    await nextTick()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(h.view.get('[data-id="hilos-table-order"]').text()).toContain(
      'Last seen',
    )
    await h.view.get('[data-id="hilos-table-sort-lastSeenTs"]').trigger('click')
    expect(h.sendTableViewport.mock.calls.at(-1)?.[2]).toMatchObject({
      filter: expect.objectContaining({ period: 'this-hour' }),
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    h.window([[7, PERSON]], 500, false)
    await nextTick()
    expect(h.view.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 1 of 500+',
    )
    expect(h.view.find('[data-id="hilos-table-page-1"]').exists()).toBe(false)
    expect(h.view.get('[data-id="hilos-table-next"]').text()).toBe('Next')
  })

  it('releases the real binding and controller on scope change and unmount', async () => {
    const h = harness()
    h.identity()
    h.coldWindow()
    h.window([[7, PERSON]])
    await nextTick()
    expect(h.registered.size).toBe(1)
    h.scopes.openPage(HilosPages.ANALYTICS)
    await nextTick()
    expect(h.registered.size).toBe(0)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    h.view.unmount()
    expect(h.unregisterTableWindow).toHaveBeenCalledTimes(1)
    expect(h.controller.rows.get()).toEqual([])
  })
})
