import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  bindTimeZoneState,
  createSignal,
  HilosPages,
  hilosTimeZone,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
} from '@hilos/core/base'
import {
  ANALYTICS_OVERVIEW_SECTION,
  hilosAnalyticsOverviewSnapshotSchema,
  type HilosAnalyticsOverviewSnapshot,
} from '@hilos/core/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosAnalyticsPage from './HilosAnalyticsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
afterEach(() => vi.restoreAllMocks())

const PAGE = HilosPages.ANALYTICS
const LEAD =
  'What a browser session did, in order, and which sessions a person signed into.'
const SNAPSHOT: HilosAnalyticsOverviewSnapshot = {
  timeZone: 'Europe/Warsaw',
  weekStartTs: Date.parse('2026-10-11T22:00:00Z'),
  dayStartTs: Date.parse('2026-10-13T22:00:00Z'),
  hourStartTs: Date.parse('2026-10-14T20:00:00Z'),
  asOfTs: Date.parse('2026-10-14T20:59:00Z'),
  visitorsThisWeek: 38,
  visitorsToday: 7,
  visitorsThisHour: 4,
}

function harness(initial?: HilosAnalyticsOverviewSnapshot) {
  const scopes = new ScopeManager()
  const page = scopes.openPage(PAGE)
  if (initial !== undefined) page.data.set(ANALYTICS_OVERVIEW_SECTION, initial)
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath: createSignal('/hilos/analytics'),
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
  const view = mount(HilosAnalyticsPage, {
    props: { context: { connection: {} as HilosConnection, scopes } },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })

  return {
    view,
    scopes,
    answer(snapshot: unknown = SNAPSHOT) {
      const data = scopes.page()!.data
      data.set('pageLabel', 'Analytics')
      data.set('pageLead', LEAD)
      data.set('pageHeadOwner', {
        page: PAGE,
        label: 'Analytics',
        lead: LEAD,
        icon: 'bi-bar-chart',
      })
      data.set('pageBreadcrumb', [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: PAGE, label: 'Analytics' },
      ])
      data.set('pageTabs', [{ page: PAGE, label: 'Overview' }])
      data.set('pageChildren', [])
      data.set(ANALYTICS_OVERVIEW_SECTION, snapshot)
    },
  }
}

