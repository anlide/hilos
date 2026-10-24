import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatSnapshotMoment } from '../../src/format/moment.js'
import { calendarPeriodStarts } from '../../src/timeZone/calendarPeriods.js'

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
