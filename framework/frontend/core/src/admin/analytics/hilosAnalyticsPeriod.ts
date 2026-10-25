import { offsetMs } from '../../session/serverClock.js'
import { type HilosTableFilter } from '../../table/tableFrame.js'
import {
  calendarPeriodStarts,
  type CalendarPeriodStarts,
} from '../../timeZone/calendarPeriods.js'
import { hilosTimeZone } from '../../timeZone/timeZoneState.js'

/** Calendar choices shared by the Analytics list tables. */
export type AnalyticsPeriod = 'any-time' | 'this-hour' | 'today' | 'this-week'

/** Viewport filter key of the visible Period select. */
export const ANALYTICS_PERIOD_FILTER = 'period'
/** Context filter key of the captured reader zone. */
export const ANALYTICS_PERIOD_TIME_ZONE_FILTER = 'timeZone'
/** Context filter key of the captured calendar week's start. */
export const ANALYTICS_PERIOD_WEEK_START_TS_FILTER = 'weekStartTs'
/** Context filter key of the captured calendar day's start. */
export const ANALYTICS_PERIOD_DAY_START_TS_FILTER = 'dayStartTs'
/** Context filter key of the captured calendar hour's start. */
export const ANALYTICS_PERIOD_HOUR_START_TS_FILTER = 'hourStartTs'

/** Words of the shared Period select, in calendar order. */
export const ANALYTICS_PERIOD_COPY = {
  label: 'Period',
  anyTime: 'Any time',
  thisHour: 'This hour',
  today: 'Today',
  thisWeek: 'This week',
} as const

/** One table opening's immutable calendar context, including its formatting zone. */
export interface AnalyticsPeriodContext extends CalendarPeriodStarts {
  readonly timeZone: string
}

/** Optional opening choice and deterministic calendar inputs. */
export interface HilosAnalyticsPeriodOptions {
  readonly initialPeriod?: AnalyticsPeriod
  readonly periodContext?: AnalyticsPeriodContext
  /** Already aligned server moment; otherwise Date.now() + offsetMs() is sampled once. */
  readonly now?: number
}

/** Opening seed and select declaration; the controller alone holds the later choice. */
export interface HilosAnalyticsPeriod {
  readonly context: AnalyticsPeriodContext
  /** Reset baseline: context only, never the initial visible choice. */
  readonly initialFilter: Readonly<Record<string, unknown>>
  /** Value to set before binding; undefined serializes Any time by absence. */
  readonly initialPeriod: Exclude<AnalyticsPeriod, 'any-time'> | undefined
  readonly filter: Extract<HilosTableFilter, { kind: 'select' }>
}

/**
 * Capture one opening's calendar context and build its standard Period select.
 * This is scope-local UI context: no other process reads it, so Hilos::$rt is
 * unnecessary. The table releases its controller and binding on scope disposal.
 * Calendar boundaries and the Monday/authority TODOs belong to calendarPeriodStarts.
 * No timer or reader-zone observer changes this accepted context (P-703).
 *
 * @param options Opening choice, ready context or an explicit calendar reference.
 */
export function createHilosAnalyticsPeriod(
  options: HilosAnalyticsPeriodOptions = {},
): HilosAnalyticsPeriod {
  const timeZone = options.periodContext?.timeZone ?? hilosTimeZone.get()
  const starts =
    options.periodContext ??
    calendarPeriodStarts(options.now ?? Date.now() + offsetMs(), timeZone)
  const context: AnalyticsPeriodContext = Object.freeze({
    timeZone,
    weekStartTs: starts.weekStartTs,
    dayStartTs: starts.dayStartTs,
    hourStartTs: starts.hourStartTs,
  })
  const initialPeriod = options.initialPeriod ?? 'today'

  return {
    context,
    initialFilter: Object.freeze({
      [ANALYTICS_PERIOD_TIME_ZONE_FILTER]: context.timeZone,
      [ANALYTICS_PERIOD_WEEK_START_TS_FILTER]: context.weekStartTs,
      [ANALYTICS_PERIOD_DAY_START_TS_FILTER]: context.dayStartTs,
      [ANALYTICS_PERIOD_HOUR_START_TS_FILTER]: context.hourStartTs,
    }),
    initialPeriod: initialPeriod === 'any-time' ? undefined : initialPeriod,
    filter: {
      kind: 'select',
      key: ANALYTICS_PERIOD_FILTER,
      label: ANALYTICS_PERIOD_COPY.label,
      anyLabel: ANALYTICS_PERIOD_COPY.anyTime,
      options: () => [
        { value: 'this-hour', label: ANALYTICS_PERIOD_COPY.thisHour },
        { value: 'today', label: ANALYTICS_PERIOD_COPY.today },
        { value: 'this-week', label: ANALYTICS_PERIOD_COPY.thisWeek },
      ],
    },
  }
}
