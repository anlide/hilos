import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createIntlDateTimeZoneAdapter,
  type DateTimeZoneAdapter,
  type DateTimeZoneCandidate,
  type DateTimeZoneProjection,
  type DateTimeZoneResolution,
  type LocalDateTimeParts,
} from '../../src/base/index.js'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

// Fixture coordinates are explicitly UTC; no expectations come from the adapter.
function localParts(iso: string): LocalDateTimeParts {
  const date = new Date(`${iso}Z`)

  return {
    year: date.getUTCFullYear(),
    month: date.getUTCMonth() + 1,
    day: date.getUTCDate(),
    hour: date.getUTCHours(),
    minute: date.getUTCMinutes(),
    second: date.getUTCSeconds(),
    millisecond: date.getUTCMilliseconds(),
  }
}

function candidate(iso: string, offsetSeconds: number): DateTimeZoneCandidate {
  return { epochMs: Date.parse(iso), offsetSeconds }
}

const validParts = localParts('2026-01-01T00:34:56.123')
const adapter: DateTimeZoneAdapter = createIntlDateTimeZoneAdapter()

describe('exact zone adapter validation', () => {
  it.each([
    ['year', 'date', 1, 9999],
    ['month', 'date', 1, 12],
    ['day', 'date', 1, 31],
    ['hour', 'time', 0, 23],
    ['minute', 'time', 0, 59],
    ['second', 'time', 0, 59],
    ['millisecond', 'time', 0, 999],
  ] as const)(
    'refuses noninteger and out-of-range %s',
    (field, reason, min, max) => {
      for (const value of [
        NaN,
        Infinity,
        -Infinity,
        1.5,
        min - 1,
        max + 1,
        '1',
        null,
        undefined,
      ]) {
        expect(
          adapter.resolve({ ...validParts, [field]: value }, 'UTC'),
          `${field}: ${String(value)}`,
        ).toEqual({ status: 'invalid', reason })
      }
    },
  )

  it.each([
    [1900, 2, 29],
    [2100, 2, 29],
    [2026, 2, 29],
    [2026, 2, 31],
    [2026, 4, 31],
  ])('refuses an impossible Gregorian date %s-%s-%s', (year, month, day) => {
    expect(adapter.resolve({ ...validParts, year, month, day }, 'UTC')).toEqual(
      {
        status: 'invalid',
        reason: 'date',
      },
    )
  })

  it.each(['2000-02-29T23:59:59.999', '2024-02-29T00:00:00.001'])(
    'accepts the Gregorian leap date %s',
    (iso) => {
      expect(adapter.resolve(localParts(iso), 'UTC')).toEqual({
        status: 'unique',
        candidate: candidate(`${iso}Z`, 0),
      })
    },
  )

  it('reports date before time, and time before zone', () => {
    expect(
      adapter.resolve({ ...validParts, day: 0, hour: 24 }, 'Unknown/Zone'),
    ).toEqual({ status: 'invalid', reason: 'date' })
    expect(
      adapter.resolve({ ...validParts, hour: 24 }, 'Unknown/Zone'),
    ).toEqual({ status: 'invalid', reason: 'time' })
    expect(adapter.resolve(validParts, 'Unknown/Zone')).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(adapter.project(NaN, 'Unknown/Zone')).toEqual({
      status: 'invalid',
      reason: 'moment',
    })
  })

  it.each([
    '',
    'Unknown/Zone',
    ' UTC ',
    'Europe/Warsaw ',
    '\tUTC',
    '+02:00',
    '-0500',
    '+02',
    '−05:00',
  ])('refuses zone %s without a fallback', (zone) => {
    expect(adapter.resolve(validParts, zone)).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(adapter.project(0, zone)).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
  })

  it.each([
    NaN,
    Infinity,
    -Infinity,
    0.5,
    -0.5,
    8640000000000001,
    -8640000000000001,
    8640000000000000,
    -8640000000000000,
    Date.parse('0000-12-31T23:59:59.999Z'),
    Date.parse('+010000-01-01T00:00:00.000Z'),
  ])('refuses an uneditable moment %s', (moment) => {
    expect(adapter.project(moment, 'UTC')).toEqual({
      status: 'invalid',
      reason: 'moment',
    })
  })

  it.each([
    ['Europe/Warsaw', '9999-12-31T23:59:59.999Z'],
    ['America/New_York', '0001-01-01T00:00:00.000Z'],
  ])('checks the local year in %s', (zone, iso) => {
    expect(adapter.project(Date.parse(iso), zone)).toEqual({
      status: 'invalid',
      reason: 'moment',
    })
  })

  it('leaves the input fields unchanged', () => {
    const parts = Object.freeze(localParts('2026-10-25T02:30:45.123'))
    expect(adapter.resolve(parts, 'Europe/Warsaw').status).toBe('fold')
    expect(parts).toEqual(localParts('2026-10-25T02:30:45.123'))
  })
})