describe('HilosAnalyticsPage', () => {
  it('waits on three decorative tiles without false zeros, headings or tabs', async () => {
    const h = harness()
    const skeleton = () =>
      h.view.get('[data-id="hilos-analytics-overview-skeleton"]')
    expect(skeleton().attributes('aria-hidden')).toBe('true')
    expect(skeleton().findAll('.border.rounded-3.p-3.h-100')).toHaveLength(3)
    expect(skeleton().text()).toBe('')
    expect(
      h.view
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    expect(h.view.findAll('h1, nav, dl, button, a')).toHaveLength(0)
    expect(h.view.text()).toBe('')

    h.scopes.page()!.data.set(ANALYTICS_OVERVIEW_SECTION, {
      ...SNAPSHOT,
      visitorsThisHour: undefined,
    })
    await nextTick()
    expect(skeleton().text()).toBe('')
    expect(
      h.view.find('[data-id="hilos-analytics-overview-tiles"]').exists(),
    ).toBe(false)
    expect(h.view.findAll('[data-id$="-count"]')).toHaveLength(0)
  })

  it.each([4, 0])(
    'draws exact aggregates including hour=%i beneath one shared catalog head',
    async (visitorsThisHour) => {
      const h = harness()
      h.answer({ ...SNAPSHOT, visitorsThisHour })
      await nextTick()

      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(h.view.get('h1').attributes('data-id')).toBe(
        'hilos-page-head-title',
      )
      expect(h.view.get('h1').text()).toBe('Analytics')
      expect(h.view.findAll('[data-id="hilos-page-head"]')).toHaveLength(1)
      expect(h.view.findAll('.bi-bar-chart')).toHaveLength(1)
      expect(
        h.view.get('.bi-bar-chart').element.closest('[aria-hidden="true"]'),
      ).not.toBeNull()
      expect(h.view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
        LEAD,
      )
      const breadcrumb = h.view.get('nav[aria-label="Breadcrumb"]')
      expect(breadcrumb.get('a').attributes('href')).toBe('/hilos')
      expect(breadcrumb.get('[aria-current="page"]').text()).toBe('Analytics')
      expect(breadcrumb.find('[aria-current="page"] a').exists()).toBe(false)
      expect(h.view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)

      const tiles = h.view.get('[data-id="hilos-analytics-overview-tiles"]')
      expect(tiles.classes()).toEqual(
        expect.arrayContaining(['row', 'row-cols-1', 'row-cols-sm-3', 'g-3']),
      )
      expect(tiles.findAll('dl')).toHaveLength(3)
      for (const [period, label, count, since] of [
        [
          'week',
          'Visitors this week',
          38,
          'browser sessions since Monday, 00:00',
        ],
        ['day', 'Visitors today', 7, 'browser sessions since 00:00'],
        [
          'hour',
          'Visitors this hour',
          visitorsThisHour,
          'browser sessions since 22:00',
        ],
      ] as const) {
        const tile = tiles.get(
          `dl[data-id="hilos-analytics-visitors-${period}"]`,
        )
        expect(tile.classes()).toEqual(
          expect.arrayContaining(['border', 'rounded-3', 'p-3', 'h-100']),
        )
        expect(tile.get('dt').text()).toBe(label)
        expect(tile.findAll('dd')).toHaveLength(2)
        expect(
          tile
            .get(`dd[data-id="hilos-analytics-visitors-${period}-count"]`)
            .text(),
        ).toBe(String(count))
        expect(
          tile
            .get(`dd[data-id="hilos-analytics-visitors-${period}-since"]`)
            .text(),
        ).toBe(since)
      }
      expect(tiles.findAll('a, button, [aria-hidden="true"]')).toHaveLength(0)
      expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
      expect(
        h.view.find('[data-id="hilos-analytics-overview-empty"]').exists(),
      ).toBe(false)
      expect(
        h.view.find('[data-id="hilos-analytics-overview-open-users"]').exists(),
      ).toBe(false)
      expect(
        h.view.find('[data-id="hilos-analytics-overview-skeleton"]').exists(),
      ).toBe(false)
      expect(h.view.text()).not.toContain('Nothing here yet')
      expect(h.view.text()).not.toContain('Open users')
    },
  )

  it('starts during setup and reads an answer that arrived before mounting', () => {
    const h = harness(SNAPSHOT)
    expect(
      h.view.get('[data-id="hilos-analytics-visitors-week-count"]').text(),
    ).toBe('38')
    expect(
      h.view.get('[data-id="hilos-analytics-visitors-hour-since"]').text(),
    ).toBe('browser sessions since 22:00')
  })

  it('keeps the captured counts and periods after an actual global zone change and another reply', async () => {
    type Listener = (signal: { type: string; data: unknown }) => void
    const listeners = new Map<string, Listener>()
    const connection = {
      projectTimeZone: 'UTC',
      on(event: string, listener: Listener) {
        listeners.set(event, listener)
        return () => listeners.delete(event)
      },
    } as unknown as HilosConnection
    const previousZone = hilosTimeZone.get()
    const release = bindTimeZoneState(connection)
    const setZone = (timeZone: string) =>
      listeners.get('projectSignal')!({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
    try {
      setZone('Europe/Warsaw')
      const h = harness()
      h.answer()
      await nextTick()
      const before = h.view
        .get('[data-id="hilos-analytics-overview-tiles"]')
        .html()
      setZone('America/New_York')
      expect(hilosTimeZone.get()).toBe('America/New_York')
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2027-01-01T00:00:00Z'))
      h.answer({
        ...SNAPSHOT,
        timeZone: 'UTC',
        visitorsThisWeek: 100,
        visitorsToday: 20,
        visitorsThisHour: 10,
      })
      await nextTick()
      expect(
        h.view.get('[data-id="hilos-analytics-overview-tiles"]').html(),
      ).toBe(before)
    } finally {
      setZone(previousZone)
      release()
    }
  })

  it('removes the old snapshot on a new scope or access refusal while still mounted', async () => {
    const h = harness()
    h.answer()
    await nextTick()
    const old = h.scopes.page()!
    h.scopes.openPage(PAGE)
    old.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-analytics-overview-tiles"]').exists(),
    ).toBe(false)
    expect(
      h.view.find('[data-id="hilos-analytics-overview-skeleton"]').exists(),
    ).toBe(true)
    h.answer({ ...SNAPSHOT, visitorsThisWeek: 39 })
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-analytics-visitors-week-count"]').text(),
    ).toBe('39')
    h.scopes.dropPage()
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-analytics-overview-tiles"]').exists(),
    ).toBe(false)
    expect(h.view.findAll('h1, nav, dl')).toHaveLength(0)
    expect(
      h.view.get('[data-id="hilos-analytics-overview-skeleton"]').text(),
    ).toBe('')
  })

  it('releases the real controller and signal subscriptions on unmount', async () => {
    const h = harness()
    const parse = vi.spyOn(hilosAnalyticsOverviewSnapshotSchema, 'safeParse')
    h.answer()
    await nextTick()
    expect(parse).toHaveBeenCalled()
    h.view.unmount()
    parse.mockClear()
    const readPage = vi.spyOn(h.scopes, 'page')
    const page = h.scopes.openPage(PAGE)
    page.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    h.scopes.dropPage()
    await nextTick()
    expect(readPage).not.toHaveBeenCalled()
    expect(parse).not.toHaveBeenCalled()
  })
})
