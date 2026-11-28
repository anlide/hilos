import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  formatCalendarDay,
  formatMoment,
  formatSnapshotMoment,
  formatTimeZoneChoiceMoment,
  type MomentFormatOptions,
} from '../../src/format/moment.js'
import { applyServerTime, offsetMs } from '../../src/session/serverClock.js'
import { computedSignal } from '../../src/state/signal.js'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import { bindReader, type ReaderFixture } from './readerFixture.js'

let reader: ReaderFixture

beforeEach(() => {
  reader = bindReader()
  reader.choose('UTC')
  applyServerTime(Date.now())
})

afterEach(() => {
  reader.release()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
})

describe('Time zone choice preview', () => {
  it('reads explicit zones without changing the live reader or correcting the moment', () => {
    reader.choose('Europe/Warsaw')
    applyServerTime(Date.now() + 300000)
    const moment = '2026-01-01T00:00:00Z'
    expect(formatTimeZoneChoiceMoment(moment, 'UTC')).toBe('00:00')
    expect(
      formatTimeZoneChoiceMoment(Date.parse(moment), 'Asia/Kathmandu'),
    ).toBe('05:45')
    expect(formatTimeZoneChoiceMoment(moment, 'America/New_York')).toBe('19:00')
    expect(hilosTimeZone.get()).toBe('Europe/Warsaw')
    expect(formatMoment(moment)).toBe('01.01.2026 01:00:00')
  })

  it('writes the hour and minute in the region time template', () => {
    reader.region({ time: 'hh:mm:ss a' })
    expect(
      formatTimeZoneChoiceMoment('2026-01-01T00:00:00Z', 'America/New_York'),
    ).toBe('07:00 PM')
    expect(formatTimeZoneChoiceMoment('2026-01-01T00:00:00Z', 'UTC')).toBe(
      '12:00 AM',
    )
  })

  it.each([
    ['2026-03-08T06:59:59Z', '01:59'],
    ['2026-03-08T07:00:00Z', '03:00'],
    ['2026-11-01T05:59:59Z', '01:59'],
    ['2026-11-01T06:00:00Z', '01:00'],
  ])('keeps New York DST at %s', (moment, expected) => {
    expect(formatTimeZoneChoiceMoment(moment, 'America/New_York')).toBe(
      expected,
    )
  })

  it.each(['Unknown/Zone', '+02:00', '', ' UTC '])(
    'refuses an unusable explicit zone %s',
    (zone) => {
      expect(formatTimeZoneChoiceMoment(0, zone)).toBeNull()
    },
  )

  it.each([
    NaN,
    Infinity,
    8.64e15 + 1,
    '2026-02-30T00:00:00Z',
    '2026-01-01',
    '2026-01-01T00:00:00+01:00',
  ])('uses the shared strict parser for %s', (moment) => {
    expect(formatTimeZoneChoiceMoment(moment, 'UTC')).toBeNull()
  })

  it('returns null without Intl or DateTimeFormat', () => {
    vi.stubGlobal('Intl', undefined)
    expect(formatTimeZoneChoiceMoment(0, 'UTC')).toBeNull()
    vi.stubGlobal('Intl', {})
    expect(formatTimeZoneChoiceMoment(0, 'UTC')).toBeNull()
  })

  it('returns null when Intl cannot read the parts after accepting the zone', () => {
    const prototype = Intl.DateTimeFormat.prototype
    const descriptor = Object.getOwnPropertyDescriptor(
      prototype,
      'formatToParts',
    )!
    Object.defineProperty(prototype, 'formatToParts', {
      configurable: true,
      get: () => () => {
        throw new RangeError('unavailable formatter')
      },
    })
    try {
      expect(formatTimeZoneChoiceMoment(0, 'UTC')).toBeNull()
    } finally {
      Object.defineProperty(prototype, 'formatToParts', descriptor)
    }
  })
})