describe('exact zone adapter environment boundary', () => {
  it.each([
    undefined,
    {},
    { DateTimeFormat: undefined },
    {
      DateTimeFormat: class {
        constructor() {
          throw new Error('Unavailable Intl')
        }
      },
    },
  ])('creates and converts UTC without usable Intl (%s)', (host) => {
    const parts = localParts('1969-12-31T23:59:59.999')
    vi.stubGlobal('Intl', host)
    const utcAdapter = createIntlDateTimeZoneAdapter()
    expect(utcAdapter.resolve(parts, 'UTC')).toEqual({
      status: 'unique',
      candidate: { epochMs: -1, offsetSeconds: 0 },
    })
    expect(utcAdapter.project(-1, 'UTC')).toEqual({
      status: 'valid',
      parts,
      candidate: { epochMs: -1, offsetSeconds: 0 },
    })
    expect(utcAdapter.resolve(parts, 'Europe/Warsaw')).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(utcAdapter.project(-1, 'Europe/Warsaw')).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
  })

  it('does not read Intl on creation or on the exact UTC path', () => {
    const parts = localParts('1970-01-01T00:00:00.000')
    vi.stubGlobal('Intl', undefined)
    Object.defineProperty(globalThis, 'Intl', {
      configurable: true,
      get() {
        throw new Error('Unexpected Intl read')
      },
    })
    const utcAdapter = createIntlDateTimeZoneAdapter()
    expect(utcAdapter.resolve(parts, 'UTC')).toEqual({
      status: 'unique',
      candidate: { epochMs: 0, offsetSeconds: 0 },
    })
    expect(utcAdapter.project(0, 'UTC')).toEqual({
      status: 'valid',
      parts,
      candidate: { epochMs: 0, offsetSeconds: 0 },
    })
  })

  it('reports an unavailable Gregorian formatter as an invalid zone', () => {
    const realFormatter = Intl.DateTimeFormat
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(
      function (locale, options) {
        if (options?.calendar === 'gregory')
          throw new RangeError('Unavailable calendar')

        return new realFormatter(locale, options)
      },
    )
    expect(adapter.resolve(validParts, 'Europe/Warsaw')).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(adapter.project(0, 'Europe/Warsaw')).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
  })

  it('does not hide unexpected field-reading errors as gaps or invalid input', () => {
    vi.spyOn(Intl.DateTimeFormat.prototype, 'formatToParts').mockImplementation(
      () => {
        throw new Error('Broken field reader')
      },
    )
    expect(() => adapter.resolve(validParts, 'Europe/Warsaw')).toThrow(
      'Broken field reader',
    )
    expect(() => adapter.project(0, 'Europe/Warsaw')).toThrow(
      'Broken field reader',
    )
  })

  it('reads no host-local fields, live clock or implicit Date.parse', () => {
    const moment = Date.parse('2026-01-01T00:34:56.123Z')
    const utc = localParts('2026-01-01T00:34:56.123')
    const warsaw = localParts('2026-01-01T01:34:56.123')
    const losAngeles = localParts('2025-12-31T16:34:56.123')
    for (const getter of [
      'getFullYear',
      'getMonth',
      'getDate',
      'getDay',
      'getHours',
      'getMinutes',
      'getSeconds',
      'getMilliseconds',
      'getTimezoneOffset',
    ] as const) {
      vi.spyOn(Date.prototype, getter).mockImplementation(() => {
        throw new Error('Host-local calendar read')
      })
    }
    for (const method of ['parse', 'now'] as const) {
      vi.spyOn(Date, method).mockImplementation(() => {
        throw new Error('Implicit parsing or clock read')
      })
    }
    const explicitAdapter = createIntlDateTimeZoneAdapter()
    for (const [zone, parts, offsetSeconds] of [
      ['UTC', utc, 0],
      ['Europe/Warsaw', warsaw, 3600],
      ['America/Los_Angeles', losAngeles, -28800],
    ] as const) {
      expect(explicitAdapter.project(moment, zone)).toEqual({
        status: 'valid',
        parts,
        candidate: { epochMs: moment, offsetSeconds },
      })
      expect(explicitAdapter.resolve(parts, zone)).toEqual({
        status: 'unique',
        candidate: { epochMs: moment, offsetSeconds },
      })
    }
  })
})

