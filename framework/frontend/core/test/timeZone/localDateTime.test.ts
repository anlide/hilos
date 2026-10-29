import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  localDateTimeValue,
  parseLocalDateTimeValue,
} from '../../src/timeZone/localDateTime.js'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('local date-time editor', () => {
  it.each([
    ['UTC', '1970-01-01T00:00:00Z', '1970-01-01T00:00:00'],
    ['UTC', '1969-12-31T23:59:59Z', '1969-12-31T23:59:59'],
    ['UTC', '2026-01-01T00:34:56Z', '2026-01-01T00:34:56'],
    ['Europe/Warsaw', '2026-01-01T00:34:56Z', '2026-01-01T01:34:56'],
    ['America/Los_Angeles', '2026-01-01T00:34:56Z', '2025-12-31T16:34:56'],
    ['Asia/Kathmandu', '2026-01-01T00:34:56Z', '2026-01-01T06:19:56'],
    ['Asia/Kathmandu', '1900-01-01T00:00:00Z', '1900-01-01T05:41:16'],
    ['UTC', '0001-01-01T00:00:00Z', '0001-01-01T00:00:00'],
    ['UTC', '0099-02-03T04:05:06Z', '0099-02-03T04:05:06'],
    ['Europe/Warsaw', '0099-02-03T04:05:06Z', '0099-02-03T05:29:06'],
    ['UTC', '9999-12-31T23:59:59Z', '9999-12-31T23:59:59'],
    ['UTC', '2000-02-29T23:59:59Z', '2000-02-29T23:59:59'],
    ['Europe/Warsaw', '2024-02-29T23:59:59Z', '2024-03-01T00:59:59'],
  ])('roundtrips %s at %s to the exact second', (zone, iso, value) => {
    const moment = Date.parse(iso)
    expect(localDateTimeValue(moment, zone)).toBe(value)
    expect(parseLocalDateTimeValue(value, zone)).toBe(moment)
  })

  it('uses the explicit reader zone without host-local reads', () => {
    const moment = Date.parse('2026-01-01T00:34:56Z')
    // Any accidental use of host-local fields or Date.parse must fail here.
    for (const getter of [
      'getFullYear',
      'getMonth',
      'getDate',
      'getHours',
      'getMinutes',
      'getSeconds',
    ] as const) {
      vi.spyOn(Date.prototype, getter).mockImplementation(() => {
        throw new Error('Host-local calendar read')
      })
    }
    vi.spyOn(Date, 'parse').mockImplementation(() => {
      throw new Error('Implicit date parsing')
    })
    expect(localDateTimeValue(moment, 'UTC')).toBe('2026-01-01T00:34:56')
    expect(localDateTimeValue(moment, 'Europe/Warsaw')).toBe(
      '2026-01-01T01:34:56',
    )
    expect(
      parseLocalDateTimeValue('2025-12-31T16:34:56', 'America/Los_Angeles'),
    ).toBe(moment)
  })

  it.each([
    ['2026-01-01T00:34:56.789Z', '2026-01-01T00:34:56', '2026-01-01T00:34:56Z'],
    ['1969-12-31T23:59:59.999Z', '1969-12-31T23:59:59', '1969-12-31T23:59:59Z'],
  ])('discards subsecond precision at %s', (iso, value, wholeSecond) => {
    expect(localDateTimeValue(Date.parse(iso), 'UTC')).toBe(value)
    expect(parseLocalDateTimeValue(value, 'UTC')).toBe(Date.parse(wholeSecond))
  })

  it('accepts browser-normalized zero seconds', () => {
    expect(parseLocalDateTimeValue('2026-01-01T01:34', 'Europe/Warsaw')).toBe(
      Date.parse('2026-01-01T00:34:00Z'),
    )
    expect(parseLocalDateTimeValue('1970-01-01T00:00', 'UTC')).toBe(0)
  })

  it.each([undefined, {}, { DateTimeFormat: undefined }])(
    'keeps UTC deterministic without Intl.DateTimeFormat (%s)',
    (host) => {
      vi.stubGlobal('Intl', host)
      expect(localDateTimeValue(0, 'UTC')).toBe('1970-01-01T00:00:00')
      expect(parseLocalDateTimeValue('1970-01-01T00:00:00', 'UTC')).toBe(0)
      expect(() => localDateTimeValue(0, 'Europe/Warsaw')).toThrow(RangeError)
      expect(
        parseLocalDateTimeValue('1970-01-01T00:00:00', 'Europe/Warsaw'),
      ).toBeNull()
    },
  )

  it.each([
    '',
    '2026-01-01',
    '2026-1-01T00:00:00',
    '26-01-01T00:00:00',
    '2026-01-01 00:00:00',
    '2026-01-01t00:00:00',
    '2026-01-01T0:00:00',
    '2026-01-01T00:0:00',
    '2026-01-01T00:00:0',
    '2026-01-01T00:00:00.000',
    '2026-01-01T00:00:00Z',
    '2026-01-01T00:00:00+01:00',
    ' 2026-01-01T00:00:00',
    '2026-01-01T00:00:00 ',
    '2026-01-01T00:00:00\n',
    '２０２６-01-01T00:00:00',
    '2026-00-01T00:00:00',
    '2026-13-01T00:00:00',
    '2026-01-00T00:00:00',
    '2026-02-31T00:00:00',
    '2026-04-31T00:00:00',
    '2026-02-29T00:00:00',
    '1900-02-29T00:00:00',
    '2100-02-29T00:00:00',
    '2026-01-01T24:00:00',
    '2026-01-01T00:60:00',
    '2026-01-01T00:00:60',
    '0000-01-01T00:00:00',
    '-000001-01-01T00:00:00',
    '+010000-01-01T00:00:00',
  ])('refuses invalid calendar fields or format %s', (value) => {
    expect(parseLocalDateTimeValue(value, 'UTC')).toBeNull()
    expect(parseLocalDateTimeValue(value, 'Europe/Warsaw')).toBeNull()
  })

  it.each(['', 'Unknown/Zone', ' UTC ', '+02:00', '-0500', '−05:00'])(
    'refuses an unusable zone %s without a fallback',
    (zone) => {
      expect(() => localDateTimeValue(0, zone)).toThrow(RangeError)
      expect(parseLocalDateTimeValue('1970-01-01T00:00:00', zone)).toBeNull()
    },
  )

  it.each([
    NaN,
    Infinity,
    -Infinity,
    8640000000000001,
    -8640000000000001,
    -8640000000000000,
    8640000000000000,
    Date.parse('0000-12-31T23:59:59Z'),
    Date.parse('+010000-01-01T00:00:00Z'),
  ])('refuses an uneditable moment %s', (moment) => {
    expect(() => localDateTimeValue(moment, 'UTC')).toThrow(RangeError)
  })

  it('checks the year in the reader zone at the editor boundaries', () => {
    expect(() =>
      localDateTimeValue(Date.parse('9999-12-31T23:59:59Z'), 'Europe/Warsaw'),
    ).toThrow(RangeError)
    expect(() =>
      localDateTimeValue(
        Date.parse('0001-01-01T00:00:00Z'),
        'America/New_York',
      ),
    ).toThrow(RangeError)
  })
})

