// The Angular peer of react/test/HilosAnalyticsPage.test.tsx: aggregates from
// the real controller, the shared head, and the lifetime of the input's scopes.
import { TestBed } from '@angular/core/testing'
import {
  bindAdminAccess,
  bindTimeZoneState,
  computedSignal,
  createSignal,
  HilosPages,
  hilosAdminAccess,
  hilosBrowserTimeZone,
  hilosOwnTimeZone,
  hilosTimeZone,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  ANALYTICS_OVERVIEW_SECTION,
  hilosAnalyticsOverviewSnapshotSchema,
  type HilosAnalyticsContext,
  type HilosAnalyticsOverviewSnapshot,
} from '@hilos/core/admin/analytics'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosAnalyticsPage } from '../src/admin/analytics/HilosAnalyticsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
const OVERVIEW_TAB = { page: PAGE, label: 'Overview' }

function answer(
  scopes: ScopeManager,
  snapshot: unknown = SNAPSHOT,
  tabs: HilosPageIdentity['pageTabs'] = [OVERVIEW_TAB],
) {
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
  data.set('pageTabs', tabs)
  data.set('pageChildren', [])
  data.set(ANALYTICS_OVERVIEW_SECTION, snapshot)
}

function harness(initial?: HilosAnalyticsOverviewSnapshot) {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  if (initial !== undefined) answer(scopes, initial)
  const identity = createSignal(pageIdentity(scopes))
  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: PAGE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/analytics'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: computedSignal(() => identity.get().get()),
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
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosAnalyticsPage)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()

  return {
    fixture,
    root: fixture.nativeElement as HTMLElement,
    scopes,
    connection,
    answer(snapshot: unknown = SNAPSHOT, tabs?: HilosPageIdentity['pageTabs']) {
      answer(scopes, snapshot, tabs)
      fixture.detectChanges()
    },
    setContext(context: HilosAnalyticsContext) {
      identity.set(pageIdentity(context.scopes))
      fixture.componentRef.setInput('context', context)
      fixture.detectChanges()
    },
  }
}

function count(root: HTMLElement, period: string): string | undefined {
  return root
    .querySelector(`[data-id="hilos-analytics-visitors-${period}-count"]`)
    ?.textContent?.trim()
}

function tileText(root: HTMLElement): string | undefined {
  return root
    .querySelector('[data-id="hilos-analytics-overview-tiles"]')
    ?.textContent?.trim()
}

