import { describe, expect, expectTypeOf, it } from 'vitest'
import {
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  DEFAULT_DATE_TIME_PICKER_LABELS,
  createIntlDateTimeZoneAdapter,
  type DateTimePickerFactors,
  type DateTimePickerLabels,
  type DateTimePickerValue,
} from '../../src/base/index.js'
import {
  fillDateTimePickerTemplate,
  resolveDateTimePickerFactors,
  resolveDateTimePickerLabels,
} from '../../src/primitives/dateTimePickerFactors.js'
import {
  validateDateTimePickerEndpointValue,
  validateDateTimePickerValue,
} from '../../src/primitives/dateTimePickerValue.js'

function expectDeeplyFrozen(value: object): void {
  expect(Object.isFrozen(value)).toBe(true)
  for (const child of Object.values(value)) {
    if (child !== null && typeof child === 'object') {
      expectDeeplyFrozen(child)
    }
  }
}

describe('DateTimePicker factors', () => {
  it('supplies literal English defaults without reading locale or time', () => {
    expect(DEFAULT_DATE_TIME_PICKER_FACTORS).toEqual({
      firstWeekday: 1,
      weekendDays: [0, 6],
      dateOrder: ['day', 'month', 'year'],
      dateSeparator: '/',
      hourCycle: 'h23',
      monthNames: [
        { short: 'Jan', full: 'January' },
        { short: 'Feb', full: 'February' },
        { short: 'Mar', full: 'March' },
        { short: 'Apr', full: 'April' },
        { short: 'May', full: 'May' },
        { short: 'Jun', full: 'June' },
        { short: 'Jul', full: 'July' },
        { short: 'Aug', full: 'August' },
        { short: 'Sep', full: 'September' },
        { short: 'Oct', full: 'October' },
        { short: 'Nov', full: 'November' },
        { short: 'Dec', full: 'December' },
      ],
      weekdayNames: [
        { short: 'Sun', full: 'Sunday' },
        { short: 'Mon', full: 'Monday' },
        { short: 'Tue', full: 'Tuesday' },
        { short: 'Wed', full: 'Wednesday' },
        { short: 'Thu', full: 'Thursday' },
        { short: 'Fri', full: 'Friday' },
        { short: 'Sat', full: 'Saturday' },
      ],
      placeholders: {
        year: 'yyyy',
        month: 'mm',
        day: 'dd',
        hour: '--',
        minute: '--',
        second: '--',
        meridiem: '--',
        offset: 'UTC±--',
      },
      amLabel: 'AM',
      pmLabel: 'PM',
    })
    expect(DEFAULT_DATE_TIME_PICKER_LABELS).toEqual({
      from: 'From',
      to: 'To',
      today: 'Today',
      done: 'Done',
      previousMonth: 'Previous month',
      nextMonth: 'Next month',
      chooseDate: 'Choose date',
      chooseTime: 'Choose time',
      chooseOffset: 'Choose UTC offset',
      year: 'Year',
      month: 'Month',
      day: 'Day',
      hour: 'Hours',
      minute: 'Minutes',
      second: 'Seconds',
      meridiem: 'AM/PM',
      offset: 'UTC offset',
      invalidDate: 'This date does not exist.',
      invalidTime: 'This time does not exist.',
      invalidZone: '{zone} is not a usable time zone.',
      gap: '{time} does not exist on {date} in {zone}: at {from} the clocks jump to {to}.',
      ambiguous: '{time} happens twice on {date} in {zone}. Which one?',
    })
  })

  it('deeply freezes both shared defaults', () => {
    expectDeeplyFrozen(DEFAULT_DATE_TIME_PICKER_FACTORS)
    expectDeeplyFrozen(DEFAULT_DATE_TIME_PICKER_LABELS)
    expect(
      Reflect.set(
        DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames[0],
        'full',
        'Changed',
      ),
    ).toBe(false)
    expect(
      Reflect.set(DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders, 'hour', 'hh'),
    ).toBe(false)
    expect(
      Reflect.set(DEFAULT_DATE_TIME_PICKER_LABELS, 'done', 'Changed'),
    ).toBe(false)
  })

  it('starts each resolution from defaults instead of accumulating inputs', () => {
    const first = resolveDateTimePickerFactors({
      firstWeekday: 0,
      hourCycle: 'h12',
      dateSeparator: '.',
      amLabel: 'Morning',
      pmLabel: 'Evening',
    })
    expect(first).toEqual({
      ...DEFAULT_DATE_TIME_PICKER_FACTORS,
      firstWeekday: 0,
      hourCycle: 'h12',
      dateSeparator: '.',
      amLabel: 'Morning',
      pmLabel: 'Evening',
    })
    expect(resolveDateTimePickerFactors({ firstWeekday: 6 })).toEqual({
      ...DEFAULT_DATE_TIME_PICKER_FACTORS,
      firstWeekday: 6,
    })
    expect(resolveDateTimePickerFactors()).toEqual(
      DEFAULT_DATE_TIME_PICKER_FACTORS,
    )
    expect(resolveDateTimePickerFactors({ hourCycle: undefined })).toEqual(
      DEFAULT_DATE_TIME_PICKER_FACTORS,
    )
    expect(first.hourCycle).toBe('h12')
  })

  it('replaces complete arrays and placeholders without retaining caller objects', () => {
    const monthNames = DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.map(
      (name) => ({
        short: `${name.short}!`,
        full: `${name.full}!`,
      }),
    )
    const weekdayNames = DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.map(
      (name) => ({ ...name }),
    )
    const weekendDays: DateTimePickerFactors['firstWeekday'][] = [5]
    const dateOrder: ['year', 'month', 'day'] = ['year', 'month', 'day']
    const placeholders = {
      year: 'Y',
      month: 'M',
      day: 'D',
      hour: 'H',
      minute: 'm',
      second: 's',
      meridiem: 'A',
      offset: 'Z',
    }
    const input = {
      monthNames,
      weekdayNames,
      weekendDays,
      dateOrder,
      placeholders,
    }
    const resolved = resolveDateTimePickerFactors(input)
    expect(resolved).toEqual({ ...DEFAULT_DATE_TIME_PICKER_FACTORS, ...input })
    monthNames[0].full = 'Changed'
    weekdayNames[0].short = 'Changed'
    weekendDays.push(6)
    dateOrder.reverse()
    placeholders.hour = 'Changed'
    expect(resolved.monthNames[0].full).toBe('January!')
    expect(resolved.weekdayNames[0].short).toBe('Sun')
    expect(resolved.weekendDays).toEqual([5])
    expect(resolved.dateOrder).toEqual(['year', 'month', 'day'])
    expect(resolved.placeholders.hour).toBe('H')
    expect(DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames[0].full).toBe('January')
    expect(DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders.hour).toBe('--')
    expect(
      resolveDateTimePickerFactors({ weekendDays: [] }).weekendDays,
    ).toEqual([])
    expect(resolveDateTimePickerFactors()).toEqual(
      DEFAULT_DATE_TIME_PICKER_FACTORS,
    )
  })

  const invalidFactors: [string, unknown][] = [
    [
      '11 months',
      { monthNames: DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.slice(1) },
    ],
    [
      '13 months',
      {
        monthNames: [
          ...DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames,
          { short: 'X', full: 'Extra' },
        ],
      },
    ],
    [
      '6 weekdays',
      { weekdayNames: DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.slice(1) },
    ],
    [
      '8 weekdays',
      {
        weekdayNames: [
          ...DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames,
          { short: 'X', full: 'Extra' },
        ],
      },
    ],
    ['duplicate date part', { dateOrder: ['day', 'day', 'year'] }],
    ['unknown date part', { dateOrder: ['day', 'month', 'hour'] }],
    ['missing date part', { dateOrder: ['day', 'month'] }],
    ['weekday 7', { firstWeekday: 7 }],
    ['weekday -1', { firstWeekday: -1 }],
    ['fractional weekday', { firstWeekday: 1.5 }],
    ['string weekday', { firstWeekday: '1' }],
    ['duplicate weekend day', { weekendDays: [0, 0] }],
    ['foreign weekend day', { weekendDays: [0, 7] }],
    ['negative weekend day', { weekendDays: [-1] }],
    ['fractional weekend day', { weekendDays: [1.5] }],
    ['sparse weekends', { weekendDays: new Array(2) }],
    ['unknown hour cycle', { hourCycle: 'h24' }],
    ['empty AM', { amLabel: '' }],
    ['empty PM', { pmLabel: '' }],
    ['null hour cycle', { hourCycle: null }],
    ['numeric separator', { dateSeparator: 1 }],
    ['partial placeholders', { placeholders: { hour: 'H' } }],
    [
      'missing offset placeholder',
      {
        placeholders: {
          ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
          offset: undefined,
        },
      },
    ],
    [
      'non-string placeholder',
      {
        placeholders: {
          ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
          minute: 1,
        },
      },
    ],
    [
      'empty short name',
      {
        monthNames: DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.map((name) => ({
          ...name,
          short: '',
        })),
      },
    ],
    [
      'empty full name',
      {
        weekdayNames: DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.map(
          (name) => ({ ...name, full: '' }),
        ),
      },
    ],
    ['sparse names', { monthNames: new Array(12) }],
    [
      'null name',
      {
        weekdayNames: [
          null,
          ...DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.slice(1),
        ],
      },
    ],
    ['null input', null],
    ['array input', []],
  ]
  it.each(invalidFactors)('rejects %s with RangeError', (_name, partial) => {
    expect(() =>
      resolveDateTimePickerFactors(partial as Partial<DateTimePickerFactors>),
    ).toThrow(RangeError)
  })

  it('completes labels afresh and ignores unknown keys at each configuration boundary', () => {
    const factors = resolveDateTimePickerFactors({
      firstWeekday: 0,
      unknown: 'ignored',
      placeholders: {
        ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
        extra: 'ignored',
      },
    } as Partial<DateTimePickerFactors>)
    expect(factors).not.toHaveProperty('unknown')
    expect(factors.placeholders).not.toHaveProperty('extra')
    const partial = { from: 'Starts', to: 'Ends', unknown: 'ignored' }
    const labels = resolveDateTimePickerLabels(partial)
    expect(labels).toEqual({
      ...DEFAULT_DATE_TIME_PICKER_LABELS,
      from: 'Starts',
      to: 'Ends',
    })
    expect(labels).not.toHaveProperty('unknown')
    partial.from = 'Changed'
    expect(labels.from).toBe('Starts')
    expect(resolveDateTimePickerLabels({ from: undefined })).toEqual(
      DEFAULT_DATE_TIME_PICKER_LABELS,
    )
    expect(resolveDateTimePickerLabels({ done: 'Close' })).toEqual({
      ...DEFAULT_DATE_TIME_PICKER_LABELS,
      done: 'Close',
    })
  })

  it.each([
    ['empty label', { done: '' }],
    ['numeric label', { year: 1 }],
    ['null label', { gap: null }],
    ['null input', null],
  ])('rejects %s', (_name, partial) => {
    expect(() =>
      resolveDateTimePickerLabels(partial as Partial<DateTimePickerLabels>),
    ).toThrow(RangeError)
  })

  it('fills all five template slots, preserving unknown or missing slots and literal replacement text', () => {
    expect(
      fillDateTimePickerTemplate(DEFAULT_DATE_TIME_PICKER_LABELS.gap, {
        time: '02:30',
        date: '29/03/2026',
        zone: 'Europe/Warsaw',
        from: '02:00',
        to: '03:00',
      }),
    ).toBe(
      '02:30 does not exist on 29/03/2026 in Europe/Warsaw: at 02:00 the clocks jump to 03:00.',
    )
    expect(
      fillDateTimePickerTemplate('{zone}: {time}, {time}; {unknown} {date}', {
        zone: '$&',
        time: '{from}',
      }),
    ).toBe('$&: {from}, {from}; {unknown} {date}')
    expect(
      fillDateTimePickerTemplate(DEFAULT_DATE_TIME_PICKER_LABELS.ambiguous, {
        time: '02:30',
        date: '25/10/2026',
        zone: 'Europe/Warsaw',
      }),
    ).toBe('02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?')
    expect(
      fillDateTimePickerTemplate(DEFAULT_DATE_TIME_PICKER_LABELS.invalidZone, {
        zone: 'Unknown/Zone',
      }),
    ).toBe('Unknown/Zone is not a usable time zone.')
  })
})