describe('absolute moments', () => {
  it.each([
    ['UTC', '2025-12-31T23:30:00.000Z', '31.12.2025 23:30:00'],
    ['Europe/Kyiv', '2025-12-31T23:30:00.000Z', '01.01.2026 01:30:00'],
    ['UTC', '2026-01-31T22:30:00Z', '31.01.2026 22:30:00'],
    ['Europe/Kyiv', '2026-01-31T22:30:00Z', '01.02.2026 00:30:00'],
    ['Europe/Kyiv', '2026-03-29T00:59:59Z', '29.03.2026 02:59:59'],
    ['Europe/Kyiv', '2026-03-29T01:00:00Z', '29.03.2026 04:00:00'],
    ['Europe/Kyiv', '2026-10-25T00:59:59Z', '25.10.2026 03:59:59'],
    ['Europe/Kyiv', '2026-10-25T01:00:00Z', '25.10.2026 03:00:00'],
  ])(
    'formats %s at %s identically from epoch-ms and ISO Z',
    (zone, iso, expected) => {
      reader.choose(zone)
      expect(hilosTimeZone.get()).toBe(zone)
      expect(formatMoment(iso)).toBe(expected)
      expect(formatMoment(Date.parse(iso))).toBe(expected)
    },
  )

  it('reads a changed zone after its first call', () => {
    const moment = '2025-12-31T23:30:00Z'
    expect(formatMoment(moment)).toBe('31.12.2025 23:30:00')
    reader.choose('Europe/Kyiv')
    expect(formatMoment(moment)).toBe('01.01.2026 01:30:00')
    reader.choose('UTC')
    expect(formatMoment(moment)).toBe('31.12.2025 23:30:00')
  })

  it.each([-300000, 300000])(
    'never corrects the input for a clock offset of %i ms',
    (offset) => {
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-01-01T00:00:00Z'))
      applyServerTime(Date.now() + offset)
      expect(offsetMs()).toBe(offset)
      expect(formatMoment('2025-12-31T23:59:00Z')).toBe('31.12.2025 23:59:00')
    },
  )

  it('treats small numbers as milliseconds, with no seconds heuristic', () => {
    expect(formatMoment(1767225600)).toBe('21.01.1970 10:53:45')
    expect(formatMoment(0)).toBe('01.01.1970 00:00:00')
    expect(formatMoment(-1)).toBe('31.12.1969 23:59:59')
  })

  it.each([
    ['0099-01-01T00:00:00.1Z', '01.01.0099'],
    ['0000-02-29T00:00:00Z', '29.02.0000'],
    ['-000001-01-01T00:00:00.000Z', '01.01.-0001'],
    ['+010000-01-01T00:00:00.000Z', '01.01.10000'],
  ])('writes the year of %s with four digits at least', (iso, expected) => {
    expect(formatMoment(iso, { kind: 'date' })).toBe(expected)
  })

  it.each([
    '2024-02-29T23:59:59.123Z',
    '0000-02-29T00:00:00Z',
    '0099-01-01T00:00:00.1Z',
    '2026-01-01T00:00:00.12Z',
    '2026-01-01T00:00:00.123456Z',
    '-000001-01-01T00:00:00.000Z',
    '+010000-01-01T00:00:00.000Z',
    '-271821-04-20T00:00:00.000Z',
    '+275760-09-13T00:00:00.000Z',
  ])('accepts a valid complete UTC timestamp %s', (iso) => {
    expect(formatMoment(iso)).not.toBeNull()
    expect(formatMoment(iso)).toBe(formatMoment(Date.parse(iso)))
    expect(formatSnapshotMoment(iso, 'UTC')).toBe(formatMoment(iso))
  })

  it.each([-8640000000000000, 8640000000000000, 1.5])(
    'accepts valid epoch-ms %s',
    (moment) => {
      expect(formatMoment(moment)).toBeTypeOf('string')
      expect(formatSnapshotMoment(moment, 'UTC')).toBe(formatMoment(moment))
    },
  )
})

