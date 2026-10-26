// The React peer of vue/src/admin/analytics/HilosAnalyticsPage.test.ts:
// the overview's aggregates, the shared head, snapshot holding, StrictMode replay,
// and controller disposal on unmount and scope change.
import { StrictMode } from 'react'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  ANALYTICS_OVERVIEW_SECTION,
  hilosAnalyticsOverviewSnapshotSchema,
  type HilosAnalyticsContext,
  type HilosAnalyticsOverviewSnapshot,
} from '@hilos/core/admin/analytics'

import { HilosAnalyticsPage } from '../src/admin/analytics/HilosAnalyticsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

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

function harness(
  initial?: HilosAnalyticsOverviewSnapshot,
  strict = false,
  customScopes?: ScopeManager,
) {
  const scopes = customScopes ?? new ScopeManager()
  const page = scopes.openPage(PAGE)
  if (initial !== undefined) page.data.set(ANALYTICS_OVERVIEW_SECTION, initial)
  const currentRoute = createSignal<PageRouteMatch>({
    page: PAGE,
    params: {},
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
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
  const connection = {
    projectTimeZone: 'UTC',
    on: () => () => {},
  } as unknown as HilosConnection

  function App({ ctx }: { ctx: HilosAnalyticsContext }) {
    return (
      <HilosRouterContext.Provider value={router}>
        <HilosAnalyticsPage context={ctx} />
      </HilosRouterContext.Provider>
    )
  }

  const initialContext: HilosAnalyticsContext = { connection, scopes }
  const view = render(
    strict ? (
      <StrictMode>
        <App ctx={initialContext} />
      </StrictMode>
    ) : (
      <App ctx={initialContext} />
    ),
  )

  function answer(snapshot: unknown = SNAPSHOT) {
    act(() => {
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
    })
  }

  return {
    view,
    scopes,
    router,
    connection,
    answer,
    setContext(ctx: HilosAnalyticsContext) {
      act(() => {
        view.rerender(
          strict ? (
            <StrictMode>
              <App ctx={ctx} />
            </StrictMode>
          ) : (
            <App ctx={ctx} />
          ),
        )
      })
    },
  }
}

describe('HilosAnalyticsPage', () => {
  it('waits on three decorative tiles without false zeros, headings or tabs', () => {
    const h = harness()
    const skeleton = h.view.container.querySelector(
      '[data-id="hilos-analytics-overview-skeleton"]',
    )
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(
      skeleton?.querySelectorAll('.border.rounded-3.p-3.h-100'),
    ).toHaveLength(3)
    expect(skeleton?.textContent).toBe('')
    expect(
      h.view.container
        .querySelector('[data-id="hilos-page-head-skeleton"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(
      h.view.container.querySelectorAll('h1, nav, dl, button, a'),
    ).toHaveLength(0)
    expect(h.view.container.textContent).toBe('')

    act(() => {
      h.scopes.page()!.data.set(ANALYTICS_OVERVIEW_SECTION, {
        ...SNAPSHOT,
        visitorsThisHour: undefined,
      })
    })
    expect(skeleton?.textContent).toBe('')
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      ),
    ).toBeNull()
    expect(
      h.view.container.querySelectorAll('[data-id$="-count"]'),
    ).toHaveLength(0)
  })

  it.each([4, 0])(
    'draws exact aggregates including hour=%i beneath one shared catalog head',
    (visitorsThisHour) => {
      const h = harness()
      h.answer({ ...SNAPSHOT, visitorsThisHour })

      expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
      const h1 = h.view.container.querySelector('h1')
      expect(h1?.getAttribute('data-id')).toBe('hilos-page-head-title')
      expect(h1?.textContent).toBe('Analytics')
      expect(
        h.view.container.querySelectorAll('[data-id="hilos-page-head"]'),
      ).toHaveLength(1)
      expect(h.view.container.querySelectorAll('.bi-bar-chart')).toHaveLength(1)
      expect(
        h.view.container
          .querySelector('.bi-bar-chart')
          ?.closest('[aria-hidden="true"]'),
      ).not.toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-page-head-block"] p')
          ?.textContent,
      ).toBe(LEAD)
      const breadcrumb = h.view.container.querySelector(
        'nav[aria-label="Breadcrumb"]',
      )
      expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe(
        '/hilos',
      )
      expect(
        breadcrumb?.querySelector('[aria-current="page"]')?.textContent,
      ).toContain('Analytics')
      expect(breadcrumb?.querySelector('[aria-current="page"] a')).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-page-tabs"]'),
      ).toBeNull()

      const tiles = h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      )
      expect(tiles?.classList.contains('row')).toBe(true)
      expect(tiles?.classList.contains('row-cols-1')).toBe(true)
      expect(tiles?.classList.contains('row-cols-sm-3')).toBe(true)
      expect(tiles?.classList.contains('g-3')).toBe(true)
      expect(tiles?.classList.contains('mb-4')).toBe(true)
      expect(tiles?.querySelectorAll('dl')).toHaveLength(3)

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
        const tile = tiles?.querySelector(
          `dl[data-id="hilos-analytics-visitors-${period}"]`,
        )
        expect(tile?.classList.contains('border')).toBe(true)
        expect(tile?.classList.contains('rounded-3')).toBe(true)
        expect(tile?.classList.contains('p-3')).toBe(true)
        expect(tile?.classList.contains('h-100')).toBe(true)
        expect(tile?.classList.contains('mb-0')).toBe(true)
        expect(tile?.querySelector('dt')?.textContent).toBe(label)
        expect(tile?.querySelectorAll('dd')).toHaveLength(2)
        expect(
          tile?.querySelector(
            `dd[data-id="hilos-analytics-visitors-${period}-count"]`,
          )?.textContent,
        ).toBe(String(count))
        expect(
          tile?.querySelector(
            `dd[data-id="hilos-analytics-visitors-${period}-since"]`,
          )?.textContent,
        ).toBe(since)
      }
      expect(
        tiles?.querySelectorAll('a, button, [aria-hidden="true"]'),
      ).toHaveLength(0)
      expect(
        h.view.container.querySelector('[data-id="hilos-admin-children"]'),
      ).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-admin-empty"]'),
      ).toBeNull()
      expect(
        h.view.container.querySelector(
          '[data-id="hilos-analytics-overview-empty"]',
        ),
      ).toBeNull()
      expect(
        h.view.container.querySelector(
          '[data-id="hilos-analytics-overview-open-users"]',
        ),
      ).toBeNull()
      expect(
        h.view.container.querySelector(
          '[data-id="hilos-analytics-overview-skeleton"]',
        ),
      ).toBeNull()
      expect(h.view.container.textContent).not.toContain('Nothing here yet')
      expect(h.view.container.textContent).not.toContain('Open users')
    },
  )

  it('preserves all zero counts when week, day and hour are zero', () => {
    const h = harness()
    h.answer({
      ...SNAPSHOT,
      visitorsThisWeek: 0,
      visitorsToday: 0,
      visitorsThisHour: 0,
    })
    for (const period of ['week', 'day', 'hour'] as const) {
      expect(
        h.view.container.querySelector(
          `dd[data-id="hilos-analytics-visitors-${period}-count"]`,
        )?.textContent,
      ).toBe('0')
    }
  })

  it('starts during setup and reads an answer that arrived before mounting', () => {
    const h = harness(SNAPSHOT)
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-visitors-week-count"]',
      )?.textContent,
    ).toBe('38')
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-visitors-hour-since"]',
      )?.textContent,
    ).toBe('browser sessions since 22:00')
  })

  it('keeps the captured counts and periods after an actual global zone change and another reply', () => {
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
      const before = h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      )?.innerHTML
      act(() => {
        setZone('America/New_York')
      })
      expect(hilosTimeZone.get()).toBe('America/New_York')
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2027-01-01T00:00:00Z'))
      h.answer({
        ...SNAPSHOT,
        timeZone: 'UTC',
        visitorsThisWeek: 100,
        visitorsToday: 20,
        visitorsThisHour: 10,
      })
      expect(
        h.view.container.querySelector(
          '[data-id="hilos-analytics-overview-tiles"]',
        )?.innerHTML,
      ).toBe(before)
    } finally {
      act(() => {
        setZone(previousZone)
      })
      release()
      vi.restoreAllMocks()
    }
  })

  it('removes the old snapshot on a new scope or access refusal while still mounted', () => {
    const h = harness()
    h.answer()
    const old = h.scopes.page()!
    act(() => {
      h.scopes.openPage(PAGE)
      old.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    })
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      ),
    ).toBeNull()
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-skeleton"]',
      ),
    ).not.toBeNull()

    h.answer({ ...SNAPSHOT, visitorsThisWeek: 39 })
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-visitors-week-count"]',
      )?.textContent,
    ).toBe('39')

    act(() => {
      h.scopes.dropPage()
    })
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      ),
    ).toBeNull()
    expect(h.view.container.querySelectorAll('h1, nav, dl')).toHaveLength(0)
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-skeleton"]',
      )?.textContent,
    ).toBe('')
  })

  it('releases the real controller and signal subscriptions on unmount', () => {
    const h = harness()
    const parse = vi.spyOn(hilosAnalyticsOverviewSnapshotSchema, 'safeParse')
    h.answer()
    expect(parse).toHaveBeenCalled()
    h.view.unmount()
    parse.mockClear()
    const readPage = vi.spyOn(h.scopes, 'page')
    act(() => {
      const page = h.scopes.openPage(PAGE)
      page.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
      h.scopes.dropPage()
    })
    expect(readPage).not.toHaveBeenCalled()
    expect(parse).not.toHaveBeenCalled()
  })

  it('replays setup under StrictMode and updates when context.scopes changes', () => {
    const h = harness(undefined, true)
    h.answer(SNAPSHOT)
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-visitors-week-count"]',
      )?.textContent,
    ).toBe('38')

    const newScopes = new ScopeManager()
    newScopes.openPage(PAGE)
    h.setContext({ connection: h.connection, scopes: newScopes })

    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      ),
    ).toBeNull()
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-skeleton"]',
      ),
    ).not.toBeNull()

    act(() => {
      h.scopes.page()!.data.set(ANALYTICS_OVERVIEW_SECTION, {
        ...SNAPSHOT,
        visitorsThisWeek: 99,
      })
    })
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      ),
    ).toBeNull()

    act(() => {
      const data = newScopes.page()!.data
      data.set('pageLabel', 'Analytics')
      data.set('pageLead', LEAD)
      data.set('pageHeadOwner', {
        page: PAGE,
        label: 'Analytics',
        lead: LEAD,
        icon: 'bi-bar-chart',
      })
      data.set(ANALYTICS_OVERVIEW_SECTION, {
        ...SNAPSHOT,
        visitorsThisWeek: 42,
      })
    })
    expect(
      h.view.container.querySelector(
        '[data-id="hilos-analytics-visitors-week-count"]',
      )?.textContent,
    ).toBe('42')

    h.view.unmount()
  })
})
