import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ANALYTICS_OVERVIEW_COPY,
  ANALYTICS_OVERVIEW_SECTION,
  analyticsOverviewTiles,
  createHilosAnalyticsOverview,
  hilosAnalyticsOverviewSnapshotSchema,
  type HilosAnalyticsOverviewSnapshot,
} from '../../../src/admin/analytics/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import { calendarPeriodStarts } from '../../../src/timeZone/calendarPeriods.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'

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

const MOMENTS = ['weekStartTs', 'dayStartTs', 'hourStartTs', 'asOfTs'] as const
const COUNTS = [
  'visitorsThisWeek',
  'visitorsToday',
  'visitorsThisHour',
] as const

afterEach(() => vi.restoreAllMocks())

describe('analytics overview snapshot validation', () => {
  it('keeps eight exact fields in an immutable object without a table ceiling', () => {
    expect(ANALYTICS_OVERVIEW_SECTION).toBe('analyticsOverview')
    const parsed = hilosAnalyticsOverviewSnapshotSchema.parse(SNAPSHOT)
    expect(parsed).toEqual(SNAPSHOT)
    expect(Object.isFrozen(parsed)).toBe(true)
    expect(
      hilosAnalyticsOverviewSnapshotSchema.parse({
        ...SNAPSHOT,
        visitorsThisWeek: Number.MAX_SAFE_INTEGER,
        visitorsToday: 10000000,
        visitorsThisHour: 1000000,
      }),
    ).toMatchObject({
      visitorsThisWeek: Number.MAX_SAFE_INTEGER,
      visitorsToday: 10000000,
      visitorsThisHour: 1000000,
    })
  })

  it.each([
    undefined,
    null,
    {},
    [],
    { _hidden: true },
    { ...SNAPSHOT, extra: 1 },
  ])('rejects an absent, hidden, partial or extended object: %j', (value) => {
    expect(hilosAnalyticsOverviewSnapshotSchema.safeParse(value).success).toBe(
      false,
    )
  })

  it.each([...MOMENTS, ...COUNTS])('requires a safe integer %s', (field) => {
    for (const value of [
      undefined,
      null,
      '0',
      -1,
      0.5,
      NaN,
      Infinity,
      -Infinity,
      Number.MAX_SAFE_INTEGER + 1,
    ]) {
      expect(
        hilosAnalyticsOverviewSnapshotSchema.safeParse({
          ...SNAPSHOT,
          [field]: value,
        }).success,
      ).toBe(false)
    }
    const partial: Record<string, unknown> = { ...SNAPSHOT }
    delete partial[field]
    expect(
      hilosAnalyticsOverviewSnapshotSchema.safeParse(partial).success,
    ).toBe(false)
  })

  it.each(MOMENTS)(
    'rejects a safe integer outside Date range in %s',
    (field) => {
      expect(
        hilosAnalyticsOverviewSnapshotSchema.safeParse({
          ...SNAPSHOT,
          [field]: 8640000000000001,
        }).success,
      ).toBe(false)
    },
  )

  it.each([
    { weekStartTs: SNAPSHOT.dayStartTs + 1 },
    { dayStartTs: SNAPSHOT.hourStartTs + 1 },
    { hourStartTs: SNAPSHOT.asOfTs + 1 },
    { visitorsToday: SNAPSHOT.visitorsThisWeek + 1 },
    { visitorsThisHour: SNAPSHOT.visitorsToday + 1 },
  ])('rejects reversed period bounds or counts: %j', (fields) => {
    expect(
      hilosAnalyticsOverviewSnapshotSchema.safeParse({ ...SNAPSHOT, ...fields })
        .success,
    ).toBe(false)
  })

  it('accepts zero counts and equal closed boundaries', () => {
    const zero = {
      timeZone: 'UTC',
      weekStartTs: 0,
      dayStartTs: 0,
      hourStartTs: 0,
      asOfTs: 0,
      visitorsThisWeek: 0,
      visitorsToday: 0,
      visitorsThisHour: 0,
    }
    expect(hilosAnalyticsOverviewSnapshotSchema.parse(zero)).toEqual(zero)
  })

  it.each([
    undefined,
    null,
    0,
    '',
    ' Europe/Warsaw',
    'UTC ',
    '+02:00',
    '-0500',
    'No/Zone',
  ])('rejects an unusable snapshot zone: %j', (timeZone) => {
    expect(
      hilosAnalyticsOverviewSnapshotSchema.safeParse({ ...SNAPSHOT, timeZone })
        .success,
    ).toBe(false)
  })

  it.each(['UTC', 'US/Eastern', 'Asia/Kathmandu'])(
    'keeps an engine-usable name or alias unchanged: %s',
    (timeZone) => {
      expect(
        hilosAnalyticsOverviewSnapshotSchema.parse({ ...SNAPSHOT, timeZone })
          .timeZone,
      ).toBe(timeZone)
    },
  )
})