describe('region templates', () => {
  const moment = '2026-07-05T08:04:06.789Z'

  it.each([
    ['DD.MM.YYYY', '05.07.2026'],
    ['DD/MM/YYYY', '05/07/2026'],
    ['DD-MM-YYYY', '05-07-2026'],
    ['YYYY.MM.DD', '2026.07.05'],
    ['YYYY/MM/DD', '2026/07/05'],
    ['YYYY-MM-DD', '2026-07-05'],
    ['MM/DD/YYYY', '07/05/2026'],
  ])('writes a date in the %s template', (date, expected) => {
    reader.region({ date })
    expect(formatMoment(moment, { kind: 'date' })).toBe(expected)
    expect(formatMoment(moment)).toBe(`${expected} 08:04:06`)
  })

  it.each([
    ['HH:mm:ss', '2026-07-05T08:04:06Z', '08:04:06'],
    ['HH:mm:ss', '2026-07-05T00:00:00Z', '00:00:00'],
    ['HH:mm:ss', '2026-07-05T23:59:59Z', '23:59:59'],
    ['hh:mm:ss a', '2026-07-05T08:04:06Z', '08:04:06 AM'],
    ['hh:mm:ss a', '2026-07-05T00:00:00Z', '12:00:00 AM'],
    ['hh:mm:ss a', '2026-07-05T11:59:59Z', '11:59:59 AM'],
    ['hh:mm:ss a', '2026-07-05T12:00:00Z', '12:00:00 PM'],
    ['hh:mm:ss a', '2026-07-05T13:05:00Z', '01:05:00 PM'],
  ])('writes a time in the %s template at %s', (time, at, expected) => {
    reader.region({ time })
    expect(formatMoment(at, { kind: 'time' })).toBe(expected)
  })

  it('joins the date and the time with a space', () => {
    reader.region({ date: 'MM/DD/YYYY', time: 'hh:mm:ss a' })
    expect(formatMoment('2026-07-05T20:04:06Z')).toBe('07/05/2026 08:04:06 PM')
    expect(formatMoment('2026-07-05T20:04:06Z', { kind: 'dateTime' })).toBe(
      '07/05/2026 08:04:06 PM',
    )
  })

  it.each([
    ['HH:mm:ss', { seconds: false }, '08:04'],
    ['hh:mm:ss a', { seconds: false }, '08:04 AM'],
    ['HH:mm:ss', { milliseconds: true }, '08:04:06.789'],
    ['hh:mm:ss a', { milliseconds: true }, '08:04:06.789 AM'],
    ['HH:mm:ss', { seconds: false, milliseconds: true }, '08:04'],
  ] as const)(
    'drops or extends the seconds of %s with %o',
    (time, flags, expected) => {
      reader.region({ time })
      expect(formatMoment(moment, { kind: 'time', ...flags })).toBe(expected)
    },
  )

  it('writes whole seconds as .000 when milliseconds are asked for', () => {
    expect(
      formatMoment('2026-07-05T08:04:06Z', {
        kind: 'time',
        milliseconds: true,
      }),
    ).toBe('08:04:06.000')
  })

  it('drops the seconds of a date and time too', () => {
    expect(formatMoment(moment, { seconds: false })).toBe('05.07.2026 08:04')
  })

  it('appends the short English zone name after the text', () => {
    expect(formatMoment(moment, { zoneName: true })).toBe(
      '05.07.2026 08:04:06 UTC',
    )
    reader.choose('America/New_York')
    expect(formatMoment(moment, { zoneName: true })).toBe(
      '05.07.2026 04:04:06 EDT',
    )
    expect(
      formatMoment('2026-01-05T08:04:06Z', { kind: 'date', zoneName: true }),
    ).toBe('05.01.2026 EST')
  })

  it.each([
    ['UTC', '2026-11-30T22:30:00Z', 'November 2026'],
    ['Europe/Kyiv', '2026-11-30T22:30:00Z', 'December 2026'],
    ['UTC', '2026-01-15T12:00:00Z', 'January 2026'],
  ])('writes the month and year in %s at %s', (zone, at, expected) => {
    reader.choose(zone)
    expect(formatMoment(at, { kind: 'monthYear' })).toBe(expected)
  })

  it.each([
    ['UTC', '2026-11-23T12:00:00Z', 'Monday'],
    ['UTC', '2026-11-28T12:00:00Z', 'Saturday'],
    ['UTC', '2026-11-22T22:30:00Z', 'Sunday'],
    ['Europe/Kyiv', '2026-11-22T22:30:00Z', 'Monday'],
    ['UTC', '1970-01-01T00:00:00Z', 'Thursday'],
    ['UTC', '0001-01-01T12:00:00Z', 'Monday'],
    ['UTC', '0000-01-01T12:00:00Z', 'Saturday'],
  ])('writes the weekday in %s at %s', (zone, at, expected) => {
    reader.choose(zone)
    expect(formatMoment(at, { kind: 'weekday' })).toBe(expected)
  })

  it('ignores the removed locale and format options of an unsafe JavaScript caller', () => {
    reader.choose('Europe/Kyiv')
    const unsafe = {
      locale: 'ar-SA',
      format: { timeZone: 'America/New_York', dateStyle: 'long' },
    } as MomentFormatOptions
    expect(formatMoment(moment, unsafe)).toBe('05.07.2026 11:04:06')
  })

  it('ignores a calendar reference for other kinds', () => {
    expect(formatMoment(moment, { kind: 'date', now: NaN })).toBe('05.07.2026')
  })
})

