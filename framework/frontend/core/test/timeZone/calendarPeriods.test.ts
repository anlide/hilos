import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatSnapshotMoment } from '../../src/format/moment.js'
import {
  calendarDays,
  calendarPeriodStarts,
} from '../../src/timeZone/calendarPeriods.js'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('calendar period starts', () => {
  it.each([
    [
      'UTC',
      '2026-01-01T12:34:56.789Z',
      '2025-12-29T00:00:00Z',
      '2026-01-01T00:00:00Z',
      '2026-01-01T12:00:00Z',
    ],
    [
      'UTC',
      '2023-01-01T23:59:59.999Z',
      '2022-12-26T00:00:00Z',
      '2023-01-01T00:00:00Z',
      '2023-01-01T23:00:00Z',
    ],
    [
      'UTC',
      '2023-01-02T00:00:00Z',
      '2023-01-02T00:00:00Z',
      '2023-01-02T00:00:00Z',
      '2023-01-02T00:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-03-29T00:59:59.999Z',
      '2026-03-22T23:00:00Z',
      '2026-03-28T23:00:00Z',
      '2026-03-29T00:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-03-29T01:00:00Z',
      '2026-03-22T23:00:00Z',
      '2026-03-28T23:00:00Z',
      '2026-03-29T01:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-03-29T21:59:59Z',
      '2026-03-22T23:00:00Z',
      '2026-03-28T23:00:00Z',
      '2026-03-29T21:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-03-29T22:00:00Z',
      '2026-03-29T22:00:00Z',
      '2026-03-29T22:00:00Z',
      '2026-03-29T22:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T00:45:00Z',
      '2026-10-18T22:00:00Z',
      '2026-10-24T22:00:00Z',
      '2026-10-25T00:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T01:45:00Z',
      '2026-10-18T22:00:00Z',
      '2026-10-24T22:00:00Z',
      '2026-10-25T01:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T23:00:00Z',
      '2026-10-25T23:00:00Z',
      '2026-10-25T23:00:00Z',
      '2026-10-25T23:00:00Z',
    ],
    [
      'Asia/Kathmandu',
      '2026-01-01T00:05:00Z',
      '2025-12-28T18:15:00Z',
      '2025-12-31T18:15:00Z',
      '2025-12-31T23:15:00Z',
    ],
    [
      'Australia/Lord_Howe',
      '2026-10-03T15:45:00Z',
      '2026-09-27T13:30:00Z',
      '2026-10-03T13:30:00Z',
      '2026-10-03T15:30:00Z',
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-04T14:45:00Z',
      '2026-03-29T13:00:00Z',
      '2026-04-04T13:00:00Z',
      '2026-04-04T14:00:00Z',
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-04T15:15:00Z',
      '2026-03-29T13:00:00Z',
      '2026-04-04T13:00:00Z',
      '2026-04-04T15:00:00Z',
    ],
    [
      'America/Sao_Paulo',
      '2018-11-04T03:30:00Z',
      '2018-10-29T03:00:00Z',
      '2018-11-04T03:00:00Z',
      '2018-11-04T03:00:00Z',
    ],
    [
      'Asia/Tehran',
      '2021-03-21T21:00:00Z',
      '2021-03-21T20:30:00Z',
      '2021-03-21T20:30:00Z',
      '2021-03-21T20:30:00Z',
    ],
    [
      'America/Havana',
      '2026-11-01T04:30:00Z',
      '2026-10-26T04:00:00Z',
      '2026-11-01T04:00:00Z',
      '2026-11-01T04:00:00Z',
    ],
    [
      'America/Havana',
      '2026-11-01T05:30:00Z',
      '2026-10-26T04:00:00Z',
      '2026-11-01T04:00:00Z',
      '2026-11-01T05:00:00Z',
    ],
    [
      'Asia/Kathmandu',
      '1985-12-31T18:40:00Z',
      '1985-12-29T18:30:00Z',
      '1985-12-31T18:30:00Z',
      '1985-12-31T18:30:00Z',
    ],
    [
      'Pacific/Apia',
      '2011-12-30T10:30:00Z',
      '2011-12-26T10:00:00Z',
      '2011-12-30T10:00:00Z',
      '2011-12-30T10:00:00Z',
    ],
  ])(
    '%s at %s has exact UTC week/day/hour starts',
    (zone, now, week, day, hour) => {
      expect(calendarPeriodStarts(Date.parse(now), zone)).toEqual({
        weekStartTs: Date.parse(week),
        dayStartTs: Date.parse(day),
        hourStartTs: Date.parse(hour),
      })
    },
  )

  it.each([
    ['Australia/Lord_Howe', '2026-10-03T15:45:00Z', '02:30'],
    ['Australia/Lord_Howe', '2026-04-04T15:15:00Z', '01:30'],
    ['Asia/Kathmandu', '1985-12-31T18:40:00Z', '00:15'],
    ['America/Sao_Paulo', '2018-11-04T03:30:00Z', '01:00'],
  ])('labels the first real minute in %s at %s', (zone, now, label) => {
    const starts = calendarPeriodStarts(Date.parse(now), zone)
    expect(
      formatSnapshotMoment(starts.hourStartTs, zone, {
        locale: 'en-GB',
        format: { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
      }),
    ).toBe(label)
  })

  it.each([undefined, {}, { DateTimeFormat: undefined }])(
    'supports UTC without Intl.DateTimeFormat (%s)',
    (host) => {
      vi.stubGlobal('Intl', host)
      expect(
        calendarPeriodStarts(Date.parse('2026-01-01T12:34:56Z'), 'UTC'),
      ).toEqual({
        weekStartTs: Date.parse('2025-12-29T00:00:00Z'),
        dayStartTs: Date.parse('2026-01-01T00:00:00Z'),
        hourStartTs: Date.parse('2026-01-01T12:00:00Z'),
      })
    },
  )

  it.each([NaN, Infinity, -Infinity, 8640000000000001])(
    'refuses an impossible moment %s',
    (now) => {
      expect(() => calendarPeriodStarts(now, 'UTC')).toThrow(RangeError)
    },
  )
})

describe('calendar day sequence', () => {
  it.each([
    [
      '2026-03-29T12:00:00Z',
      '2026-03-28T23:00:00Z',
      '2026-03-29T22:00:00Z',
      23,
    ],
    [
      '2026-10-25T12:00:00Z',
      '2026-10-24T22:00:00Z',
      '2026-10-25T23:00:00Z',
      25,
    ],
  ])('preserves the Warsaw DST day at %s', (now, start, end, hours) => {
    const days = calendarDays(Date.parse(now), 'Europe/Warsaw', 90)
    const today = days.at(-1)
    expect(days).toHaveLength(90)
    expect(today).toEqual({
      startTs: Date.parse(start),
      endTs: Date.parse(end),
    })
    expect((today!.endTs - today!.startTs) / 3600000).toBe(hours)
    for (let index = 1; index < days.length; index++) {
      expect(days[index - 1]?.endTs).toBe(days[index]?.startTs)
    }
  })

  it.each([
    [
      'UTC',
      '2026-01-01T00:00:00Z',
      '2025-12-31T00:00:00Z',
      '2026-01-02T00:00:00Z',
    ],
    [
      'UTC',
      '2024-03-01T00:00:00Z',
      '2024-02-29T00:00:00Z',
      '2024-03-02T00:00:00Z',
    ],
    [
      'Pacific/Apia',
      '2011-12-30T12:00:00Z',
      '2011-12-29T10:00:00Z',
      '2011-12-31T10:00:00Z',
    ],
    [
      'Pacific/Apia',
      '2011-12-29T12:00:00Z',
      '2011-12-28T10:00:00Z',
      '2011-12-30T10:00:00Z',
    ],
    [
      'America/Sao_Paulo',
      '2018-11-04T12:00:00Z',
      '2018-11-03T03:00:00Z',
      '2018-11-05T02:00:00Z',
    ],
    [
      'America/Havana',
      '2026-11-01T06:00:00Z',
      '2026-10-31T04:00:00Z',
      '2026-11-02T05:00:00Z',
    ],
  ])(
    'steps calendar fields across boundaries in %s at %s',
    (zone, now, first, end) => {
      const days = calendarDays(Date.parse(now), zone, 2)
      expect(days[0]?.startTs).toBe(Date.parse(first))
      expect(days[0]?.endTs).toBe(days[1]?.startTs)
      expect(days[1]?.endTs).toBe(Date.parse(end))
    },
  )

  it('uses the requested reader zone and supports UTC without Intl', () => {
    const now = Date.parse('2026-01-01T00:30:00Z')
    expect(calendarDays(now, 'America/Los_Angeles', 1)[0]?.startTs).toBe(
      Date.parse('2025-12-31T08:00:00Z'),
    )
    vi.stubGlobal('Intl', undefined)
    expect(calendarDays(now, 'UTC', 1)).toEqual([
      {
        startTs: Date.parse('2026-01-01T00:00:00Z'),
        endTs: Date.parse('2026-01-02T00:00:00Z'),
      },
    ])
  })

  it.each([0, -1, 1.5, NaN, Infinity])('refuses invalid count %s', (count) => {
    expect(() => calendarDays(Date.now(), 'UTC', count)).toThrow(RangeError)
  })
})
