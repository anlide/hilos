import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createDateTimePickerController,
  createIntlDateTimeZoneAdapter,
  createSignal,
  subscribeSignal,
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  type DateTimePickerController,
  type DateTimePickerKind,
  type DateTimePickerCount,
  type DateTimePickerOptions,
  type DateTimePickerPart,
  type DateTimeZoneAdapter,
} from '../../src/base/index.js'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

const today = () => '2026-10-13'
function datePicker(value: string | null = null) {
  return createDateTimePickerController({
    kind: 'date',
    count: 'single',
    value,
    today,
  })
}
function timePicker(value: string | null = null, showSeconds = false) {
  return createDateTimePickerController({
    kind: 'time',
    count: 'single',
    value,
    showSeconds,
  })
}
function momentPicker(
  value: number | null = null,
  timeZone = 'Europe/Warsaw',
  showSeconds = false,
) {
  return createDateTimePickerController({
    kind: 'datetime',
    count: 'single',
    value,
    timeZone,
    today,
    showSeconds,
  })
}
function part<K extends DateTimePickerKind, C extends DateTimePickerCount>(
  picker: DateTimePickerController<K, C>,
  name: DateTimePickerPart,
  edge: 'start' | 'end' = 'start',
) {
  return picker.state
    .get()
    [edge]?.parts.find((candidate) => candidate.part === name)
}
function enterMoment(
  picker: DateTimePickerController<'datetime', 'single'>,
  date: string,
  hour: string,
  minute: string,
) {
  picker.setDate('start', date)
  picker.setPartText('start', 'hour', hour)
  picker.setPartText('start', 'minute', minute)
}

describe('DateTimePicker canonical values and configuration', () => {
  it('supports all six kind/count combinations and leaves single.end null', () => {
    const date = datePicker('2026-10-17')
    const time = timePicker('06:00:00')
    const moment = momentPicker(1792209600000)
    expect(date.state.get().result).toEqual({
      status: 'valid',
      value: '2026-10-17',
    })
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '06:00:00',
    })
    expect(moment.state.get().result).toEqual({
      status: 'valid',
      value: 1792209600000,
    })
    expect(moment.state.get().start.parts.map(({ text }) => text)).toEqual([
      '17',
      '10',
      '2026',
      '06',
      '00',
    ])
    for (const picker of [date, time, moment])
      expect(picker.state.get().end).toBeNull()
    const dates = createDateTimePickerController({
      kind: 'date',
      count: 'range',
      value: ['2026-10-17', '2026-10-21'],
      today,
    })
    const times = createDateTimePickerController({
      kind: 'time',
      count: 'range',
      value: ['06:00:00', '07:00:00'],
    })
    const moments = createDateTimePickerController({
      kind: 'datetime',
      count: 'range',
      value: [1792209600000, 1792213200000],
      timeZone: 'Europe/Warsaw',
      today,
    })
    expect(dates.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-17', '2026-10-21'],
    })
    expect(times.state.get().result).toEqual({
      status: 'valid',
      value: ['06:00:00', '07:00:00'],
    })
    expect(moments.state.get().result).toEqual({
      status: 'valid',
      value: [1792209600000, 1792213200000],
    })
    expect(moments.state.get().end?.result).toEqual({
      status: 'valid',
      value: 1792213200000,
    })
  })

  it('rejects malformed external values atomically, including the second range edge', () => {
    const date = datePicker('2026-10-17'),
      time = timePicker('06:00:00'),
      moment = momentPicker(1792209600000)
    for (const bad of ['2026-02-31', '2026-2-01', '', '0000-01-01']) {
      const snapshot = date.state.get()
      expect(() => date.setValue(bad)).toThrow(RangeError)
      expect(() => date.setEndpointValue('start', bad)).toThrow(RangeError)
      expect(date.state.get()).toBe(snapshot)
    }
    for (const bad of [
      '24:00:00',
      '6:00:00',
      '06:60:00',
      '06:00',
      '06:00:00\n',
    ]) {
      const snapshot = time.state.get()
      expect(() => time.setValue(bad)).toThrow(RangeError)
      expect(time.state.get()).toBe(snapshot)
    }
    for (const bad of [NaN, Infinity, 1.5, Number.MAX_SAFE_INTEGER]) {
      const snapshot = moment.state.get()
      expect(() => moment.setValue(bad)).toThrow(RangeError)
      expect(moment.state.get()).toBe(snapshot)
    }
    const range = createDateTimePickerController({
      kind: 'date',
      count: 'range',
      value: ['2026-10-17', '2026-10-21'],
      today,
    })
    const snapshot = range.state.get()
    expect(() => range.setValue(['2026-11-01', '2026-02-31'])).toThrow(
      RangeError,
    )
    expect(() =>
      range.setValue(['2026-11-01'] as unknown as readonly [string, string]),
    ).toThrow(RangeError)
    expect(range.state.get()).toBe(snapshot)
    expect(() => moment.setEndpointValue('end', null)).toThrow(RangeError)
    expect(() => date.setPartText('end', 'day', '01')).toThrow(RangeError)
  })

  it.each([
    { kind: 'other', count: 'single', value: null },
    { kind: 'time', count: 'many', value: null },
    { kind: 'date', count: 'single', value: null },
    { kind: 'datetime', count: 'single', value: null, today },
    { kind: 'datetime', count: 'single', value: null, today, timeZone: '' },
    { kind: 'datetime', count: 'single', value: null, today, timeZone: ' ' },
    {
      kind: 'date',
      count: 'single',
      value: null,
      today,
      minDate: '2026-02-31',
    },
    { kind: 'date', count: 'single', value: null, today, timeZone: 'UTC' },
    { kind: 'time', count: 'single', value: null, today },
    { kind: 'time', count: 'single', value: null, minDate: '2026-01-01' },
    {
      kind: 'time',
      count: 'single',
      value: null,
      adapter: createIntlDateTimeZoneAdapter(),
    },
    { kind: 'time', count: 'single', value: null, showSeconds: 'yes' },
    { kind: 'datetime', count: 'single', value: 1.5, timeZone: 'UTC', today },
    {
      kind: 'datetime',
      count: 'single',
      value: null,
      timeZone: 'UTC',
      today,
      adapter: {},
    },
  ])('rejects invalid options %#', (options) => {
    expect(() =>
      createDateTimePickerController(
        options as unknown as DateTimePickerOptions<
          DateTimePickerKind,
          DateTimePickerCount
        >,
      ),
    ).toThrow(RangeError)
  })

  it('allows typing before minDate and accepts zero and negative epochs', () => {
    const picker = createDateTimePickerController({
      kind: 'date',
      count: 'single',
      value: null,
      today,
      minDate: '2026-10-13',
    })
    picker.setDate('start', '0001-01-01')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '0001-01-01',
    })
    expect(momentPicker(0, 'UTC').state.get().result).toEqual({
      status: 'valid',
      value: 0,
    })
    expect(momentPicker(-1, 'UTC').state.get().result).toEqual({
      status: 'valid',
      value: -1,
    })
  })
})