describe('formats of the tab region', () => {
  const moment = '2026-07-05T08:04:06Z'

  it('writes in the built-in English formats until the tab knows a region', () => {
    expect(formatMoment(moment)).toBe('05.07.2026 08:04:06')
  })

  it('reads the region on every call and falls back when the node is null', () => {
    reader.region({ date: 'YYYY-MM-DD', time: 'hh:mm:ss a' })
    expect(formatMoment(moment)).toBe('2026-07-05 08:04:06 AM')
    reader.region({ date: 'DD/MM/YYYY' })
    expect(formatMoment(moment)).toBe('05/07/2026 08:04:06')
    reader.region(null)
    expect(formatMoment(moment)).toBe('05.07.2026 08:04:06')
  })

  it('recomputes a derived label when the region changes', () => {
    const label = computedSignal(() => formatMoment(moment, { kind: 'date' }))
    expect(label.get()).toBe('05.07.2026')
    reader.region({ date: 'MM/DD/YYYY' })
    expect(label.get()).toBe('07/05/2026')
    reader.choose('Pacific/Kiritimati')
    expect(label.get()).toBe('07/05/2026')
    reader.choose('Pacific/Pago_Pago')
    expect(label.get()).toBe('07/04/2026')
  })
})

describe('historical snapshot labels', () => {
  it('keeps the captured zone while the ordinary formatter follows the live zone', () => {
    reader.choose('Asia/Kathmandu')
    const zone = hilosTimeZone.get()
    const moment = '2025-12-31T23:30:00Z'
    expect(formatSnapshotMoment(moment, zone)).toBe('01.01.2026 05:15:00')
    reader.choose('America/New_York')
    expect(formatSnapshotMoment(moment, zone)).toBe('01.01.2026 05:15:00')
    expect(formatSnapshotMoment(Date.parse(moment), zone)).toBe(
      '01.01.2026 05:15:00',
    )
    expect(formatMoment(moment)).toBe('31.12.2025 18:30:00')
  })

  it('writes the captured zone in the current formats of the region', () => {
    const moment = '2025-12-31T23:30:00Z'
    expect(formatSnapshotMoment(moment, 'Asia/Kathmandu')).toBe(
      '01.01.2026 05:15:00',
    )
    reader.region({ date: 'MM/DD/YYYY', time: 'hh:mm:ss a' })
    expect(formatSnapshotMoment(moment, 'Asia/Kathmandu')).toBe(
      '01/01/2026 05:15:00 AM',
    )
    expect(
      formatSnapshotMoment(moment, 'Asia/Kathmandu', {
        kind: 'time',
        seconds: false,
      }),
    ).toBe('05:15 AM')
  })

  it('compares calendar days in the snapshot zone with its fixed asOf reference', () => {
    const now = Date.parse('2026-01-01T00:01:00Z')
    const options: MomentFormatOptions = { kind: 'calendar', now }
    const moment = '2025-12-31T23:59:00Z'
    expect(formatSnapshotMoment(moment, 'Europe/Warsaw', options)).toBe('today')
    reader.choose('America/New_York')
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-06-01T00:00:00Z'))
    applyServerTime(Date.now() + 300000)
    expect(formatSnapshotMoment(moment, 'Europe/Warsaw', options)).toBe('today')
    expect(formatSnapshotMoment(moment, 'UTC', options)).toBe('yesterday')
  })

  it('requires a captured reference for calendar labels instead of reading the clock', () => {
    expect(formatSnapshotMoment(0, 'UTC', { kind: 'calendar' })).toBeNull()
  })

  it.each([
    '',
    ' Europe/Warsaw',
    'Europe/Warsaw ',
    'No/Such_Zone',
    '+02:00',
    '-0500',
  ])('returns null for an unusable snapshot zone %s', (zone) => {
    expect(formatSnapshotMoment(0, zone)).toBeNull()
  })
})