describe('local date-time transitions', () => {
  it.each([
    ['Europe/Warsaw', '2026-03-29T00:59:59Z', '2026-03-29T01:59:59'],
    ['Europe/Warsaw', '2026-03-29T01:00:00Z', '2026-03-29T03:00:00'],
    ['Europe/Warsaw', '2026-10-24T23:59:59Z', '2026-10-25T01:59:59'],
    ['Europe/Warsaw', '2026-10-25T02:00:00Z', '2026-10-25T03:00:00'],
    ['Australia/Lord_Howe', '2026-10-03T15:29:59Z', '2026-10-04T01:59:59'],
    ['Australia/Lord_Howe', '2026-10-03T15:30:00Z', '2026-10-04T02:30:00'],
    ['Australia/Lord_Howe', '2026-04-04T14:29:59Z', '2026-04-05T01:29:59'],
    ['Australia/Lord_Howe', '2026-04-04T15:30:00Z', '2026-04-05T02:00:00'],
    ['Asia/Kathmandu', '1985-12-31T18:29:59Z', '1985-12-31T23:59:59'],
    ['Asia/Kathmandu', '1985-12-31T18:30:00Z', '1986-01-01T00:15:00'],
  ])('roundtrips a unique moment in %s at %s', (zone, iso, value) => {
    const moment = Date.parse(iso)
    expect(localDateTimeValue(moment, zone)).toBe(value)
    expect(parseLocalDateTimeValue(value, zone)).toBe(moment)
  })

  it.each([
    ['Europe/Warsaw', '2026-03-29T02:00:00'],
    ['Europe/Warsaw', '2026-03-29T02:30:45'],
    ['Europe/Warsaw', '2026-03-29T02:59:59'],
    ['Australia/Lord_Howe', '2026-10-04T02:00:00'],
    ['Australia/Lord_Howe', '2026-10-04T02:29:59'],
    ['Asia/Kathmandu', '1986-01-01T00:00:00'],
    ['Asia/Kathmandu', '1986-01-01T00:14:59'],
    ['Pacific/Apia', '2011-12-30T12:00:00'],
  ])('refuses the missing local time in %s at %s', (zone, value) => {
    expect(parseLocalDateTimeValue(value, zone)).toBeNull()
  })

  it.each([
    [
      'Europe/Warsaw',
      '2026-10-25T02:00:00',
      '2026-10-25T00:00:00Z',
      '2026-10-25T01:00:00Z',
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T02:30:45',
      '2026-10-25T00:30:45Z',
      '2026-10-25T01:30:45Z',
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T02:59:59',
      '2026-10-25T00:59:59Z',
      '2026-10-25T01:59:59Z',
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:30:00',
      '2026-04-04T14:30:00Z',
      '2026-04-04T15:00:00Z',
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:59:59',
      '2026-04-04T14:59:59Z',
      '2026-04-04T15:29:59Z',
    ],
  ])(
    'refuses both occurrences of a fold in %s at %s',
    (zone, value, first, second) => {
      expect(localDateTimeValue(Date.parse(first), zone)).toBe(value)
      expect(localDateTimeValue(Date.parse(second), zone)).toBe(value)
      expect(parseLocalDateTimeValue(value, zone)).toBeNull()
    },
  )
})