describe('numeric input and part lifetime', () => {
  it('appends, restarts overflow, pads completed parts and reports advancement', () => {
    const picker = datePicker()
    expect(picker.inputDigit('start', 'month', '1')).toBe(false)
    expect(part(picker, 'month')?.text).toBe('1')
    expect(part(picker, 'month')?.valueNow).toBe(1)
    expect(picker.inputDigit('start', 'month', '5')).toBe(true)
    expect(part(picker, 'month')?.text).toBe('05')
    expect(picker.inputDigit('start', 'month', '4')).toBe(true)
    expect(part(picker, 'month')?.text).toBe('04')
    picker.setPartText('start', 'year', '')
    for (const digit of ['2', '0', '2'])
      expect(picker.inputDigit('start', 'year', digit)).toBe(false)
    expect(part(picker, 'year')?.valueNow).toBeNull()
    expect(picker.inputDigit('start', 'year', '6')).toBe(true)
    expect(part(picker, 'year')?.valueNow).toBe(2026)
    picker.setPartText('start', 'day', '0')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    picker.setPartText('start', 'day', '1')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '2026-04-01',
    })
    const time = timePicker()
    expect(time.inputDigit('start', 'minute', '7')).toBe(true)
    expect(part(time, 'minute')?.text).toBe('07')
    time.setFactors({ hourCycle: 'h12' })
    expect(time.inputDigit('start', 'hour', '2')).toBe(true)
    expect(part(time, 'hour')?.text).toBe('02')
    time.setPartText('start', 'hour', '0')
    expect(part(time, 'hour')?.valueNow).toBeNull()
    expect(time.state.get().start.result.status).toBe('incomplete')
  })

  it('reads small years only at four digits and pads on leaving without onChange', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'date',
      count: 'single',
      value: '2026-01-01',
      today,
      onChange,
    })
    picker.setPartText('start', 'year', '202')
    expect(picker.state.get().start.result.status).toBe('incomplete')
    expect(part(picker, 'year')?.valueNow).toBeNull()
    picker.setPartText('start', 'year', '0099')
    picker.setPartText('start', 'month', '1')
    const calls = onChange.mock.calls.length
    picker.leavePart('start', 'month')
    expect(part(picker, 'month')?.text).toBe('01')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '0099-01-01',
    })
    expect(onChange).toHaveBeenCalledTimes(calls)
  })

  it('separates bad call text from invalid numeric drafts and never exposes a stale value', () => {
    const picker = datePicker('2026-01-31')
    for (const bad of ['abc', '１２', '123', '-1', '1\n']) {
      const snapshot = picker.state.get()
      expect(() => picker.setPartText('start', 'month', bad)).toThrow(
        RangeError,
      )
      expect(picker.state.get()).toBe(snapshot)
    }
    for (const bad of ['', '12', 'a', '１', '\n'])
      expect(() => picker.inputDigit('start', 'day', bad)).toThrow(RangeError)
    picker.setPartText('start', 'month', '13')
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'date',
    })
    expect(picker.state.get().start.reason).toBe('This date does not exist.')
    expect(picker.state.get().result).toEqual({ status: 'unresolved' })
    expect(part(picker, 'month')?.valueNow).toBeNull()
    expect(part(picker, 'month')?.valueText).toBe('13')
    picker.clearPart('start', 'year')
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'date',
    })
    picker.setPartText('start', 'month', '02')
    picker.setPartText('start', 'year', '2026')
    expect(part(picker, 'day')?.text).toBe('31')
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'date',
    })
    const time = timePicker('06:00:00')
    time.setPartText('start', 'minute', '60')
    expect(time.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'time',
    })
    expect(time.state.get().start.reason).toBe('This time does not exist.')
    expect(() => time.setDate('start', '2026-10-17')).toThrow(RangeError)
    expect(() => time.clearPart('start', 'day')).toThrow(RangeError)
    expect(() => picker.stepPart('start', 'hour', 1)).toThrow(RangeError)
  })

  it('ignores currently hidden parts and validates meridiem when present', () => {
    const picker = timePicker('06:00:42')
    const snapshot = picker.state.get()
    picker.setPartText('start', 'second', '15')
    picker.inputDigit('start', 'second', '1')
    picker.clearPart('start', 'second')
    picker.stepPart('start', 'second', 1)
    picker.leavePart('start', 'second')
    picker.setMeridiem('start', 'pm')
    picker.clearPart('start', 'meridiem')
    expect(picker.state.get()).toBe(snapshot)
    picker.setFactors({ hourCycle: 'h12' })
    expect(() => picker.setMeridiem('start', 'other' as 'am')).toThrow(
      RangeError,
    )
  })
})