describe('calendar labels', () => {
  it.each([
    ['UTC', '2026-01-01T00:01:00Z', '2025-12-31T23:59:00Z', 'yesterday'],
    ['UTC', '2026-01-01T23:59:00Z', '2026-01-01T00:01:00Z', 'today'],
    ['UTC', '2026-01-31T23:59:00Z', '2026-02-01T00:01:00Z', 'tomorrow'],
    [
      'Europe/Kyiv',
      '2025-12-31T22:01:00Z',
      '2025-12-31T21:59:00Z',
      'yesterday',
    ],
    ['Europe/Kyiv', '2026-01-01T21:59:00Z', '2025-12-31T22:01:00Z', 'today'],
    ['Europe/Kyiv', '2026-01-31T21:59:00Z', '2026-01-31T22:01:00Z', 'tomorrow'],
    // Noon to noon crosses 23 hours in spring and 25 hours in autumn.
    [
      'Europe/Kyiv',
      '2026-03-29T09:00:00Z',
      '2026-03-28T10:00:00Z',
      'yesterday',
    ],
    ['Europe/Kyiv', '2026-03-28T10:00:00Z', '2026-03-29T09:00:00Z', 'tomorrow'],
    [
      'Europe/Kyiv',
      '2026-10-25T10:00:00Z',
      '2026-10-24T09:00:00Z',
      'yesterday',
    ],
    ['Europe/Kyiv', '2026-10-24T09:00:00Z', '2026-10-25T10:00:00Z', 'tomorrow'],
    // The same local day can span almost 25 hours, and two dates can be only 23 hours apart.
    ['Europe/Kyiv', '2026-10-25T21:59:00Z', '2026-10-24T21:01:00Z', 'today'],
    [
      'Europe/Kyiv',
      '2026-03-29T21:01:00Z',
      '2026-03-28T21:59:00Z',
      '28.03.2026',
    ],
    [
      'Europe/Kyiv',
      '2026-03-28T21:59:00Z',
      '2026-03-29T21:01:00Z',
      '30.03.2026',
    ],
    ['Europe/Kyiv', '2026-03-28T22:01:00Z', '2026-03-29T20:59:00Z', 'today'],
    ['UTC', '0000-01-01T00:01:00Z', '-000001-12-31T23:59:00Z', 'yesterday'],
    ['UTC', '0001-01-01T00:01:00Z', '0000-12-31T23:59:00Z', 'yesterday'],
  ])(
    'compares local days in %s: reference %s, moment %s',
    (zone, reference, moment, expected) => {
      reader.choose(zone)
      expect(
        formatMoment(moment, { kind: 'calendar', now: Date.parse(reference) }),
      ).toBe(expected)
    },
  )

  it('keeps the calendar words English in any region and writes other days by its template', () => {
    reader.region({ date: 'YYYY/MM/DD', time: 'hh:mm:ss a' })
    const now = Date.parse('2026-01-01T12:00:00Z')
    expect(formatMoment(now, { kind: 'calendar', now })).toBe('today')
    expect(
      formatMoment('2026-01-05T00:00:00Z', { kind: 'calendar', now }),
    ).toBe('2026/01/05')
  })

  it.each([-300000, 300000])(
    'defaults its reference to server time with offset %i ms',
    (offset) => {
      const localNow = Date.parse(
        offset > 0 ? '2026-01-01T23:58:00Z' : '2026-01-02T00:02:00Z',
      )
      vi.spyOn(Date, 'now').mockReturnValue(localNow)
      applyServerTime(localNow + offset)
      const moment = '2026-01-02T00:01:00Z'
      expect(formatMoment(moment, { kind: 'calendar' })).toBe(
        offset > 0 ? 'today' : 'tomorrow',
      )
      expect(formatMoment(moment, { kind: 'calendar', now: localNow })).toBe(
        offset > 0 ? 'tomorrow' : 'today',
      )
    },
  )

  it('reads an explicit now in server scale without applying the offset again', () => {
    const now = Date.parse('2026-01-01T23:58:00Z')
    vi.spyOn(Date, 'now').mockReturnValue(now)
    applyServerTime(now + 300000)
    expect(
      formatMoment('2026-01-02T00:01:00Z', { kind: 'calendar', now }),
    ).toBe('tomorrow')
  })

  it.each([-8640000000000000, 8640000000000000])(
    'labels the same day at Date range edge %s',
    (now) => {
      reader.choose('Europe/Kyiv')
      expect(formatMoment(now, { kind: 'calendar', now })).toBe('today')
    },
  )

  it('falls back to the region date outside the three named days', () => {
    expect(
      formatMoment('2026-01-05T00:00:00Z', {
        kind: 'calendar',
        now: Date.parse('2026-01-01T00:00:00Z'),
      }),
    ).toBe('05.01.2026')
  })

  it('names the day without Intl.RelativeTimeFormat', () => {
    vi.stubGlobal('Intl', { DateTimeFormat: Intl.DateTimeFormat })
    const now = Date.parse('2026-01-01T00:00:00Z')
    expect(formatMoment(now, { kind: 'calendar', now })).toBe('today')
  })
})