describe('HilosAnalyticsPage', () => {
  it('waits on three decorative tiles without false zeros, headings or tabs', () => {
    const h = harness()
    const skeleton = h.root.querySelector(
      '[data-id="hilos-analytics-overview-skeleton"]',
    )
    expect(skeleton?.getAttribute('aria-hidden')).toBe('true')
    expect(skeleton?.children).toHaveLength(3)
    expect(skeleton?.textContent?.trim()).toBe('')
    expect(
      h.root
        .querySelector('[data-id="hilos-page-head-skeleton"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.root.querySelectorAll('h1, nav, dl, button, a')).toHaveLength(0)
    expect(h.root.textContent?.trim()).toBe('')

    h.scopes.page()!.data.set(ANALYTICS_OVERVIEW_SECTION, {
      ...SNAPSHOT,
      visitorsThisHour: undefined,
    })
    h.fixture.detectChanges()
    expect(
      h.root.querySelector('[data-id="hilos-analytics-overview-skeleton"]'),
    ).toBe(skeleton)
    expect(tileText(h.root)).toBeUndefined()
    expect(h.root.querySelectorAll('[data-id$="-count"]')).toHaveLength(0)
  })

  it.each([4, 0])(
    'draws exact aggregates including hour=%i beneath one shared catalog head',
    (visitorsThisHour) => {
      const h = harness()
      h.answer({ ...SNAPSHOT, visitorsThisHour })

      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
      const heading = h.root.querySelector('h1')
      expect(heading?.getAttribute('data-id')).toBe('hilos-page-head-title')
      expect(heading?.textContent?.trim()).toBe('Analytics')
      expect(
        h.root.querySelectorAll('[data-id="hilos-page-head"]'),
      ).toHaveLength(1)
      const icon = h.root.querySelector('[data-id="hilos-page-head-block"] i')
      expect(icon).not.toBeNull()
      expect(icon?.closest('[aria-hidden="true"]')).not.toBeNull()
      expect(
        h.root
          .querySelector('[data-id="hilos-page-head-block"] p')
          ?.textContent?.trim(),
      ).toBe(LEAD)
      const breadcrumb = h.root.querySelector('nav[aria-label="Breadcrumb"]')
      expect(
        Array.from(breadcrumb?.querySelectorAll('li') ?? [], (item) => {
          const copy = item.cloneNode(true) as HTMLElement
          copy
            .querySelectorAll('[aria-hidden="true"]')
            .forEach((node) => node.remove())
          return copy.textContent?.trim()
        }),
      ).toEqual(['Hilos', 'Analytics'])
      expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe(
        '/hilos',
      )
      expect(
        breadcrumb?.querySelector('[aria-current="page"]')?.textContent?.trim(),
      ).toContain('Analytics')
      expect(breadcrumb?.querySelector('[aria-current="page"] a')).toBeNull()
      expect(h.root.querySelector('[data-id="hilos-page-tabs"]')).toBeNull()

      const tiles = h.root.querySelector(
        '[data-id="hilos-analytics-overview-tiles"]',
      )
      expect(tiles?.querySelectorAll('dl')).toHaveLength(3)
      for (const [period, label, expectedCount, since] of [
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
        expect(tile?.querySelector('dt')?.textContent?.trim()).toBe(label)
        expect(tile?.querySelectorAll('dd')).toHaveLength(2)
        expect(count(h.root, period)).toBe(String(expectedCount))
        expect(
          tile
            ?.querySelector(
              `dd[data-id="hilos-analytics-visitors-${period}-since"]`,
            )
            ?.textContent?.trim(),
        ).toBe(since)
      }
      // Counts remain plain readable text until the Angular list/link leaf.
      expect(
        tiles?.querySelectorAll('a, button, [tabindex], [aria-hidden="true"]'),
      ).toHaveLength(0)
      expect(h.root.querySelectorAll('h2, h3, table')).toHaveLength(0)
      for (const id of [
        'hilos-admin-children',
        'hilos-admin-empty',
        'hilos-analytics-overview-empty',
        'hilos-analytics-overview-open-users',
        'hilos-analytics-overview-skeleton',
      ]) {
        expect(h.root.querySelector(`[data-id="${id}"]`)).toBeNull()
      }
      expect(h.root.textContent).not.toContain('Nothing here yet')
      expect(h.root.textContent).not.toContain('Open users')
      expect(h.root.textContent).not.toContain('Hidden')
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
    for (const period of ['week', 'day', 'hour']) {
      expect(count(h.root, period)).toBe('0')
    }
  })

  it('shows the same non-personal aggregates to a view-mode viewer', () => {
    const h = harness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      expect(hilosAdminAccess.get()).toBe('view')
      h.answer()
      expect(count(h.root, 'week')).toBe('38')
      expect(count(h.root, 'day')).toBe('7')
      expect(count(h.root, 'hour')).toBe('4')
      expect(h.root.textContent).not.toContain('Hidden')
    } finally {
      release()
    }
  })

  it('reads a complete page answer that arrived before mounting', () => {
    const h = harness(SNAPSHOT)
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe('Analytics')
    expect(count(h.root, 'week')).toBe('38')
    expect(count(h.root, 'hour')).toBe('4')
    expect(
      h.root
        .querySelector('[data-id="hilos-analytics-visitors-hour-since"]')
        ?.textContent?.trim(),
    ).toBe('browser sessions since 22:00')
  })

  it('delegates two available catalog tabs to the shared head', () => {
    const h = harness()
    // Supply two catalog tabs with existing addresses without building the
    // future Angular list view or changing the production catalog.
    h.answer(SNAPSHOT, [
      OVERVIEW_TAB,
      {
        page: HilosPages.ANALYTICS_BROWSER_SESSIONS,
        label: 'Browser sessions',
      },
    ])
    const tabs = h.root.querySelector('nav[aria-label="Analytics pages"]')
    expect(tabs).not.toBeNull()
    expect(tabs?.querySelectorAll('a')).toHaveLength(2)
    expect(
      tabs?.querySelector('[aria-current="page"]')?.textContent?.trim(),
    ).toBe('Overview')
    expect(
      tabs
        ?.querySelector(
          '[data-id="hilos-page-tab-hilos_analytics_browser_sessions"]',
        )
        ?.getAttribute('href'),
    ).toBe('/hilos/analytics/browser-sessions')
    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    expect(h.root.querySelector('[data-id="hilos-admin-children"]')).toBeNull()
  })

  it('keeps the captured counts and periods after a real global zone change and another reply', () => {
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
    const previousOwnZone = hilosOwnTimeZone.get()
    const previousBrowserZone = hilosBrowserTimeZone.get()
    const release = bindTimeZoneState(connection)
    const setZone = (timeZone: string | null) =>
      listeners.get('projectSignal')!({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
    try {
      setZone('Europe/Warsaw')
      const h = harness()
      h.answer()
      const before = tileText(h.root)
      setZone('America/New_York')
      h.fixture.detectChanges()
      expect(hilosTimeZone.get()).toBe('America/New_York')
      expect(tileText(h.root)).toBe(before)
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2027-01-01T00:00:00Z'))
      h.answer({
        ...SNAPSHOT,
        timeZone: 'UTC',
        visitorsThisWeek: 100,
        visitorsToday: 20,
        visitorsThisHour: 10,
      })
      expect(tileText(h.root)).toBe(before)
    } finally {
      const options = new Intl.DateTimeFormat().resolvedOptions()
      const restoreBrowser = vi
        .spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions')
        .mockReturnValue({ ...options, timeZone: previousBrowserZone ?? '' })
      setZone(previousOwnZone)
      release()
      restoreBrowser.mockRestore()
      expect(hilosTimeZone.get()).toBe(previousZone)
      expect(hilosOwnTimeZone.get()).toBe(previousOwnZone)
      expect(hilosBrowserTimeZone.get()).toBe(previousBrowserZone)
    }
  })

  it('removes the old snapshot on scope replacement and closure and accepts a new opening', () => {
    const h = harness()
    h.answer()
    const old = h.scopes.page()!
    h.scopes.openPage(PAGE)
    old.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    h.fixture.detectChanges()
    expect(tileText(h.root)).toBeUndefined()
    expect(
      h.root.querySelector('[data-id="hilos-analytics-overview-skeleton"]'),
    ).not.toBeNull()
    expect(h.root.querySelectorAll('h1, nav, dl')).toHaveLength(0)

    h.answer({ ...SNAPSHOT, visitorsThisWeek: 39 })
    expect(count(h.root, 'week')).toBe('39')
    h.scopes.dropPage()
    h.fixture.detectChanges()
    expect(tileText(h.root)).toBeUndefined()
    expect(h.root.querySelectorAll('h1, nav, dl')).toHaveLength(0)

    h.scopes.openPage(PAGE)
    h.answer({ ...SNAPSHOT, visitorsThisWeek: 40 })
    expect(count(h.root, 'week')).toBe('40')
  })

  it('keeps the accepted snapshot when only context changes with the same scopes', () => {
    const h = harness()
    h.answer()
    h.answer({ ...SNAPSHOT, visitorsThisWeek: 99 })
    h.setContext({ connection: h.connection, scopes: h.scopes })
    expect(count(h.root, 'week')).toBe('38')
  })

  it('replaces the controller for new input scopes and stops reading the old scopes', () => {
    const h = harness()
    h.answer()
    const next = new ScopeManager()
    next.openPage(PAGE)
    h.setContext({ connection: h.connection, scopes: next })
    expect(tileText(h.root)).toBeUndefined()
    const readOld = vi.spyOn(h.scopes, 'page')
    const parse = vi.spyOn(hilosAnalyticsOverviewSnapshotSchema, 'safeParse')
    const old = h.scopes.openPage(PAGE)
    old.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    h.scopes.dropPage()
    h.fixture.detectChanges()
    expect(readOld).not.toHaveBeenCalled()
    expect(parse).not.toHaveBeenCalled()
    expect(tileText(h.root)).toBeUndefined()

    answer(next, { ...SNAPSHOT, visitorsThisWeek: 42 })
    h.fixture.detectChanges()
    expect(count(h.root, 'week')).toBe('42')
    expect(h.root.querySelector('h1')?.textContent?.trim()).toBe('Analytics')
  })

  it('releases the real controller and signal subscriptions on destroy', () => {
    const h = harness()
    const parse = vi.spyOn(hilosAnalyticsOverviewSnapshotSchema, 'safeParse')
    h.answer()
    expect(parse).toHaveBeenCalled()
    h.fixture.destroy()
    parse.mockClear()
    const readPage = vi.spyOn(h.scopes, 'page')
    const page = h.scopes.openPage(PAGE)
    page.data.set(ANALYTICS_OVERVIEW_SECTION, SNAPSHOT)
    h.scopes.dropPage()
    expect(readPage).not.toHaveBeenCalled()
    expect(parse).not.toHaveBeenCalled()
  })
})
