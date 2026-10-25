import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ANALYTICS_PERIOD_COPY,
  ANALYTICS_PERIOD_FILTER,
  ANALYTICS_PERIOD_TIME_ZONE_FILTER,
  ANALYTICS_PERIOD_WEEK_START_TS_FILTER,
  ANALYTICS_PERIOD_DAY_START_TS_FILTER,
  ANALYTICS_PERIOD_HOUR_START_TS_FILTER,
  createHilosAnalyticsPeriod,
  type AnalyticsPeriod,
} from '../../../src/admin/analytics/index.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { calendarPeriodStarts } from '../../../src/timeZone/calendarPeriods.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'

const NOW = Date.parse('2026-10-14T20:59:00Z')
const CONTEXT = {
  timeZone: 'Europe/Warsaw',
  weekStartTs: Date.parse('2026-10-11T22:00:00Z'),
  dayStartTs: Date.parse('2026-10-13T22:00:00Z'),
  hourStartTs: Date.parse('2026-10-14T20:00:00Z'),
}

afterEach(() => {
  applyServerTime(Date.now())
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('Analytics Period builder', () => {
  it('declares the shared filter vocabulary and words from the Analytics entry', () => {
    expect([
      ANALYTICS_PERIOD_FILTER,
      ANALYTICS_PERIOD_TIME_ZONE_FILTER,
      ANALYTICS_PERIOD_WEEK_START_TS_FILTER,
      ANALYTICS_PERIOD_DAY_START_TS_FILTER,
      ANALYTICS_PERIOD_HOUR_START_TS_FILTER,
    ]).toEqual([
      'period',
      'timeZone',
      'weekStartTs',
      'dayStartTs',
      'hourStartTs',
    ])
    expect(ANALYTICS_PERIOD_COPY).toEqual({
      label: 'Period',
      anyTime: 'Any time',
      thisHour: 'This hour',
      today: 'Today',
      thisWeek: 'This week',
    })
    const period = createHilosAnalyticsPeriod({ periodContext: CONTEXT })
    expect(period.filter).toMatchObject({
      kind: 'select',
      key: 'period',
      label: 'Period',
      anyLabel: 'Any time',
    })
    expect(period.filter.options()).toEqual([
      { value: 'this-hour', label: 'This hour' },
      { value: 'today', label: 'Today' },
      { value: 'this-week', label: 'This week' },
    ])
  })

  it('keeps Today separate from the immutable context-only reset baseline', () => {
    const period = createHilosAnalyticsPeriod({ periodContext: CONTEXT })
    expect(period.initialPeriod).toBe('today')
    expect(period.initialFilter).toEqual(CONTEXT)
    expect(period.initialFilter).not.toHaveProperty('period')
    expect(Object.isFrozen(period.context)).toBe(true)
    expect(Object.isFrozen(period.initialFilter)).toBe(true)
  })

  it.each<AnalyticsPeriod>(['any-time', 'this-hour', 'today', 'this-week'])(
    'serializes the %s opening seed without putting it into the baseline',
    (initialPeriod) => {
      const period = createHilosAnalyticsPeriod({
        initialPeriod,
        periodContext: CONTEXT,
      })
      expect(period.initialPeriod).toBe(
        initialPeriod === 'any-time' ? undefined : initialPeriod,
      )
      expect(period.initialFilter).toEqual(CONTEXT)
    },
  )

  it('samples the effective zone and server-aligned now once without a timer', () => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW - 120000)
    applyServerTime(NOW)
    const zone = vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Europe/Warsaw')
    const period = createHilosAnalyticsPeriod()
    expect(period.context).toEqual({
      timeZone: 'Europe/Warsaw',
      ...calendarPeriodStarts(NOW, 'Europe/Warsaw'),
    })
    expect(period.context).toEqual(CONTEXT)
    expect(zone).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)

    zone.mockReturnValue('Pacific/Honolulu')
    vi.setSystemTime(NOW + 86400000)
    expect(period.context).toEqual(CONTEXT)
    expect(period.initialFilter).toEqual(CONTEXT)
    expect(zone).toHaveBeenCalledTimes(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('passes an explicit now to the calendar helper without the clock offset', () => {
    vi.useFakeTimers()
    vi.setSystemTime(0)
    applyServerTime(NOW + 7200000)
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Asia/Kathmandu')
    expect(createHilosAnalyticsPeriod({ now: NOW }).context).toEqual({
      timeZone: 'Asia/Kathmandu',
      ...calendarPeriodStarts(NOW, 'Asia/Kathmandu'),
    })
  })

  it('copies a ready context without consulting the live zone or clock', () => {
    const zone = vi.spyOn(hilosTimeZone, 'get')
    const clock = vi.spyOn(Date, 'now')
    const supplied = { ...CONTEXT }
    const period = createHilosAnalyticsPeriod({
      periodContext: supplied,
      now: NaN,
    })
    expect(zone).not.toHaveBeenCalled()
    expect(clock).not.toHaveBeenCalled()
    expect(period.context).not.toBe(supplied)
    supplied.timeZone = 'UTC'
    supplied.dayStartTs = 0
    expect(period.context).toEqual(CONTEXT)
    expect(period.initialFilter).toEqual(CONTEXT)
  })
})
