import { z } from 'zod'
import { formatSnapshotMoment } from '../../format/moment.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type Scope, type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { usableTimeZone } from '../../timeZone/timeZoneRule.js'
import { ANALYTICS_NO_VALUE } from './hilosAnalytics.js'

/** Explains where a person's actions open while the overview is empty. */
export const ANALYTICS_OVERVIEW_EMPTY_TEXT =
  "Nothing here yet. A person's actions open from their page in the admin."

/** Link text for the admin users list. */
export const ANALYTICS_OVERVIEW_OPEN_USERS_LABEL = 'Open users'

/** Page-data section carrying one complete opening's aggregate snapshot. */
export const ANALYTICS_OVERVIEW_SECTION = 'analyticsOverview'

/** Row payload key of the captured reader's zone. */
const OVERVIEW_TIME_ZONE_FIELD = 'timeZone'
/** Row payload key of the captured calendar week's start. */
const OVERVIEW_WEEK_START_TS_FIELD = 'weekStartTs'
/** Row payload key of the captured calendar day's start. */
const OVERVIEW_DAY_START_TS_FIELD = 'dayStartTs'
/** Row payload key of the captured calendar hour's start. */
const OVERVIEW_HOUR_START_TS_FIELD = 'hourStartTs'
/** Row payload key of the common closed upper bound. */
const OVERVIEW_AS_OF_TS_FIELD = 'asOfTs'
/** Row payload key of the exact week count. */
const OVERVIEW_VISITORS_THIS_WEEK_FIELD = 'visitorsThisWeek'
/** Row payload key of the exact day count. */
const OVERVIEW_VISITORS_TODAY_FIELD = 'visitorsToday'
/** Row payload key of the exact hour count. */
const OVERVIEW_VISITORS_THIS_HOUR_FIELD = 'visitorsThisHour'

const momentSchema = z.number().int().nonnegative().max(8640000000000000)
const countSchema = z.number().int().nonnegative()

/** A complete answer; invalid or missing fields never become zero counts. */
export const hilosAnalyticsOverviewSnapshotSchema = z
  .strictObject({
    [OVERVIEW_TIME_ZONE_FIELD]: z
      .string()
      .refine((zone) => usableTimeZone(zone) !== null),
    [OVERVIEW_WEEK_START_TS_FIELD]: momentSchema,
    [OVERVIEW_DAY_START_TS_FIELD]: momentSchema,
    [OVERVIEW_HOUR_START_TS_FIELD]: momentSchema,
    [OVERVIEW_AS_OF_TS_FIELD]: momentSchema,
    [OVERVIEW_VISITORS_THIS_WEEK_FIELD]: countSchema,
    [OVERVIEW_VISITORS_TODAY_FIELD]: countSchema,
    [OVERVIEW_VISITORS_THIS_HOUR_FIELD]: countSchema,
  })
  .refine(
    (value) =>
      value.weekStartTs <= value.dayStartTs &&
      value.dayStartTs <= value.hourStartTs &&
      value.hourStartTs <= value.asOfTs &&
      value.visitorsThisWeek >= value.visitorsToday &&
      value.visitorsToday >= value.visitorsThisHour,
  )
  .readonly()

/** The immutable counts and calendar context accepted for one page opening. */
export type HilosAnalyticsOverviewSnapshot = z.infer<
  typeof hilosAnalyticsOverviewSnapshotSchema
>

/** The page's held projection and its explicit subscription lifetime. */
export interface HilosAnalyticsOverview {
  readonly snapshot: ReadonlySignal<HilosAnalyticsOverviewSnapshot | undefined>
  start(): void
  dispose(): void
}

/** Shared overview words from the Analytics design. */
export const ANALYTICS_OVERVIEW_COPY = {
  week: 'Visitors this week',
  day: 'Visitors today',
  hour: 'Visitors this hour',
  since: 'browser sessions since',
} as const

/** One ready-to-render aggregate; its period belongs to the supplied snapshot. */
export interface HilosAnalyticsOverviewTile {
  readonly key: 'week' | 'day' | 'hour'
  readonly label: string
  readonly count: number
  readonly since: string
}

/**
 * Hold the first valid answer while this exact page scope remains current.
 * ScopeManager owns the page data (frontend/data-model.md); this instance's
 * projection has no readers in other processes, so it needs no Hilos::$rt row
 * or shared cache (framework-first.md).
 *
 * @param scopes The existing page scopes filled centrally by bindPageScope.
 */
export function createHilosAnalyticsOverview(
  scopes: ScopeManager,
): HilosAnalyticsOverview {
  const held = createSignal<
    | {
        readonly scope: Scope
        readonly snapshot: HilosAnalyticsOverviewSnapshot
      }
    | undefined
  >(undefined)
  const source = computedSignal(() => {
    const scope = scopes.page()
    return {
      scope,
      value: scope?.data.signal(ANALYTICS_OVERVIEW_SECTION).get(),
    }
  })
  const snapshot = computedSignal(() => {
    const value = held.get()
    return value?.scope === scopes.page() ? value?.snapshot : undefined
  })
  let current: Scope | undefined
  let off: (() => void) | undefined

  const accept = ({ scope, value }: ReturnType<typeof source.get>): void => {
    if (scope !== current) {
      held.set(undefined)
      current = scope
    }
    if (scope?.key !== HilosPages.ANALYTICS || held.get() !== undefined) return
    const parsed = hilosAnalyticsOverviewSnapshotSchema.safeParse(value)
    if (parsed.success) held.set({ scope, snapshot: parsed.data })
  }

  return {
    snapshot,
    start() {
      if (off !== undefined) return
      held.set(undefined)
      current = undefined
      off = subscribeSignal(source, accept)
      accept(source.get())
    },
    dispose() {
      off?.()
      off = undefined
      current = undefined
      held.set(undefined)
    },
  }
}

/**
 * Project the captured calendar boundaries through the shared moment engine.
 *
 * @param snapshot The accepted counts, zone and common server reference.
 */
export function analyticsOverviewTiles(
  snapshot: HilosAnalyticsOverviewSnapshot,
): readonly [
  HilosAnalyticsOverviewTile,
  HilosAnalyticsOverviewTile,
  HilosAnalyticsOverviewTile,
] {
  const time = (moment: number): string =>
    formatSnapshotMoment(moment, snapshot.timeZone, {
      kind: 'time',
      locale: 'en-GB',
      format: { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
      now: snapshot.asOfTs,
    }) ?? ANALYTICS_NO_VALUE
  const weekday =
    formatSnapshotMoment(snapshot.weekStartTs, snapshot.timeZone, {
      kind: 'date',
      locale: 'en-GB',
      format: { weekday: 'long' },
      now: snapshot.asOfTs,
    }) ?? ANALYTICS_NO_VALUE

  return [
    {
      key: 'week',
      label: ANALYTICS_OVERVIEW_COPY.week,
      count: snapshot.visitorsThisWeek,
      since: `${ANALYTICS_OVERVIEW_COPY.since} ${weekday}, ${time(snapshot.weekStartTs)}`,
    },
    {
      key: 'day',
      label: ANALYTICS_OVERVIEW_COPY.day,
      count: snapshot.visitorsToday,
      since: `${ANALYTICS_OVERVIEW_COPY.since} ${time(snapshot.dayStartTs)}`,
    },
    {
      key: 'hour',
      label: ANALYTICS_OVERVIEW_COPY.hour,
      count: snapshot.visitorsThisHour,
      since: `${ANALYTICS_OVERVIEW_COPY.since} ${time(snapshot.hourStartTs)}`,
    },
  ]
}