describe('stepping, clearing and overall results', () => {
  it('wraps only the selected part and respects the month length', () => {
    const time = timePicker('23:59:59', true)
    time.stepPart('start', 'second', 1)
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '23:59:00',
    })
    time.stepPart('start', 'minute', 1)
    time.stepPart('start', 'hour', 1)
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '00:00:00',
    })
    time.stepPart('start', 'hour', -1)
    expect(part(time, 'hour')?.text).toBe('23')
    const date = datePicker('2024-02-29')
    date.stepPart('start', 'day', 1)
    expect(date.state.get().result).toEqual({
      status: 'valid',
      value: '2024-02-01',
    })
    date.setValue('2026-02-01')
    date.stepPart('start', 'day', -1)
    expect(date.state.get().result).toEqual({
      status: 'valid',
      value: '2026-02-28',
    })
    date.setValue('2026-12-31')
    date.stepPart('start', 'month', 1)
    expect(date.state.get().result).toEqual({
      status: 'valid',
      value: '2026-01-31',
    })
  })

  it('starts empty parts from their bounds and calls today only for an empty year', () => {
    const getToday = vi.fn(today)
    const date = createDateTimePickerController({
      kind: 'date',
      count: 'single',
      value: null,
      today: getToday,
    })
    date.stepPart('start', 'month', -1)
    date.stepPart('start', 'day', 1)
    expect(part(date, 'month')?.text).toBe('12')
    expect(part(date, 'day')?.text).toBe('01')
    expect(getToday).not.toHaveBeenCalled()
    date.stepPart('start', 'year', -1)
    expect(part(date, 'year')?.text).toBe('2026')
    expect(getToday).toHaveBeenCalledTimes(1)
    date.stepPart('start', 'year', 1)
    expect(part(date, 'year')?.text).toBe('2027')
    expect(getToday).toHaveBeenCalledTimes(1)
    const time = timePicker(null, true)
    time.stepPart('start', 'minute', -1)
    time.stepPart('start', 'hour', 1)
    time.stepPart('start', 'second', 1)
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '00:59:00',
    })
    time.setFactors({ hourCycle: 'h12' })
    time.stepPart('start', 'hour', 1)
    expect(part(time, 'hour')?.text).toBe('01')
    expect(part(time, 'meridiem')?.text).toBe('AM')
    time.stepPart('start', 'meridiem', -1)
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '13:59:00',
    })
    time.clearPart('start', 'meridiem')
    time.stepPart('start', 'meridiem', -1)
    expect(part(time, 'meridiem')?.text).toBe('PM')
    time.clearPart('start', 'meridiem')
    time.stepPart('start', 'meridiem', 1)
    expect(part(time, 'meridiem')?.text).toBe('AM')
  })

  it('clears a whole endpoint, forgetting its hidden seconds and milliseconds', () => {
    const picker = momentPicker(Date.parse('2026-10-17T04:00:42.123Z'))
    picker.clearPart('start', 'minute')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    expect(picker.state.get().result).not.toHaveProperty('value')
    for (const name of ['day', 'month', 'year', 'hour'] as const)
      picker.clearPart('start', name)
    expect(picker.state.get().result).toEqual({ status: 'empty' })
    enterMoment(picker, '2026-10-17', '06', '00')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: 1792209600000,
    })
  })

  it('keeps range endpoints independent, never sorts, and omits unresolved values', () => {
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'range',
      value: null,
    })
    expect(picker.state.get().result).toEqual({ status: 'empty' })
    picker.setEndpointValue('start', '07:00:00')
    expect(picker.state.get().result).toEqual({ status: 'unresolved' })
    expect(picker.state.get().end?.result).toEqual({ status: 'empty' })
    picker.setEndpointValue('end', '06:00:00')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['07:00:00', '06:00:00'],
    })
    picker.clearPart('start', 'hour')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    const start = picker.state.get().start
    picker.setEndpointValue('end', '08:00:00')
    expect(picker.state.get().start).toEqual(start)
    expect(picker.state.get().result).toEqual({ status: 'unresolved' })
    expect(picker.state.get().start.result).not.toHaveProperty('value')
  })
})