describe('exact zone projections and unique resolutions', () => {
  it.each([
    ['UTC', '1970-01-01T00:00:00.000Z', '1970-01-01T00:00:00.000', 0],
    ['UTC', '1969-12-31T23:59:59.999Z', '1969-12-31T23:59:59.999', 0],
    ['UTC', '2026-01-01T00:34:56.001Z', '2026-01-01T00:34:56.001', 0],
    [
      'Europe/Warsaw',
      '2026-01-01T00:34:56.123Z',
      '2026-01-01T01:34:56.123',
      3600,
    ],
    [
      'America/Los_Angeles',
      '2026-01-01T00:34:56.999Z',
      '2025-12-31T16:34:56.999',
      -28800,
    ],
    [
      'Asia/Kathmandu',
      '2026-01-01T00:34:56.123Z',
      '2026-01-01T06:19:56.123',
      20700,
    ],
    [
      'Asia/Kathmandu',
      '1900-01-01T00:00:00.001Z',
      '1900-01-01T05:41:16.001',
      20476,
    ],
    [
      'Asia/Kathmandu',
      '1969-12-31T23:59:59.999Z',
      '1970-01-01T05:29:59.999',
      19800,
    ],
    [
      'America/Los_Angeles',
      '1969-12-31T23:59:59.999Z',
      '1969-12-31T15:59:59.999',
      -28800,
    ],
    ['UTC', '0001-01-01T00:00:00.001Z', '0001-01-01T00:00:00.001', 0],
    ['UTC', '0099-02-03T04:05:06.123Z', '0099-02-03T04:05:06.123', 0],
    [
      'Europe/Warsaw',
      '0099-02-03T04:05:06.123Z',
      '0099-02-03T05:29:06.123',
      5040,
    ],
    [
      'Europe/Warsaw',
      '0001-01-01T00:00:00.999Z',
      '0001-01-01T01:24:00.999',
      5040,
    ],
    ['UTC', '9999-12-31T23:59:59.999Z', '9999-12-31T23:59:59.999', 0],
    [
      'Europe/Warsaw',
      '9999-12-31T22:59:59.999Z',
      '9999-12-31T23:59:59.999',
      3600,
    ],
    [
      'America/Los_Angeles',
      '+010000-01-01T00:00:00.123Z',
      '9999-12-31T16:00:00.123',
      -28800,
    ],
    [
      'Europe/Warsaw',
      '0000-12-31T23:00:00.123Z',
      '0001-01-01T00:24:00.123',
      5040,
    ],
    [
      'US/Eastern',
      '2026-01-01T00:34:56.123Z',
      '2025-12-31T19:34:56.123',
      -18000,
    ],
    [
      'Europe/Kiev',
      '2026-01-01T00:34:56.123Z',
      '2026-01-01T02:34:56.123',
      7200,
    ],
    ['Etc/GMT-3', '2026-01-01T00:34:56.123Z', '2026-01-01T03:34:56.123', 10800],
  ] as const)('roundtrips %s at %s exactly', (zone, iso, local, offset) => {
    const parts = localParts(local)
    const expected = candidate(iso, offset)
    const projection: DateTimeZoneProjection = adapter.project(
      expected.epochMs,
      zone,
    )
    expect(projection).toEqual({ status: 'valid', parts, candidate: expected })
    const resolution: DateTimeZoneResolution = adapter.resolve(parts, zone)
    expect(resolution).toEqual({ status: 'unique', candidate: expected })
  })
})

