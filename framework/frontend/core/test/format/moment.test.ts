import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import {
  formatMoment,
  type MomentFormatOptions,
} from '../../src/format/moment.js'
import { applyServerTime, offsetMs } from '../../src/session/serverClock.js'
import {
  bindTimeZoneState,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'

/** The real binding receives the same session frame as an application's socket. */
function fakeConnection() {
  const listeners = new Set<(signal: ProjectSignal) => void>()

  return {
    projectTimeZone: 'UTC',
    on(event: string, listener: (payload: never) => void): () => void {
      const projectListener = listener as (signal: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(projectListener)

      return () => {
        listeners.delete(projectListener)
      }
    },
    choose(timeZone: string): void {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as ProjectSignal)
      }
    },
  }
}

const explicit: MomentFormatOptions = {
  locale: 'en-GB',
  format: {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  },
}
let connection: ReturnType<typeof fakeConnection>
let release: () => void

beforeEach(() => {
  connection = fakeConnection()
  release = bindTimeZoneState(connection as unknown as HilosConnection)
  connection.choose('UTC')
  applyServerTime(Date.now())
})

afterEach(() => {
  release()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
})

describe('absolute moments', () => {
  it.each([
    ['UTC', '2025-12-31T23:30:00.000Z', '31/12/2025, 23:30:00'],
    ['Europe/Kyiv', '2025-12-31T23:30:00.000Z', '01/01/2026, 01:30:00'],
    ['UTC', '2026-01-31T22:30:00Z', '31/01/2026, 22:30:00'],
    ['Europe/Kyiv', '2026-01-31T22:30:00Z', '01/02/2026, 00:30:00'],
    ['Europe/Kyiv', '2026-03-29T00:59:59Z', '29/03/2026, 02:59:59'],
    ['Europe/Kyiv', '2026-03-29T01:00:00Z', '29/03/2026, 04:00:00'],
    ['Europe/Kyiv', '2026-10-25T00:59:59Z', '25/10/2026, 03:59:59'],
    ['Europe/Kyiv', '2026-10-25T01:00:00Z', '25/10/2026, 03:00:00'],
  ])(
    'formats %s at %s identically from epoch-ms and ISO Z',
    (zone, iso, expected) => {
      connection.choose(zone)
      expect(hilosTimeZone.get()).toBe(zone)
      expect(formatMoment(iso, explicit)).toBe(expected)
      expect(formatMoment(Date.parse(iso), explicit)).toBe(expected)
    },
  )

  it('reads a changed zone after its first call', () => {
    const moment = '2025-12-31T23:30:00Z'
    expect(formatMoment(moment, explicit)).toBe('31/12/2025, 23:30:00')
    connection.choose('Europe/Kyiv')
    expect(formatMoment(moment, explicit)).toBe('01/01/2026, 01:30:00')
    connection.choose('UTC')
    expect(formatMoment(moment, explicit)).toBe('31/12/2025, 23:30:00')
  })

  it.each([-300000, 300000])(
    'never corrects the input for a clock offset of %i ms',
    (offset) => {
      vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-01-01T00:00:00Z'))
      applyServerTime(Date.now() + offset)
      expect(offsetMs()).toBe(offset)
      expect(formatMoment('2025-12-31T23:59:00Z', explicit)).toBe(
        '31/12/2025, 23:59:00',
      )
    },
  )

  it('treats small numbers as milliseconds, with no seconds heuristic', () => {
    expect(formatMoment(1767225600, explicit)).toBe('21/01/1970, 10:53:45')
    expect(formatMoment(0, explicit)).toBe('01/01/1970, 00:00:00')
    expect(formatMoment(-1, explicit)).toBe('31/12/1969, 23:59:59')
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
    expect(formatMoment(iso, explicit)).not.toBeNull()
    expect(formatMoment(iso, explicit)).toBe(
      formatMoment(Date.parse(iso), explicit),
    )
  })

  it.each([-8640000000000000, 8640000000000000, 1.5])(
    'accepts valid epoch-ms %s',
    (moment) => {
      expect(formatMoment(moment)).toBeTypeOf('string')
    },
  )
})