describe('preserved precision and display factors', () => {
  it('keeps an empty endpoint empty when seconds become visible', () => {
    for (const picker of [timePicker(), momentPicker()]) {
      picker.setShowSeconds(true)
      expect(part(picker, 'second')?.text).toBe('')
      expect(picker.state.get().result).toEqual({ status: 'empty' })
      picker.setShowSeconds(false)
      expect(picker.state.get().result).toEqual({ status: 'empty' })
      picker.setPartText('start', 'hour', '06')
      picker.setPartText('start', 'minute', '00')
      picker.setShowSeconds(true)
      expect(part(picker, 'second')?.text).toBe('00')
    }
  })

  it('preserves every unedited field, including hidden seconds and fractions', () => {
    const value = Date.parse('2026-10-17T04:00:42.123Z')
    const picker = momentPicker(value)
    expect(picker.state.get().result).toEqual({ status: 'valid', value })
    picker.setPartText('start', 'minute', '10')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-17T04:10:42.123Z'),
    })
    picker.setValue(value)
    picker.setShowSeconds(true)
    expect(part(picker, 'second')?.text).toBe('42')
    picker.setPartText('start', 'second', '15')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-17T04:00:15.123Z'),
    })
    picker.setShowSeconds(false)
    picker.setShowSeconds(true)
    expect(part(picker, 'second')?.text).toBe('15')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-17T04:00:15.123Z'),
    })
    const fresh = momentPicker()
    enterMoment(fresh, '2026-10-17', '06', '00')
    expect(fresh.state.get().result).toEqual({
      status: 'valid',
      value: 1792209600000,
    })
    const clock = timePicker('06:00:42')
    clock.setPartText('start', 'minute', '10')
    expect(clock.state.get().result).toEqual({
      status: 'valid',
      value: '06:10:42',
    })
  })

  it('keeps hidden incomplete and invalid seconds unresolved', () => {
    const picker = timePicker('06:00:42', true)
    picker.clearPart('start', 'second')
    picker.setShowSeconds(false)
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    expect(picker.state.get().result).not.toHaveProperty('value')
    picker.setShowSeconds(true)
    picker.setPartText('start', 'second', '99')
    picker.setShowSeconds(false)
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'time',
    })
    picker.setValue('06:00:00')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '06:00:00',
    })
  })

  it('translates h23/h12 meaning and changes factors without editing other raw input', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: Date.parse('2026-10-17T11:00:42.123Z'),
      today,
      timeZone: 'Europe/Warsaw',
      onChange,
    })
    picker.setPartText('start', 'minute', '1')
    const calls = onChange.mock.calls.length
    picker.setFactors({
      dateOrder: ['month', 'day', 'year'],
      hourCycle: 'h12',
      amLabel: 'Morning',
      pmLabel: 'Evening',
    })
    expect(picker.state.get().start.parts.map(({ part }) => part)).toEqual([
      'month',
      'day',
      'year',
      'hour',
      'minute',
      'meridiem',
    ])
    expect(part(picker, 'hour')?.text).toBe('01')
    expect(part(picker, 'minute')?.text).toBe('1')
    expect(part(picker, 'meridiem')?.text).toBe('Evening')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-17T11:01:42.123Z'),
    })
    picker.setFactors({
      monthNames: DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.map((name) => ({
        ...name,
        full: `Month ${name.full}`,
      })),
    })
    expect(part(picker, 'hour')?.text).toBe('13')
    expect(part(picker, 'month')?.valueText).toBe('Month October')
    expect(picker.state.get().start.parts[0]?.part).toBe('day')
    expect(onChange).toHaveBeenCalledTimes(calls)
    const snapshot = picker.state.get()
    expect(() => picker.setFactors({ hourCycle: 'other' as 'h12' })).toThrow(
      RangeError,
    )
    expect(picker.state.get()).toBe(snapshot)
  })

  it('restores an unresolved h12 hour without guessing meridiem', () => {
    const picker = timePicker('13:00:00')
    picker.setFactors({ hourCycle: 'h12' })
    picker.clearPart('start', 'meridiem')
    picker.setPartText('start', 'hour', '1')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    picker.setFactors({ hourCycle: 'h23' })
    expect(part(picker, 'hour')?.text).toBe('')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    picker.setPartText('start', 'minute', '10')
    picker.setFactors({ hourCycle: 'h12' })
    expect(part(picker, 'hour')?.text).toBe('1')
    expect(part(picker, 'meridiem')?.text).toBe('')
    expect(part(picker, 'minute')?.text).toBe('10')
    expect(picker.state.get().result).toEqual({ status: 'unresolved' })
    picker.setFactors({ hourCycle: 'h23' })
    picker.setPartText('start', 'hour', '14')
    picker.setFactors({ hourCycle: 'h12' })
    expect(part(picker, 'hour')?.text).toBe('02')
    expect(part(picker, 'meridiem')?.text).toBe('PM')
    picker.setPartText('start', 'hour', '99')
    picker.setFactors({ hourCycle: 'h23' })
    expect(part(picker, 'hour')?.text).toBe('99')
    picker.setValue('13:00:00')
    picker.setFactors({ hourCycle: 'h12' })
    picker.clearPart('start', 'meridiem')
    picker.setPartText('start', 'hour', '0')
    picker.setFactors({ hourCycle: 'h23' })
    expect(part(picker, 'hour')?.text).toBe('')
    expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
    picker.setFactors({ hourCycle: 'h12' })
    expect(part(picker, 'hour')?.text).toBe('0')
    expect(part(picker, 'meridiem')?.text).toBe('')
  })

  it.each([
    { value: null, second: '00' },
    { value: '13:00:42', second: '42' },
  ])(
    'keeps a deferred h12 hour and hidden seconds after no-op text edits from $value',
    ({ value, second }) => {
      const onChange = vi.fn()
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value,
        factors: { hourCycle: 'h12' },
        onChange,
      })
      picker.clearPart('start', 'minute')
      picker.clearPart('start', 'meridiem')
      picker.setPartText('start', 'hour', '1')
      picker.setFactors({ hourCycle: 'h23' })
      const snapshot = picker.state.get()
      const publications = vi.fn()
      const stop = subscribeSignal(picker.state, publications)
      onChange.mockClear()
      picker.clearPart('start', 'minute')
      expect(picker.state.get()).toBe(snapshot)
      picker.setPartText('start', 'minute', '')
      expect(picker.state.get()).toBe(snapshot)
      expect(publications).not.toHaveBeenCalled()
      expect(onChange).not.toHaveBeenCalled()
      picker.setFactors({ hourCycle: 'h12' })
      expect(part(picker, 'hour')?.text).toBe('1')
      expect(part(picker, 'meridiem')?.text).toBe('')
      expect(part(picker, 'minute')?.text).toBe('')
      expect(picker.state.get().start.result).toEqual({ status: 'incomplete' })
      picker.setShowSeconds(true)
      expect(part(picker, 'second')?.text).toBe(second)
      expect(onChange).not.toHaveBeenCalled()
      stop()
    },
  )

  it('keeps hidden seconds when clearing an already-empty meridiem', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: '13:00:42',
      factors: { hourCycle: 'h12' },
      showSeconds: true,
      onChange,
    })
    picker.clearPart('start', 'hour')
    picker.clearPart('start', 'minute')
    picker.clearPart('start', 'meridiem')
    picker.setShowSeconds(false)
    const snapshot = picker.state.get()
    const publications = vi.fn()
    const stop = subscribeSignal(picker.state, publications)
    onChange.mockClear()

    picker.clearPart('start', 'meridiem')

    expect(picker.state.get()).toBe(snapshot)
    expect(publications).not.toHaveBeenCalled()
    expect(onChange).not.toHaveBeenCalled()
    picker.setShowSeconds(true)
    expect(part(picker, 'second')?.text).toBe('42')
    stop()
  })

  it('resets hidden seconds when clearing the last visible meridiem', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: '13:00:42',
      factors: { hourCycle: 'h12' },
      onChange,
    })
    picker.clearPart('start', 'hour')
    picker.clearPart('start', 'minute')
    const snapshot = picker.state.get()
    onChange.mockClear()

    picker.clearPart('start', 'meridiem')

    expect(picker.state.get()).not.toBe(snapshot)
    expect(picker.state.get().start.result).toEqual({ status: 'empty' })
    expect(onChange).toHaveBeenCalledExactlyOnceWith(picker.state.get())
    picker.setShowSeconds(true)
    expect(part(picker, 'second')?.text).toBe('')
  })

  it('resets precision and fold selection when the last filled visible part is cleared', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: Date.parse('2026-10-25T01:30:42.123Z'),
      today,
      timeZone: 'Europe/Warsaw',
      factors: { hourCycle: 'h12' },
      onChange,
    })
    for (const name of ['day', 'month', 'year', 'minute', 'meridiem'] as const)
      picker.clearPart('start', name)
    const snapshot = picker.state.get()
    onChange.mockClear()
    picker.clearPart('start', 'hour')
    expect(picker.state.get()).not.toBe(snapshot)
    expect(picker.state.get().result).toEqual({ status: 'empty' })
    expect(
      picker.state.get().start.parts.every(({ text }) => text === ''),
    ).toBe(true)
    expect(picker.state.get().start.offsetChoices).toEqual([])
    expect(onChange).toHaveBeenCalledExactlyOnceWith(picker.state.get())
    enterMoment(picker, '2026-10-25', '02', '30')
    picker.setMeridiem('start', 'am')
    expect(picker.state.get().start.result.status).toBe('ambiguous')
    picker.setShowSeconds(true)
    expect(part(picker, 'second')?.text).toBe('00')
    picker.selectOffset('start', Date.parse('2026-10-25T01:30:00Z'))
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-25T01:30:00Z'),
    })
  })

  it('setDate changes only calendar parts and leaves a new datetime incomplete', () => {
    const picker = momentPicker(Date.parse('2026-10-17T04:00:42.123Z'))
    picker.setDate('start', '2026-10-18')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-18T04:00:42.123Z'),
    })
    const fresh = momentPicker()
    fresh.setDate('start', '2026-10-17')
    expect(fresh.state.get().start.result).toEqual({ status: 'incomplete' })
    expect(part(fresh, 'hour')?.text).toBe('')
    expect(part(fresh, 'minute')?.text).toBe('')
    const snapshot = fresh.state.get()
    expect(() => fresh.setDate('start', '2026-02-31')).toThrow(RangeError)
    expect(fresh.state.get()).toBe(snapshot)
  })
})