describe('analytics overview page lifetime', () => {
  it('waits for start and the first complete valid answer, including a real zero', () => {
    const scopes = new ScopeManager()
    const overview = createHilosAnalyticsOverview(scopes)
    expect(overview.snapshot.get()).toBeUndefined()
    const page = scopes.openPage(HilosPages.ANALYTICS)
    page.data.set('analyticsOverview', {
      ...SNAPSHOT,
      visitorsThisHour: undefined,
    })
    overview.start()
    expect(overview.snapshot.get()).toBeUndefined()
    page.data.set('analyticsOverview', { ...SNAPSHOT, visitorsToday: -1 })
    expect(overview.snapshot.get()).toBeUndefined()
    page.data.set('analyticsOverview', { ...SNAPSHOT, visitorsThisHour: 0 })
    expect(overview.snapshot.get()).toEqual({
      ...SNAPSHOT,
      visitorsThisHour: 0,
    })
    overview.dispose()

    page.data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toBeUndefined()
    overview.start()
    expect(overview.snapshot.get()).toEqual(SNAPSHOT)
    overview.dispose()
  })

  it('starts before a scope exists and accepts its later complete page data', () => {
    const scopes = new ScopeManager()
    const overview = createHilosAnalyticsOverview(scopes)
    overview.start()
    expect(overview.snapshot.get()).toBeUndefined()
    scopes
      .openPage(HilosPages.ANALYTICS)
      .data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toEqual(SNAPSHOT)
    overview.dispose()
  })

  it('holds the first object across repeated delivery, reconnect data and clock or zone changes', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS)
    const overview = createHilosAnalyticsOverview(scopes)
    overview.start()
    page.data.set('analyticsOverview', SNAPSHOT)
    const accepted = overview.snapshot.get()!
    const tiles = analyticsOverviewTiles(accepted)
    const changed = vi.fn()
    const off = subscribeSignal(overview.snapshot, changed)

    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/New_York')
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2027-01-01T00:00:00Z'))
    page.data.set('analyticsOverview', {
      ...SNAPSHOT,
      timeZone: 'Asia/Kathmandu',
      asOfTs: Date.now(),
      visitorsThisWeek: 380,
      visitorsToday: 70,
      visitorsThisHour: 40,
    })
    page.data.set('analyticsOverview', { ...SNAPSHOT })
    page.data.set('analyticsOverview', undefined)
    expect(overview.snapshot.get()).toBe(accepted)
    expect(analyticsOverviewTiles(accepted)).toEqual(tiles)
    expect(zone).not.toHaveBeenCalled()
    expect(changed).not.toHaveBeenCalled()
    off()
    overview.dispose()
  })

  it('drops the old snapshot synchronously on a new scope even when the raw object is identical', () => {
    const scopes = new ScopeManager()
    const old = scopes.openPage(HilosPages.ANALYTICS)
    old.data.set('analyticsOverview', SNAPSHOT)
    const overview = createHilosAnalyticsOverview(scopes)
    overview.start()
    const first = overview.snapshot.get()
    const changes: (HilosAnalyticsOverviewSnapshot | undefined)[] = []
    const off = subscribeSignal(overview.snapshot, (value) =>
      changes.push(value),
    )
    const reopened = scopes.openPage(HilosPages.ANALYTICS)
    expect(overview.snapshot.get()).toBeUndefined()
    expect(changes).toEqual([undefined])
    old.data.set('analyticsOverview', { ...SNAPSHOT, visitorsThisWeek: 39 })
    expect(overview.snapshot.get()).toBeUndefined()
    reopened.data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toEqual(SNAPSHOT)
    expect(overview.snapshot.get()).not.toBe(first)
    off()
    overview.dispose()
  })

  it('hides counts on another page and a refused scope, before controller disposal', () => {
    const scopes = new ScopeManager()
    const overview = createHilosAnalyticsOverview(scopes)
    overview.start()
    scopes
      .openPage(HilosPages.ANALYTICS)
      .data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toEqual(SNAPSHOT)
    scopes
      .openPage(HilosPages.ANALYTICS_PERSON)
      .data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toBeUndefined()
    const reopened = scopes.openPage(HilosPages.ANALYTICS)
    reopened.data.set('analyticsOverview', {
      ...SNAPSHOT,
      visitorsThisWeek: 39,
    })
    expect(overview.snapshot.get()?.visitorsThisWeek).toBe(39)
    scopes.dropPage() // bindPageScope drops the refused page while the view still exists.
    expect(overview.snapshot.get()).toBeUndefined()
    reopened.data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toBeUndefined()
    scopes
      .openPage(HilosPages.ANALYTICS)
      .data.set('analyticsOverview', SNAPSHOT)
    expect(overview.snapshot.get()).toEqual(SNAPSHOT)
    overview.dispose()
  })

  it('makes start/dispose idempotent, releases listeners and resets on restart', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS)
    const overview = createHilosAnalyticsOverview(scopes)
    const parse = vi.spyOn(hilosAnalyticsOverviewSnapshotSchema, 'safeParse')
    overview.dispose()
    overview.start()
    overview.start()
    parse.mockClear()
    page.data.set('analyticsOverview', {
      ...SNAPSHOT,
      visitorsThisWeek: undefined,
    })
    expect(parse).toHaveBeenCalledTimes(1)
    page.data.set('analyticsOverview', SNAPSHOT)
    const first = overview.snapshot.get()
    overview.start()
    expect(overview.snapshot.get()).toBe(first)
    overview.dispose()
    overview.dispose()
    expect(overview.snapshot.get()).toBeUndefined()

    parse.mockClear()
    const readPage = vi.spyOn(scopes, 'page')
    page.data.set('analyticsOverview', { ...SNAPSHOT, visitorsThisWeek: 39 })
    scopes.dropPage()
    const next = scopes.openPage(HilosPages.ANALYTICS)
    next.data.set('analyticsOverview', { ...SNAPSHOT, visitorsThisWeek: 40 })
    expect(readPage).not.toHaveBeenCalled()
    expect(parse).not.toHaveBeenCalled()
    overview.start()
    expect(overview.snapshot.get()?.visitorsThisWeek).toBe(40)
    overview.dispose()
  })

  it('does not share held snapshots between controllers or managers', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.ANALYTICS)
    page.data.set('analyticsOverview', SNAPSHOT)
    const first = createHilosAnalyticsOverview(scopes)
    first.start()
    page.data.set('analyticsOverview', { ...SNAPSHOT, visitorsThisWeek: 39 })
    const second = createHilosAnalyticsOverview(scopes)
    second.start()
    expect(first.snapshot.get()?.visitorsThisWeek).toBe(38)
    expect(second.snapshot.get()?.visitorsThisWeek).toBe(39)
    first.dispose()
    expect(second.snapshot.get()?.visitorsThisWeek).toBe(39)
    const separate = createHilosAnalyticsOverview(new ScopeManager())
    separate.start()
    expect(separate.snapshot.get()).toBeUndefined()
    second.dispose()
    separate.dispose()
  })
})