describe('presentation options', () => {
  const moment = Date.parse('2026-07-15T12:34:56Z')

  it.each([
    ['date', { dateStyle: 'medium' }],
    ['time', { timeStyle: 'medium' }],
    ['dateTime', { dateStyle: 'medium', timeStyle: 'medium' }],
  ] as const)('uses the %s preset', (kind, format) => {
    connection.choose('Europe/Kyiv')
    expect(formatMoment(moment, { kind, locale: 'en-GB' })).toBe(
      new Intl.DateTimeFormat('en-GB', {
        ...format,
        timeZone: 'Europe/Kyiv',
      }).format(moment),
    )
  })

  it('defaults to dateTime and the browser locale', () => {
    expect(formatMoment(moment)).toBe(
      new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: 'UTC',
      }).format(moment),
    )
  })

  it.each(['date', 'time', 'dateTime', 'calendar'] as const)(
    'replaces the whole %s preset with custom components',
    (kind) => {
      expect(
        formatMoment(moment, {
          kind,
          locale: ['en-GB'],
          format: { year: 'numeric' },
          now: 0,
        }),
      ).toBe('2026')
    },
  )

  it('does not let an unsafe JavaScript options object override the effective zone', () => {
    connection.choose('Europe/Kyiv')
    const unsafe = {
      ...explicit,
      format: { ...explicit.format, timeZone: 'America/New_York' },
    }
    expect(formatMoment(moment, unsafe)).toBe('15/07/2026, 15:34:56')
  })

  it('leaves developer locale and format errors visible, including on a calendar label', () => {
    expect(() => formatMoment(moment, { locale: 'invalid_locale' })).toThrow(
      RangeError,
    )
    for (const kind of ['dateTime', 'calendar'] as const) {
      expect(() =>
        formatMoment(moment, {
          kind,
          now: moment,
          format: { dateStyle: 'long', year: 'numeric' },
        }),
      ).toThrow(TypeError)
    }
  })

  it('ignores a calendar reference for other kinds', () => {
    expect(formatMoment(moment, { kind: 'date', now: NaN })).not.toBeNull()
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
      '28 Mar 2026',
    ],
    [
      'Europe/Kyiv',
      '2026-03-28T21:59:00Z',
      '2026-03-29T21:01:00Z',
      '30 Mar 2026',
    ],
    ['Europe/Kyiv', '2026-03-28T22:01:00Z', '2026-03-29T20:59:00Z', 'today'],
    ['UTC', '0000-01-01T00:01:00Z', '-000001-12-31T23:59:00Z', 'yesterday'],
    ['UTC', '0001-01-01T00:01:00Z', '0000-12-31T23:59:00Z', 'yesterday'],
  ])(
    'compares local days in %s: reference %s, moment %s',
    (zone, reference, moment, expected) => {
      connection.choose(zone)
      expect(
        formatMoment(moment, {
          kind: 'calendar',
          locale: 'en-GB',
          now: Date.parse(reference),
        }),
      ).toBe(expected)
    },
  )

  it('uses the selected presentation locale with Gregorian Latin-digit day comparison', () => {
    connection.choose('Europe/Kyiv')
    const now = Date.parse('2026-01-01T22:01:00Z')
    expect(
      formatMoment('2026-01-01T21:59:00Z', {
        kind: 'calendar',
        locale: 'ar-SA',
        now,
      }),
    ).toBe(
      new Intl.RelativeTimeFormat('ar-SA', { numeric: 'auto' }).format(
        -1,
        'day',
      ),
    )
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
      expect(formatMoment(moment, { kind: 'calendar', locale: 'en-GB' })).toBe(
        offset > 0 ? 'today' : 'tomorrow',
      )
      expect(
        formatMoment(moment, {
          kind: 'calendar',
          locale: 'en-GB',
          now: localNow,
        }),
      ).toBe(offset > 0 ? 'tomorrow' : 'today')
    },
  )

  it('reads an explicit now in server scale without applying the offset again', () => {
    const now = Date.parse('2026-01-01T23:58:00Z')
    vi.spyOn(Date, 'now').mockReturnValue(now)
    applyServerTime(now + 300000)
    expect(
      formatMoment('2026-01-02T00:01:00Z', {
        kind: 'calendar',
        locale: 'en-GB',
        now,
      }),
    ).toBe('tomorrow')
  })

  it.each([-8640000000000000, 8640000000000000])(
    'labels the same day at Date range edge %s',
    (now) => {
      connection.choose('Europe/Kyiv')
      expect(
        formatMoment(now, { kind: 'calendar', locale: 'en-GB', now }),
      ).toBe('today')
    },
  )

  it('falls back to a medium date outside the three named days', () => {
    expect(
      formatMoment('2026-01-05T00:00:00Z', {
        kind: 'calendar',
        locale: 'en-GB',
        now: Date.parse('2026-01-01T00:00:00Z'),
      }),
    ).toBe('5 Jan 2026')
  })

  it('falls back to the requested date format without RelativeTimeFormat', () => {
    vi.stubGlobal('Intl', { DateTimeFormat: Intl.DateTimeFormat })
    const now = Date.parse('2026-01-01T00:00:00Z')
    expect(formatMoment(now, { kind: 'calendar', locale: 'en-GB', now })).toBe(
      '1 Jan 2026',
    )
    expect(
      formatMoment(now, {
        kind: 'calendar',
        locale: 'en-GB',
        now,
        format: { dateStyle: 'long' },
      }),
    ).toBe('1 January 2026')
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
  })

  it('rejects a Date object passed by an unsafe JavaScript caller', () => {
    expect(formatMoment(new Date() as unknown as number)).toBeNull()
  })

  it.each([NaN, Infinity, -Infinity, 8640000000000001, -8640000000000001])(
    'rejects an impossible calendar reference %s',
    (now) => {
      expect(formatMoment(0, { kind: 'calendar', now })).toBeNull()
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
    },
  )
})