describe('explicit-zone gaps and folds', () => {
  it('describes a Warsaw gap and steps over it in both directions without changing precision', () => {
    const picker = momentPicker()
    enterMoment(picker, '2026-03-29', '02', '30')
    expect(picker.state.get().start.result).toEqual({ status: 'gap' })
    expect(picker.state.get().result).toEqual({ status: 'unresolved' })
    expect(picker.state.get().start.reason).toBe(
      '02:30 does not exist on 29/03/2026 in Europe/Warsaw: at 02:00 the clocks jump to 03:00.',
    )
    picker.setValue(Date.parse('2026-03-29T00:30:42.123Z'))
    picker.stepPart('start', 'hour', 1)
    expect(part(picker, 'hour')?.text).toBe('03')
    expect(part(picker, 'minute')?.text).toBe('30')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-03-29T01:30:42.123Z'),
    })
    picker.stepPart('start', 'hour', -1)
    expect(part(picker, 'hour')?.text).toBe('01')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-03-29T00:30:42.123Z'),
    })
    picker.setPartText('start', 'hour', '02')
    picker.stepPart('start', 'minute', 1)
    expect(picker.state.get().start.result).toEqual({ status: 'gap' })
    expect(part(picker, 'hour')?.text).toBe('02')
  })

  it('tries another hour after a half-hour gap and never skips an entire date', () => {
    const halfHour = momentPicker(null, 'Australia/Lord_Howe')
    enterMoment(halfHour, '2026-10-04', '01', '15')
    halfHour.stepPart('start', 'hour', 1)
    expect(part(halfHour, 'hour')?.text).toBe('03')
    expect(halfHour.state.get().result).toEqual({
      status: 'valid',
      value: Date.parse('2026-10-03T16:15:00Z'),
    })
    const skippedDate = momentPicker(null, 'Pacific/Apia')
    enterMoment(skippedDate, '2011-12-30', '01', '30')
    skippedDate.stepPart('start', 'hour', 1)
    expect(part(skippedDate, 'hour')?.text).toBe('02')
    expect(part(skippedDate, 'day')?.text).toBe('30')
    expect(skippedDate.state.get().start.result).toEqual({ status: 'gap' })
    expect(skippedDate.state.get().start.reason).toBe(
      '02:30 does not exist on 30/12/2011 in Pacific/Apia: at 00:00 the clocks jump to 31/12/2011, 00:00.',
    )
    skippedDate.stepPart('start', 'hour', -1)
    expect(part(skippedDate, 'hour')?.text).toBe('01')
    expect(skippedDate.state.get().start.result).toEqual({ status: 'gap' })
  })

  it('requires a fold occurrence, cycles offsets, ignores stale choices, and invalidates a changed coordinate', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: null,
      today,
      timeZone: 'Europe/Warsaw',
      onChange,
    })
    enterMoment(picker, '2026-10-25', '02', '30')
    const early = Date.parse('2026-10-25T00:30:00Z'),
      late = Date.parse('2026-10-25T01:30:00Z')
    expect(picker.state.get().start.result).toEqual({
      status: 'ambiguous',
      candidates: [
        { epochMs: early, offsetSeconds: 7200 },
        { epochMs: late, offsetSeconds: 3600 },
      ],
    })
    expect(picker.state.get().start.reason).toBe(
      '02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?',
    )
    expect(part(picker, 'offset')?.text).toBe('')
    expect(part(picker, 'offset')?.valueNow).toBeNull()
    expect(picker.state.get().start.offsetChoices).toEqual([
      {
        epochMs: early,
        offsetSeconds: 7200,
        text: 'UTC+02:00',
        selected: false,
      },
      {
        epochMs: late,
        offsetSeconds: 3600,
        text: 'UTC+01:00',
        selected: false,
      },
    ])
    picker.selectOffset('start', late)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: late })
    expect(part(picker, 'offset')).toMatchObject({
      text: 'UTC+01:00',
      prefix: ' ',
      placeholder: 'UTC±--',
      label: 'UTC offset',
      valueNow: 1,
      valueMin: 0,
      valueMax: 1,
      valueText: 'UTC+01:00',
    })
    const snapshot = picker.state.get(),
      calls = onChange.mock.calls.length
    picker.selectOffset('start', late + 1)
    expect(picker.state.get()).toBe(snapshot)
    expect(onChange).toHaveBeenCalledTimes(calls)
    picker.stepPart('start', 'offset', 1)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: early })
    picker.stepPart('start', 'offset', -1)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: late })
    picker.clearPart('start', 'offset')
    expect(picker.state.get().start.result.status).toBe('ambiguous')
    picker.stepPart('start', 'offset', 1)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: early })
    picker.clearPart('start', 'offset')
    picker.stepPart('start', 'offset', -1)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: late })
    picker.setFactors({ hourCycle: 'h12', dateOrder: ['month', 'day', 'year'] })
    picker.setShowSeconds(true)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: late })
    expect(part(picker, 'offset')?.text).toBe('UTC+01:00')
    picker.setPartText('start', 'minute', '31')
    expect(picker.state.get().start.result.status).toBe('ambiguous')
    expect(part(picker, 'offset')?.text).toBe('')
    expect(picker.state.get().result).not.toHaveProperty('value')
    picker.selectOffset('start', late)
    expect(picker.state.get().start.result.status).toBe('ambiguous')
    picker.setPartText('start', 'hour', '03')
    expect(part(picker, 'offset')).toBeUndefined()
    expect(picker.state.get().start.offsetChoices).toEqual([])
  })

  it('retains a loaded fold occurrence and does not resolve it again on presentation changes', () => {
    const real = createIntlDateTimeZoneAdapter()
    const resolve = vi.fn(real.resolve)
    const value = Date.parse('2026-10-25T01:30:42.123Z')
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value,
      today,
      timeZone: 'Europe/Warsaw',
      adapter: { resolve, project: real.project },
    })
    expect(part(picker, 'offset')?.text).toBe('UTC+01:00')
    resolve.mockClear()
    picker.setFactors({ hourCycle: 'h12' })
    picker.setShowSeconds(true)
    picker.setShowSeconds(false)
    picker.leavePart('start', 'minute')
    picker.setPartText('start', 'minute', '30')
    expect(picker.state.get().result).toEqual({ status: 'valid', value })
    expect(resolve).not.toHaveBeenCalled()
    const early = Date.parse('2026-10-25T00:30:42.123Z')
    picker.selectOffset('start', early)
    expect(picker.state.get().result).toEqual({ status: 'valid', value: early })
    expect(part(picker, 'offset')?.text).toBe('UTC+02:00')
    picker.clearPart('start', 'offset')
    expect(picker.state.get().start.result.status).toBe('ambiguous')
  })

  it('reports an unusable zone and uses the supplied adapter', () => {
    const picker = momentPicker(null, 'Mars/Olympus')
    enterMoment(picker, '2026-10-17', '06', '00')
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(picker.state.get().start.reason).toBe(
      'Mars/Olympus is not a usable time zone.',
    )
    expect(() => momentPicker(1792209600000, 'Mars/Olympus')).toThrow(
      RangeError,
    )
    const snapshot = picker.state.get()
    expect(() => picker.setValue(1792209600000)).toThrow(RangeError)
    expect(picker.state.get()).toBe(snapshot)
    const real = createIntlDateTimeZoneAdapter()
    const adapter: DateTimeZoneAdapter = {
      project: vi.fn((epoch) => real.project(epoch, 'UTC')),
      resolve: vi.fn((parts) => real.resolve(parts, 'UTC')),
    }
    const custom = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: 0,
      today,
      timeZone: 'Synthetic/Zone',
      adapter,
    })
    custom.setPartText('start', 'minute', '01')
    expect(custom.state.get().result).toEqual({ status: 'valid', value: 60000 })
    expect(adapter.project).toHaveBeenCalledWith(0, 'Synthetic/Zone')
    expect(adapter.resolve).toHaveBeenLastCalledWith(
      {
        year: 1970,
        month: 1,
        day: 1,
        hour: 0,
        minute: 1,
        second: 0,
        millisecond: 0,
      },
      'Synthetic/Zone',
    )
  })

  it('fills invalid-zone date and time slots without resolving the zone', () => {
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: null,
      today,
      timeZone: 'Mars/Olympus',
      labels: { invalidZone: '{date} {time} {zone}' },
    })
    enterMoment(picker, '2026-10-17', '06', '00')
    expect(picker.state.get().start.result).toEqual({
      status: 'invalid',
      reason: 'zone',
    })
    expect(picker.state.get().start.reason).toBe(
      '17/10/2026 06:00 Mars/Olympus',
    )
    picker.setFactors({
      dateOrder: ['month', 'day', 'year'],
      dateSeparator: '-',
      hourCycle: 'h12',
      amLabel: 'Morning',
    })
    picker.setShowSeconds(true)
    expect(picker.state.get().start.reason).toBe(
      '10-17-2026 06:00:00 Morning Mars/Olympus',
    )
  })

  it.each([
    {
      part: 'day',
      text: '32',
      reason: 'date',
      expected: '10-32-2026|06:00:15 Evening',
    },
    {
      part: 'hour',
      text: '99',
      reason: 'time',
      expected: '10-17-2026|99:00:15 Evening',
    },
  ] as const)(
    'fills available slots for invalid $reason with the current factors',
    ({ part, text, reason, expected }) => {
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'single',
        value: null,
        today,
        timeZone: 'Mars/Olympus',
        showSeconds: true,
        factors: {
          dateOrder: ['month', 'day', 'year'],
          dateSeparator: '-',
          hourCycle: 'h12',
          pmLabel: 'Evening',
        },
        labels: {
          invalidDate: '{date}|{time}|{zone}|{from}|{to}|{unknown}',
          invalidTime: '{date}|{time}|{zone}|{from}|{to}|{unknown}',
        },
      })
      enterMoment(picker, '2026-10-17', '06', '00')
      picker.setPartText('start', 'second', '15')
      picker.setMeridiem('start', 'pm')
      picker.setPartText('start', part, text)
      expect(picker.state.get().start.result).toEqual({
        status: 'invalid',
        reason,
      })
      expect(picker.state.get().start.reason).toBe(
        `${expected}|Mars/Olympus|{from}|{to}|{unknown}`,
      )
    },
  )

  it('leaves unavailable coordinates literal in invalid reasons without guessing missing input', () => {
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: null,
      today,
      timeZone: 'Mars/Olympus',
      factors: { hourCycle: 'h12' },
      labels: { invalidDate: '{date}|{time}|{zone}|{unknown}' },
    })
    picker.setPartText('start', 'month', '10')
    picker.setPartText('start', 'day', '32')
    picker.setPartText('start', 'year', '20')
    picker.setPartText('start', 'hour', '06')
    picker.setPartText('start', 'minute', '00')
    expect(picker.state.get().start.reason).toBe(
      '{date}|{time}|Mars/Olympus|{unknown}',
    )
    picker.setPartText('start', 'year', '2026')
    expect(picker.state.get().start.reason).toBe(
      '32/10/2026|{time}|Mars/Olympus|{unknown}',
    )
    picker.setMeridiem('start', 'am')
    expect(picker.state.get().start.reason).toBe(
      '32/10/2026|06:00 AM|Mars/Olympus|{unknown}',
    )
  })

  it('formats historical second offsets and fills all reason slots with explicit factors', () => {
    const real = createIntlDateTimeZoneAdapter()
    const parts = {
      year: 1900,
      month: 1,
      day: 1,
      hour: 5,
      minute: 41,
      second: 16,
      millisecond: 0,
    }
    const adapter: DateTimeZoneAdapter = {
      project: () => ({
        status: 'valid',
        parts,
        candidate: { epochMs: 0, offsetSeconds: 20476 },
      }),
      resolve: () => ({
        status: 'fold',
        candidates: [
          { epochMs: 0, offsetSeconds: 20476 },
          { epochMs: 1, offsetSeconds: 0 },
        ],
      }),
    }
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: 0,
      today,
      timeZone: 'Synthetic/Zone',
      adapter,
    })
    expect(part(picker, 'offset')?.text).toBe('UTC+05:41:16')
    picker.selectOffset('start', 1)
    expect(part(picker, 'offset')?.text).toBe('UTC+00:00')
    const gap = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: null,
      today,
      timeZone: 'Europe/Warsaw',
      adapter: real,
      showSeconds: true,
      factors: {
        hourCycle: 'h12',
        dateOrder: ['month', 'day', 'year'],
        dateSeparator: '-',
      },
      labels: { gap: '{date}|{time}|{zone}|{from}|{to}|{unknown}' },
    })
    enterMoment(gap, '2026-03-29', '02', '30')
    gap.setPartText('start', 'second', '15')
    gap.setMeridiem('start', 'am')
    expect(gap.state.get().start.reason).toBe(
      '03-29-2026|02:30:15 AM|Europe/Warsaw|02:00:00 AM|03:00:00 AM|{unknown}',
    )
  })
})