describe('analytics overview tiles', () => {
  it('projects the three exact design labels and periods in the captured zone', () => {
    expect(analyticsOverviewTiles(SNAPSHOT)).toEqual([
      {
        key: 'week',
        label: 'Visitors this week',
        count: 38,
        since: 'browser sessions since Monday, 00:00',
      },
      {
        key: 'day',
        label: 'Visitors today',
        count: 7,
        since: 'browser sessions since 00:00',
      },
      {
        key: 'hour',
        label: 'Visitors this hour',
        count: 4,
        since: 'browser sessions since 22:00',
      },
    ])
    expect(ANALYTICS_OVERVIEW_COPY.hour).toBe('Visitors this hour')
  })

  it.each([
    ['Asia/Kathmandu', '2026-10-14T07:49:00Z', '13:00'],
    ['Australia/Lord_Howe', '2026-10-03T15:45:00Z', '02:30'],
    ['Europe/Warsaw', '2026-10-24T23:30:00Z', '01:00'],
    ['Europe/Warsaw', '2026-10-25T00:30:00Z', '02:00'],
    ['Europe/Warsaw', '2026-10-25T01:30:00Z', '02:00'],
    ['UTC', '2026-10-14T00:59:00Z', '00:00'],
  ])('formats the actual hour start in %s at %s', (timeZone, iso, expected) => {
    const asOfTs = Date.parse(iso)
    const snapshot = {
      ...SNAPSHOT,
      ...calendarPeriodStarts(asOfTs, timeZone),
      timeZone,
      asOfTs,
      visitorsThisHour: 0,
    }
    const tiles = analyticsOverviewTiles(
      hilosAnalyticsOverviewSnapshotSchema.parse(snapshot),
    )
    expect(tiles[2].since).toBe(`browser sessions since ${expected}`)
    expect(tiles[2].count).toBe(0)
  })
})