describe('calendar days', () => {
  it.each([
    ['DD.MM.YYYY', '2026-11-26', '26.11.2026'],
    ['MM/DD/YYYY', '2026-11-26', '11/26/2026'],
    ['YYYY-MM-DD', '2024-02-29', '2024-02-29'],
    ['DD.MM.YYYY', '0000-01-01', '01.01.0000'],
  ])('writes a calendar day in the %s template: %s', (date, day, expected) => {
    reader.region({ date })
    expect(formatCalendarDay(day)).toBe(expected)
  })

  it.each(['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'UTC'])(
    'keeps the recorded day in reader zone %s',
    (zone) => {
      reader.choose(zone)
      expect(formatCalendarDay('2026-01-01')).toBe('01.01.2026')
    },
  )

  it.each([
    '2026-02-30',
    '2026-02-29',
    '2026-13-01',
    '2026-00-10',
    '2026-04-31',
    '2026-1-1',
    '2026-01-01T00:00:00Z',
    ' 2026-01-01',
    '+002026-01-01',
    '',
  ])('returns null for %s', (day) => {
    expect(formatCalendarDay(day)).toBeNull()
  })
})

describe('unusable input and host capabilities', () => {
  it.each([
    NaN,
    Infinity,
    -Infinity,
    8640000000000001,
    -8640000000000001,
    '',
    '1767225600000',
    '2026-01-01',
    '2026-01-01 12:00:00',
    '2026-01-01T12:00:00',
    '2026-01-01T12:00:00+00:00',
    '2026-01-01T12:00:00+02:00',
    '2026-01-01t12:00:00z',
    '2026-01-01T12:00Z',
    '2026-1-1T12:00:00Z',
    '2026-01-01T12:00:00Z\n',
    ' 2026-01-01T12:00:00Z',
    '2026-02-29T00:00:00Z',
    '2024-02-30T00:00:00Z',
    '1900-02-29T00:00:00Z',
    '2026-04-31T00:00:00Z',
    '2026-00-01T00:00:00Z',
    '2026-13-01T00:00:00Z',
    '2026-01-00T00:00:00Z',
    '2026-01-32T00:00:00Z',
    '2026-01-01T24:00:00Z',
    '2026-01-01T12:60:00Z',
    '2026-01-01T12:00:60Z',
    '+275760-09-13T00:00:00.001Z',
    '-000000-01-01T00:00:00Z',
  ])('returns null for %s', (moment) => {
    expect(formatMoment(moment)).toBeNull()
    expect(formatSnapshotMoment(moment, 'UTC')).toBeNull()
  })

  it('rejects a Date object passed by an unsafe JavaScript caller', () => {
    expect(formatMoment(new Date() as unknown as number)).toBeNull()
    expect(
      formatSnapshotMoment(new Date() as unknown as number, 'UTC'),
    ).toBeNull()
  })

  it.each([NaN, Infinity, -Infinity, 8640000000000001, -8640000000000001])(
    'rejects an impossible calendar reference %s',
    (now) => {
      expect(formatMoment(0, { kind: 'calendar', now })).toBeNull()
      expect(
        formatSnapshotMoment(0, 'UTC', { kind: 'calendar', now }),
      ).toBeNull()
    },
  )

  it('returns null if the default server reference is impossible', () => {
    applyServerTime(NaN)
    expect(formatMoment(0, { kind: 'calendar' })).toBeNull()
  })

  it.each([undefined, {}, { DateTimeFormat: undefined }])(
    'returns null when Intl.DateTimeFormat is unavailable',
    (host) => {
      vi.stubGlobal('Intl', host)
      expect(formatMoment(0)).toBeNull()
      expect(formatMoment(0, { kind: 'calendar', now: 0 })).toBeNull()
      expect(formatSnapshotMoment(0, 'UTC')).toBeNull()
      expect(
        formatSnapshotMoment(0, 'UTC', { kind: 'calendar', now: 0 }),
      ).toBeNull()
    },
  )
})