describe('publication, interaction guards and lifetime', () => {
  it.each(['disabled', 'busy'] as const)(
    'blocks every user method while %s, rereads plain getters and allows owner setters',
    (guard) => {
      let locked = false
      const onChange = vi.fn()
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'single',
        value: Date.parse('2026-10-25T01:30:00Z'),
        today,
        timeZone: 'Europe/Warsaw',
        factors: { hourCycle: 'h12' },
        [guard]: () => locked,
        onChange,
      })
      locked = true
      const snapshot = picker.state.get()
      picker.setPartText('start', 'minute', '31')
      expect(picker.inputDigit('start', 'minute', '1')).toBe(false)
      picker.leavePart('start', 'minute')
      picker.setMeridiem('start', 'pm')
      picker.clearPart('start', 'hour')
      picker.stepPart('start', 'hour', 1)
      picker.selectOffset('start', Date.parse('2026-10-25T00:30:00Z'))
      picker.setDate('start', '2026-10-26')
      expect(picker.state.get()).toBe(snapshot)
      expect(onChange).not.toHaveBeenCalled()
      picker.setValue(1792209600000)
      expect(picker.state.get().result).toEqual({
        status: 'valid',
        value: 1792209600000,
      })
      picker.setEndpointValue('start', 1792213200000)
      expect(picker.state.get().result).toEqual({
        status: 'valid',
        value: 1792213200000,
      })
      picker.setShowSeconds(true)
      picker.setFactors({ hourCycle: 'h23' })
      expect(part(picker, 'second')?.text).toBe('00')
      expect(part(picker, 'meridiem')).toBeUndefined()
      locked = false
      picker.setPartText('start', 'minute', '10')
      expect(onChange).toHaveBeenCalledTimes(1)
    },
  )

  it('exposes reactive disabled and busy signals', () => {
    const disabled = createSignal(false),
      busy = createSignal(false)
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: null,
      disabled: () => disabled.get(),
      busy: () => busy.get(),
    })
    const disabledChanges = vi.fn(),
      busyChanges = vi.fn()
    const stopDisabled = subscribeSignal(picker.disabled, disabledChanges)
    const stopBusy = subscribeSignal(picker.busy, busyChanges)
    expect(picker.disabled.get()).toBe(false)
    expect(picker.busy.get()).toBe(false)
    disabled.set(true)
    busy.set(true)
    expect(picker.disabled.get()).toBe(true)
    expect(picker.busy.get()).toBe(true)
    expect(disabledChanges).toHaveBeenCalledExactlyOnceWith(true)
    expect(busyChanges).toHaveBeenCalledExactlyOnceWith(true)
    disabled.set(false)
    busy.set(false)
    expect(picker.disabled.get()).toBe(false)
    expect(picker.busy.get()).toBe(false)
    stopDisabled()
    stopBusy()
  })

  it('publishes exactly one coherent snapshot per changed user edit without programmatic echoes', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: null,
      onChange,
    })
    const publications = vi.fn()
    const stop = subscribeSignal(picker.state, publications)
    const empty = picker.state.get()
    picker.clearPart('start', 'hour')
    expect(picker.state.get()).toBe(empty)
    expect(publications).not.toHaveBeenCalled()
    picker.setPartText('start', 'hour', '1')
    expect(onChange).toHaveBeenCalledExactlyOnceWith(picker.state.get())
    expect(onChange.mock.calls[0]?.[0]).toBe(picker.state.get())
    expect(publications).toHaveBeenCalledExactlyOnceWith(picker.state.get())
    expect(empty.start.parts.every(({ text }) => text === '')).toBe(true)
    picker.leavePart('start', 'hour')
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(part(picker, 'hour')?.text).toBe('01')
    picker.setPartText('start', 'minute', '99')
    expect(onChange).toHaveBeenCalledTimes(2)
    expect(onChange.mock.lastCall?.[0].start.result).toEqual({
      status: 'invalid',
      reason: 'time',
    })
    picker.setValue('06:00:00')
    picker.setEndpointValue('start', '07:00:00')
    picker.setFactors({ hourCycle: 'h12' })
    picker.setShowSeconds(true)
    picker.leavePart('start', 'minute')
    expect(onChange).toHaveBeenCalledTimes(2)
    picker.setMeridiem('start', 'pm')
    expect(onChange).toHaveBeenCalledTimes(3)
    picker.stepPart('start', 'minute', 1)
    expect(onChange).toHaveBeenCalledTimes(4)
    picker.clearPart('start', 'hour')
    expect(onChange).toHaveBeenCalledTimes(5)
    expect(onChange.mock.lastCall?.[0]).toBe(picker.state.get())
    stop()
  })

  it('makes dispose idempotent and all subsequent methods no-ops, including invalid calls', () => {
    const onChange = vi.fn()
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'single',
      value: 1792209600000,
      today,
      timeZone: 'Europe/Warsaw',
      onChange,
    })
    const snapshot = picker.state.get()
    picker.dispose()
    picker.dispose()
    picker.setValue(NaN)
    picker.setEndpointValue('end', null)
    picker.setFactors({ hourCycle: 'bad' as 'h12' })
    picker.setShowSeconds(true)
    picker.setPartText('end', 'day', 'invalid')
    expect(picker.inputDigit('start', 'hour', 'bad')).toBe(false)
    picker.leavePart('end', 'year')
    picker.setMeridiem('start', 'pm')
    picker.clearPart('start', 'hour')
    picker.stepPart('start', 'hour', 1)
    picker.selectOffset('start', 0)
    picker.setDate('start', 'invalid')
    expect(picker.state.get()).toBe(snapshot)
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('ready-to-render part models and environment independence', () => {
  it('provides numeric values, explicit names, placeholders and separators in factor order', () => {
    const picker = datePicker('2026-10-17')
    expect(picker.state.get().start.parts).toEqual([
      {
        part: 'day',
        text: '17',
        prefix: '',
        placeholder: 'dd',
        label: 'Day',
        valueNow: 17,
        valueMin: 1,
        valueMax: 31,
        valueText: '17',
      },
      {
        part: 'month',
        text: '10',
        prefix: '/',
        placeholder: 'mm',
        label: 'Month',
        valueNow: 10,
        valueMin: 1,
        valueMax: 12,
        valueText: 'October',
      },
      {
        part: 'year',
        text: '2026',
        prefix: '/',
        placeholder: 'yyyy',
        label: 'Year',
        valueNow: 2026,
        valueMin: 1,
        valueMax: 9999,
        valueText: '2026',
      },
    ])
    picker.setFactors({
      dateOrder: ['month', 'day', 'year'],
      dateSeparator: '-',
    })
    expect(
      picker.state.get().start.parts.map(({ part, prefix }) => [part, prefix]),
    ).toEqual([
      ['month', ''],
      ['day', '-'],
      ['year', '-'],
    ])
    picker.setPartText('start', 'year', '202')
    expect(part(picker, 'year')).toMatchObject({
      valueNow: null,
      valueText: '202',
    })
    picker.clearPart('start', 'month')
    expect(part(picker, 'month')).toMatchObject({
      text: '',
      valueNow: null,
      valueText: 'mm',
    })
    const time = timePicker('13:05:09', true)
    time.setFactors({
      hourCycle: 'h12',
      amLabel: 'Morning',
      pmLabel: 'Evening',
    })
    expect(time.state.get().start.parts).toEqual([
      {
        part: 'hour',
        text: '01',
        prefix: '',
        placeholder: '--',
        label: 'Hours',
        valueNow: 1,
        valueMin: 1,
        valueMax: 12,
        valueText: '01',
      },
      {
        part: 'minute',
        text: '05',
        prefix: ':',
        placeholder: '--',
        label: 'Minutes',
        valueNow: 5,
        valueMin: 0,
        valueMax: 59,
        valueText: '05',
      },
      {
        part: 'second',
        text: '09',
        prefix: ':',
        placeholder: '--',
        label: 'Seconds',
        valueNow: 9,
        valueMin: 0,
        valueMax: 59,
        valueText: '09',
      },
      {
        part: 'meridiem',
        text: 'Evening',
        prefix: ' ',
        placeholder: '--',
        label: 'AM/PM',
        valueNow: 1,
        valueMin: 0,
        valueMax: 1,
        valueText: 'Evening',
      },
    ])
    const moment = momentPicker(1792209600000)
    expect(part(moment, 'hour')?.prefix).toBe(', ')
    expect(moment.state.get().start.reason).toBeNull()
    expect(time.labels).toMatchObject({
      from: 'From',
      to: 'To',
      chooseTime: 'Choose time',
    })
  })

  it('uses no current clock, host-local calendar reads, locale or Intl in date/time editing', () => {
    const OriginalDate = Date
    let implicitDates = 0
    class ExplicitDate extends OriginalDate {
      constructor(value?: string | number) {
        if (arguments.length === 0) {
          implicitDates++
          throw new Error('Implicit clock read')
        }
        super(value!)
      }
    }
    vi.stubGlobal('Date', ExplicitDate)
    const now = vi.spyOn(Date, 'now').mockImplementation(() => {
      throw new Error('Clock read')
    })
    const intl = vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => {
      throw new Error('Locale read')
    })
    for (const getter of [
      'getFullYear',
      'getMonth',
      'getDate',
      'getHours',
      'getMinutes',
      'getSeconds',
    ] as const)
      vi.spyOn(OriginalDate.prototype, getter).mockImplementation(() => {
        throw new Error('Host-zone read')
      })
    const date = datePicker('2026-02-28')
    date.stepPart('start', 'day', 1)
    date.setFactors({ dateOrder: ['month', 'day', 'year'] })
    date.setDate('start', '2024-02-29')
    date.clearPart('start', 'year')
    date.stepPart('start', 'year', 1)
    const time = timePicker('13:00:42')
    time.setFactors({ hourCycle: 'h12' })
    time.stepPart('start', 'hour', 1)
    time.setShowSeconds(true)
    time.setPartText('start', 'second', '15')
    expect(time.state.get().result).toEqual({
      status: 'valid',
      value: '14:00:15',
    })
    expect(now).not.toHaveBeenCalled()
    expect(intl).not.toHaveBeenCalled()
    expect(implicitDates).toBe(0)
  })
})