describe('real positive offset transitions', () => {
  it.each([
    {
      zone: 'Europe/Warsaw',
      missing: '2026-03-29T02:30:45.123',
      firstMissing: '2026-03-29T02:00:00.000',
      lastMissing: '2026-03-29T02:59:59.999',
      before: '2026-03-29T00:59:59.999Z',
      after: '2026-03-29T01:00:00.000Z',
      beforeLocal: '2026-03-29T01:59:59.999',
      afterLocal: '2026-03-29T03:00:00.000',
      oldOffset: 3600,
      newOffset: 7200,
    },
    {
      zone: 'Australia/Lord_Howe',
      missing: '2026-10-04T02:15:00.123',
      firstMissing: '2026-10-04T02:00:00.000',
      lastMissing: '2026-10-04T02:29:59.999',
      before: '2026-10-03T15:29:59.999Z',
      after: '2026-10-03T15:30:00.000Z',
      beforeLocal: '2026-10-04T01:59:59.999',
      afterLocal: '2026-10-04T02:30:00.000',
      oldOffset: 37800,
      newOffset: 39600,
    },
    {
      zone: 'Asia/Kathmandu',
      missing: '1986-01-01T00:05:00.123',
      firstMissing: '1986-01-01T00:00:00.000',
      lastMissing: '1986-01-01T00:14:59.999',
      before: '1985-12-31T18:29:59.999Z',
      after: '1985-12-31T18:30:00.000Z',
      beforeLocal: '1985-12-31T23:59:59.999',
      afterLocal: '1986-01-01T00:15:00.000',
      oldOffset: 19800,
      newOffset: 20700,
    },
    {
      zone: 'Pacific/Apia',
      missing: '2011-12-30T12:00:00.123',
      firstMissing: '2011-12-30T00:00:00.000',
      lastMissing: '2011-12-30T23:59:59.999',
      before: '2011-12-30T09:59:59.999Z',
      after: '2011-12-30T10:00:00.000Z',
      beforeLocal: '2011-12-29T23:59:59.999',
      afterLocal: '2011-12-31T00:00:00.000',
      oldOffset: -36000,
      newOffset: 50400,
    },
  ])('reports the whole gap and its exact boundaries in $zone', (fixture) => {
    const before = candidate(fixture.before, fixture.oldOffset)
    const after = candidate(fixture.after, fixture.newOffset)
    for (const local of [
      fixture.firstMissing,
      fixture.missing,
      fixture.lastMissing,
    ]) {
      expect(adapter.resolve(localParts(local), fixture.zone)).toEqual({
        status: 'gap',
        before,
        after,
      })
    }
    for (const [expected, local] of [
      [before, fixture.beforeLocal],
      [after, fixture.afterLocal],
    ] as const) {
      const parts = localParts(local)
      expect(adapter.project(expected.epochMs, fixture.zone)).toEqual({
        status: 'valid',
        parts,
        candidate: expected,
      })
      expect(adapter.resolve(parts, fixture.zone)).toEqual({
        status: 'unique',
        candidate: expected,
      })
    }
  })
})

describe('real repeated local intervals', () => {
  it.each([
    [
      'Europe/Warsaw',
      '2026-10-25T02:00:00.000',
      '2026-10-25T00:00:00.000Z',
      7200,
      '2026-10-25T01:00:00.000Z',
      3600,
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T02:30:45.123',
      '2026-10-25T00:30:45.123Z',
      7200,
      '2026-10-25T01:30:45.123Z',
      3600,
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T02:59:59.999',
      '2026-10-25T00:59:59.999Z',
      7200,
      '2026-10-25T01:59:59.999Z',
      3600,
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:30:00.000',
      '2026-04-04T14:30:00.000Z',
      39600,
      '2026-04-04T15:00:00.000Z',
      37800,
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:45:00.001',
      '2026-04-04T14:45:00.001Z',
      39600,
      '2026-04-04T15:15:00.001Z',
      37800,
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:59:59.999',
      '2026-04-04T14:59:59.999Z',
      39600,
      '2026-04-04T15:29:59.999Z',
      37800,
    ],
  ] as const)(
    'returns both exact occurrences in %s at %s',
    (zone, local, first, firstOffset, second, secondOffset) => {
      const parts = localParts(local)
      const candidates = [
        candidate(first, firstOffset),
        candidate(second, secondOffset),
      ]
      expect(adapter.resolve(parts, zone)).toEqual({
        status: 'fold',
        candidates,
      })
      for (const expected of candidates) {
        expect(adapter.project(expected.epochMs, zone)).toEqual({
          status: 'valid',
          parts,
          candidate: expected,
        })
        expect(adapter.resolve(parts, zone)).toEqual({
          status: 'fold',
          candidates,
        })
      }
    },
  )

  it.each([
    [
      'Europe/Warsaw',
      '2026-10-25T01:59:59.999',
      '2026-10-24T23:59:59.999Z',
      7200,
    ],
    [
      'Europe/Warsaw',
      '2026-10-25T03:00:00.000',
      '2026-10-25T02:00:00.000Z',
      3600,
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T01:29:59.999',
      '2026-04-04T14:29:59.999Z',
      39600,
    ],
    [
      'Australia/Lord_Howe',
      '2026-04-05T02:00:00.000',
      '2026-04-04T15:30:00.000Z',
      37800,
    ],
  ] as const)(
    'keeps times immediately outside the fold unique in %s',
    (zone, local, iso, offset) => {
      const parts = localParts(local)
      const expected = candidate(iso, offset)
      expect(adapter.resolve(parts, zone)).toEqual({
        status: 'unique',
        candidate: expected,
      })
      expect(adapter.project(expected.epochMs, zone)).toEqual({
        status: 'valid',
        parts,
        candidate: expected,
      })
    },
  )
})