describe('DateTimePicker external values', () => {
  it('keeps the canonical types for all six kind/count combinations', () => {
    expectTypeOf<
      DateTimePickerValue<'date', 'single'>
    >().toEqualTypeOf<string>()
    expectTypeOf<
      DateTimePickerValue<'time', 'single'>
    >().toEqualTypeOf<string>()
    expectTypeOf<
      DateTimePickerValue<'datetime', 'single'>
    >().toEqualTypeOf<number>()
    expectTypeOf<DateTimePickerValue<'date', 'range'>>().toEqualTypeOf<
      readonly [string, string]
    >()
    expectTypeOf<DateTimePickerValue<'time', 'range'>>().toEqualTypeOf<
      readonly [string, string]
    >()
    expectTypeOf<DateTimePickerValue<'datetime', 'range'>>().toEqualTypeOf<
      readonly [number, number]
    >()
  })

  it('accepts strict dates/times, an explicit reset and unordered complete pairs', () => {
    for (const value of ['0001-01-01', '2000-02-29', '9999-12-31']) {
      expect(() =>
        validateDateTimePickerValue('date', 'single', value),
      ).not.toThrow()
    }
    for (const value of ['00:00:00', '23:59:59']) {
      expect(() =>
        validateDateTimePickerValue('time', 'single', value),
      ).not.toThrow()
    }
    expect(() =>
      validateDateTimePickerValue('date', 'range', null),
    ).not.toThrow()
    expect(() =>
      validateDateTimePickerValue('date', 'range', [
        '2026-10-21',
        '2026-10-17',
      ]),
    ).not.toThrow()
  })

  it.each([
    '2026-02-29',
    '0000-01-01',
    '2026-1-01',
    '2026-01-01\n',
    '',
    undefined,
    0,
  ])('rejects malformed dates %s', (value) => {
    expect(() => validateDateTimePickerValue('date', 'single', value)).toThrow(
      RangeError,
    )
  })

  it.each([
    '24:00:00',
    '23:60:00',
    '23:59:60',
    '1:00:00',
    '01:00',
    '01:00:00.001',
    '01:00:00Z',
    '01:00:00\n',
    ' 01:00:00',
  ])('rejects malformed clock times %s', (value) => {
    expect(() => validateDateTimePickerValue('time', 'single', value)).toThrow(
      RangeError,
    )
  })

  it.each([
    [],
    ['00:00:00'],
    ['00:00:00', null],
    ['00:00:00', ''],
    ['00:00:00', '01:00:00', '02:00:00'],
    new Array(2),
  ])('rejects incomplete range %s', (...value) => {
    expect(() => validateDateTimePickerValue('time', 'range', value)).toThrow(
      RangeError,
    )
  })

  it('uses adapter projection for moments, preserving zero, negative values and milliseconds', () => {
    const adapter = createIntlDateTimeZoneAdapter()
    for (const value of [0, -1, Date.parse('2026-10-25T01:30:45.123Z')]) {
      expect(() =>
        validateDateTimePickerEndpointValue(
          'datetime',
          value,
          adapter,
          'Europe/Warsaw',
        ),
      ).not.toThrow()
    }
    for (const value of [
      NaN,
      Infinity,
      1.5,
      Number.MAX_SAFE_INTEGER + 1,
      Date.parse('0000-12-31T00:00:00Z'),
      Date.parse('+010000-01-01T00:00:00Z'),
    ]) {
      expect(() =>
        validateDateTimePickerEndpointValue('datetime', value, adapter, 'UTC'),
      ).toThrow(RangeError)
    }
    expect(() =>
      validateDateTimePickerEndpointValue(
        'datetime',
        0,
        adapter,
        'Unknown/Zone',
      ),
    ).toThrow(RangeError)
    expect(() => validateDateTimePickerEndpointValue('datetime', 0)).toThrow(
      RangeError,
    )
  })
})
