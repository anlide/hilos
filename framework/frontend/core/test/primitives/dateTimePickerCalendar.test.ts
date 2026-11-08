import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createDateTimePickerController,
  subscribeSignal,
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  type DateTimePickerCalendar,
  type DateTimePickerCalendarGrid,
  type DateTimePickerCalendarSnapshot,
  type DateTimePickerOptions,
  type DateTimePickerSnapshot,
} from '../../src/base/index.js'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

const today = () => '2026-10-13'
function datePicker(
  value: string | null = null,
  options: Partial<DateTimePickerOptions<'date', 'single'>> = {},
) {
  return createDateTimePickerController({
    kind: 'date',
    count: 'single',
    value,
    today,
    ...options,
  })
}
function rangePicker(
  value: readonly [string, string] | null = null,
  options: Partial<DateTimePickerOptions<'date', 'range'>> = {},
) {
  return createDateTimePickerController({
    kind: 'date',
    count: 'range',
    value,
    today,
    ...options,
  })
}
function momentPicker(value: number | null = null, timeZone = 'Europe/Warsaw') {
  return createDateTimePickerController({
    kind: 'datetime',
    count: 'single',
    value,
    today,
    timeZone,
  })
}
function grid(picker: {
  readonly calendar: DateTimePickerCalendar
}): DateTimePickerCalendarGrid {
  const result = picker.calendar.state.get().grid
  if (result === null) throw new Error('Expected an open calendar grid')
  return result
}
function day(
  picker: { readonly calendar: DateTimePickerCalendar },
  date: string,
) {
  const result = grid(picker)
    .weeks.flat()
    .find((cell) => cell?.date === date)
  if (result === null || result === undefined)
    throw new Error(`Missing day ${date}`)
  return result
}
function selected(picker: { readonly calendar: DateTimePickerCalendar }) {
  return grid(picker)
    .weeks.flat()
    .filter((cell) => cell?.selected)
    .map((cell) => cell!.date)
}

describe('DateTimePicker calendar opening and day semantics', () => {
  it('starts closed and opens on a selected day, retaining one explicit today per opening', () => {
    const readToday = vi.fn(today)
    const picker = datePicker('2026-10-17', { today: readToday })
    expect(picker.calendar.state.get()).toEqual({
      open: false,
      activeEdge: 'start',
      grid: null,
    })
    expect(readToday).not.toHaveBeenCalled()
    picker.calendar.open()
    picker.calendar.open()
    expect(readToday).toHaveBeenCalledTimes(1)
    expect(grid(picker)).toMatchObject({
      year: 2026,
      month: 10,
      title: 'October 2026',
      focusDate: '2026-10-17',
    })
    expect(day(picker, '2026-10-17')).toMatchObject({
      day: 17,
      label: 'Saturday, 17 October 2026',
      selected: true,
      today: false,
      disabled: false,
      focusTarget: true,
      weekend: true,
      span: 'none',
    })
    expect(day(picker, '2026-10-13')).toMatchObject({
      today: true,
      selected: false,
      focusTarget: false,
    })
    expect(
      grid(picker)
        .weeks.flat()
        .filter((cell) => cell?.focusTarget),
    ).toHaveLength(1)
    expect(grid(picker).weeks[0]).toEqual([
      null,
      null,
      null,
      expect.objectContaining({ day: 1 }),
      expect.objectContaining({ day: 2 }),
      expect.objectContaining({ day: 3 }),
      expect.objectContaining({ day: 4 }),
    ])
    expect(grid(picker).weeks.at(-1)?.at(-1)).toBeNull()
    picker.calendar.close()
    expect(picker.calendar.state.get().grid).toBeNull()
    picker.calendar.open()
    expect(readToday).toHaveBeenCalledTimes(2)
  })

  it('focuses today for empty, partial or impossible dates', () => {
    const picker = datePicker()
    picker.calendar.open()
    expect(grid(picker).focusDate).toBe('2026-10-13')
    picker.calendar.close()
    picker.setPartText('start', 'year', '202')
    picker.setPartText('start', 'month', '02')
    picker.setPartText('start', 'day', '30')
    picker.calendar.open()
    expect(grid(picker).focusDate).toBe('2026-10-13')
    expect(selected(picker)).toEqual([])
    picker.calendar.close()
    picker.setPartText('start', 'year', '2026')
    picker.calendar.open()
    expect(selected(picker)).toEqual([])
    expect(grid(picker).focusDate).toBe('2026-10-13')
  })

  it('opens on the waiting end or the only existing edge', () => {
    const picker = rangePicker()
    picker.setEndpointValue('start', '2026-09-17')
    picker.calendar.open()
    expect(picker.calendar.state.get().activeEdge).toBe('end')
    expect(grid(picker).focusDate).toBe('2026-09-17')
    picker.calendar.close()
    picker.setEndpointValue('start', null)
    picker.setEndpointValue('end', '2026-11-21')
    picker.calendar.open()
    expect(picker.calendar.state.get().activeEdge).toBe('start')
    expect(grid(picker).focusDate).toBe('2026-11-21')
  })

  it('rejects an invalid today on open or Today without changing the snapshot', () => {
    const readToday = vi.fn(() => '2026-02-30')
    const picker = datePicker('2026-10-17', { today: readToday })
    const closed = picker.calendar.state.get()
    expect(() => picker.calendar.open()).toThrow(
      'today() must return a calendar date',
    )
    expect(picker.calendar.state.get()).toBe(closed)
    readToday.mockReturnValue('2026-10-13')
    picker.calendar.open()
    const opened = picker.calendar.state.get()
    readToday.mockReturnValue('bad')
    expect(() => picker.calendar.showToday()).toThrow(RangeError)
    expect(picker.calendar.state.get()).toBe(opened)
  })

  it('opens time controls without reading today or creating a grid', () => {
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: '02:30:00',
    })
    picker.calendar.open()
    expect(picker.calendar.state.get()).toEqual({
      open: true,
      activeEdge: 'start',
      grid: null,
    })
    expect(picker.calendar.pressKey('Enter', false)).toBe(false)
    picker.calendar.showToday()
    picker.calendar.showNextMonth()
    picker.calendar.showPreviousMonth()
    picker.calendar.hoverDay('2026-10-17')
    expect(picker.calendar.state.get().grid).toBeNull()
    expect(() => picker.calendar.selectDay('2026-10-17')).toThrow(RangeError)
    picker.calendar.close()
    expect(picker.calendar.state.get().open).toBe(false)
  })

  it('marks weekends by weekday with Sunday first and Friday/Saturday weekends', () => {
    const picker = datePicker('2026-10-17', {
      factors: { firstWeekday: 0, weekendDays: [5, 6] },
    })
    picker.calendar.open()
    expect(
      grid(picker).weekdays.map(({ weekday, short, full, weekend }) => [
        weekday,
        short,
        full,
        weekend,
      ]),
    ).toEqual([
      [0, 'Sun', 'Sunday', false],
      [1, 'Mon', 'Monday', false],
      [2, 'Tue', 'Tuesday', false],
      [3, 'Wed', 'Wednesday', false],
      [4, 'Thu', 'Thursday', false],
      [5, 'Fri', 'Friday', true],
      [6, 'Sat', 'Saturday', true],
    ])
    expect(day(picker, '2026-10-16').weekend).toBe(true)
    expect(day(picker, '2026-10-17').weekend).toBe(true)
    expect(day(picker, '2026-10-18').weekend).toBe(false)
  })
})

describe('DateTimePicker calendar navigation', () => {
  it('handles arrows and crosses month boundaries without editing the field', () => {
    const picker = datePicker('2026-10-31')
    expect(picker.calendar.pressKey('ArrowRight', false)).toBe(false)
    picker.calendar.open()
    for (const [key, focus] of [
      ['ArrowRight', '2026-11-01'],
      ['ArrowDown', '2026-11-08'],
      ['ArrowLeft', '2026-11-07'],
      ['ArrowUp', '2026-10-31'],
    ]) {
      expect(picker.calendar.pressKey(key!, false)).toBe(true)
      expect(grid(picker).focusDate).toBe(focus)
      expect(day(picker, focus!).focusTarget).toBe(true)
    }
    expect(picker.calendar.pressKey('Escape', false)).toBe(false)
    expect(picker.calendar.pressKey('Tab', false)).toBe(false)
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '2026-10-31',
    })
  })

  it('clamps month shifts without retaining a preferred day and shifts years with Shift', () => {
    const picker = datePicker('2027-01-31')
    picker.calendar.open()
    picker.calendar.pressKey('PageDown', false)
    expect(grid(picker).focusDate).toBe('2027-02-28')
    picker.calendar.pressKey('PageDown', false)
    expect(grid(picker).focusDate).toBe('2027-03-28')
    picker.calendar.pressKey('PageUp', false)
    expect(grid(picker).focusDate).toBe('2027-02-28')
    picker.calendar.pressKey('PageDown', true)
    expect(grid(picker).focusDate).toBe('2028-02-28')
    picker.calendar.pressKey('PageUp', true)
    expect(grid(picker).focusDate).toBe('2027-02-28')
    picker.calendar.showPreviousMonth()
    expect(grid(picker).focusDate).toBe('2027-01-28')
    picker.calendar.showNextMonth()
    expect(grid(picker).focusDate).toBe('2027-02-28')
  })

  it.each([
    [0, '2026-09-27', '2026-10-03'],
    [1, '2026-09-28', '2026-10-04'],
  ] as const)(
    'uses first weekday %s for Home and End across month edges',
    (firstWeekday, first, last) => {
      const picker = datePicker('2026-10-01', { factors: { firstWeekday } })
      picker.calendar.open()
      picker.calendar.pressKey('Home', false)
      expect(grid(picker).focusDate).toBe(first)
      picker.calendar.pressKey('End', false)
      expect(grid(picker).focusDate).toBe(last)
    },
  )

  it.each([
    ['0001-01-01', ['ArrowLeft', 'ArrowUp', 'PageUp', 'Home'], false, true],
    ['9999-12-31', ['ArrowRight', 'ArrowDown', 'PageDown', 'End'], true, false],
  ] as const)(
    'keeps focus at the outer date %s and reports handled boundary keys',
    (date, keys, canPreviousMonth, canNextMonth) => {
      const picker = datePicker(date, { factors: { firstWeekday: 0 } })
      picker.calendar.open()
      const unchanged = picker.calendar.state.get()
      for (const key of keys) {
        expect(picker.calendar.pressKey(key, false)).toBe(true)
        expect(picker.calendar.pressKey(key, true)).toBe(true)
        expect(picker.calendar.state.get()).toBe(unchanged)
      }
      if (canPreviousMonth) picker.calendar.showNextMonth()
      else picker.calendar.showPreviousMonth()
      expect(picker.calendar.state.get()).toBe(unchanged)
      expect(grid(picker)).toMatchObject({
        focusDate: date,
        canPreviousMonth,
        canNextMonth,
      })
      if (!canPreviousMonth)
        expect(day(picker, date).label).toBe('Monday, 1 January 0001')
    },
  )

  it('refreshes today and focus without selecting, and ignores Today while closed', () => {
    const readToday = vi.fn(today)
    const picker = datePicker('2026-10-17', { today: readToday })
    picker.calendar.showToday()
    expect(readToday).not.toHaveBeenCalled()
    picker.calendar.open()
    readToday.mockReturnValue('2026-11-08')
    picker.calendar.showToday()
    expect(grid(picker).focusDate).toBe('2026-11-08')
    expect(day(picker, '2026-11-08').today).toBe(true)
    picker.calendar.showPreviousMonth()
    expect(day(picker, '2026-10-13').today).toBe(false)
    expect(selected(picker)).toEqual(['2026-10-17'])
    expect(readToday).toHaveBeenCalledTimes(2)
  })
})

describe('DateTimePicker calendar selection', () => {
  it.each(['Enter', ' '])(
    'selects a date with %s and closes date-only single',
    (key) => {
      const onChange = vi.fn()
      const picker = datePicker(null, { onChange })
      picker.calendar.open()
      picker.calendar.pressKey('ArrowRight', false)
      expect(picker.calendar.pressKey(key, false)).toBe(true)
      expect(picker.state.get().result).toEqual({
        status: 'valid',
        value: '2026-10-14',
      })
      expect(picker.calendar.state.get().open).toBe(false)
      expect(onChange).toHaveBeenCalledTimes(1)
    },
  )

  it('changes only a loaded moment date, retaining seconds and milliseconds', () => {
    const picker = momentPicker(Date.UTC(2026, 9, 17, 4, 0, 45, 123))
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-20')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.UTC(2026, 9, 20, 4, 0, 45, 123),
    })
    expect(picker.calendar.state.get().open).toBe(true)
    expect(grid(picker).focusDate).toBe('2026-10-20')
    expect(selected(picker)).toEqual(['2026-10-20'])
  })

  it('keeps a selected date with incomplete time and clears only by explicit field editing', () => {
    const picker = momentPicker()
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-17')
    expect(picker.state.get().start.result.status).toBe('incomplete')
    expect(selected(picker)).toEqual(['2026-10-17'])
    picker.clearPart('start', 'month')
    expect(selected(picker)).toEqual([])
    expect(grid(picker).focusDate).toBe('2026-10-17')
  })

  it('builds a range with pointer and keyboard preview, then completed interior', () => {
    const onChange = vi.fn()
    const picker = rangePicker(null, { onChange })
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-17')
    expect(picker.calendar.state.get().activeEdge).toBe('end')
    expect(picker.state.get().result.status).toBe('unresolved')
    picker.calendar.hoverDay('2026-10-21')
    expect(day(picker, '2026-10-18').span).toBe('preview')
    expect(day(picker, '2026-10-21')).toMatchObject({
      span: 'preview-end',
      selected: false,
    })
    picker.calendar.hoverDay('2026-10-17')
    expect(day(picker, '2026-10-18').span).toBe('none')
    picker.calendar.hoverDay('2026-10-16')
    expect(day(picker, '2026-10-16').span).toBe('none')
    picker.calendar.hoverDay(null)
    picker.calendar.pressKey('ArrowDown', false)
    expect(day(picker, '2026-10-21').span).toBe('preview')
    expect(day(picker, '2026-10-24').span).toBe('preview-end')
    picker.calendar.selectDay('2026-10-21')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-17', '2026-10-21'],
    })
    expect(picker.calendar.state.get()).toMatchObject({
      activeEdge: 'start',
      open: false,
    })
    picker.calendar.open()
    picker.calendar.hoverDay('2026-10-24')
    expect(day(picker, '2026-10-18').span).toBe('inside')
    expect(day(picker, '2026-10-21')).toMatchObject({
      span: 'none',
      selected: true,
    })
    expect(day(picker, '2026-10-24').span).toBe('none')
    expect(onChange).toHaveBeenCalledTimes(2)
  })

  it('restarts when a waiting end is earlier, then allows a one-day pair', () => {
    const picker = rangePicker()
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-17')
    picker.calendar.selectDay('2026-10-15')
    expect(selected(picker)).toEqual(['2026-10-15'])
    expect(picker.state.get().end?.result.status).toBe('empty')
    expect(picker.calendar.state.get().activeEdge).toBe('end')
    picker.calendar.selectDay('2026-10-15')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-15', '2026-10-15'],
    })
    expect(picker.calendar.state.get().open).toBe(false)
  })

  it('edits just the active edge of a completed pair and leaves start edits open', () => {
    const picker = rangePicker(['2026-10-17', '2026-10-21'])
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-18')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-18', '2026-10-21'],
    })
    expect(picker.calendar.state.get()).toMatchObject({
      open: true,
      activeEdge: 'end',
    })
    picker.calendar.selectDay('2026-10-22')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-18', '2026-10-22'],
    })
    expect(picker.calendar.state.get().open).toBe(false)
  })

  it.each(['start', 'end'] as const)(
    'restarts a completed pair from active %s with one coherent onChange',
    (edge) => {
      const snapshots: DateTimePickerSnapshot<'date', 'range'>[] = []
      const picker = rangePicker(['2026-10-17', '2026-10-21'], {
        onChange: (snapshot) => snapshots.push(snapshot),
      })
      picker.calendar.open()
      picker.calendar.setActiveEdge(edge)
      const date = edge === 'start' ? '2026-10-24' : '2026-10-15'
      const published = vi.fn()
      const stop = subscribeSignal(picker.state, published)
      picker.calendar.selectDay(date)
      expect(snapshots).toHaveLength(1)
      expect(published).toHaveBeenCalledTimes(1)
      expect(snapshots[0]).toMatchObject({
        start: { result: { status: 'valid', value: date } },
        end: { result: { status: 'empty' } },
        result: { status: 'unresolved' },
      })
      expect(picker.calendar.state.get()).toMatchObject({
        open: true,
        activeEdge: 'end',
      })
      expect(selected(picker)).toEqual([date])
      stop()
    },
  )

  it('retains end time and hidden precision across restart and reselects a fold explicitly', () => {
    const picker = createDateTimePickerController({
      kind: 'datetime',
      count: 'range',
      timeZone: 'Europe/Warsaw',
      today,
      value: [
        Date.UTC(2026, 9, 17, 0, 30, 45, 123),
        Date.UTC(2026, 9, 25, 1, 30, 54, 321),
      ],
    })
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-26')
    expect(picker.state.get().end).toMatchObject({
      result: { status: 'incomplete' },
      parts: expect.arrayContaining([
        expect.objectContaining({ part: 'year', text: '' }),
        expect.objectContaining({ part: 'month', text: '' }),
        expect.objectContaining({ part: 'day', text: '' }),
        expect.objectContaining({ part: 'hour', text: '02' }),
        expect.objectContaining({ part: 'minute', text: '30' }),
      ]),
    })
    expect(picker.state.get().result.status).toBe('unresolved')
    picker.calendar.selectDay('2026-10-27')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: [
        Date.UTC(2026, 9, 26, 1, 30, 45, 123),
        Date.UTC(2026, 9, 27, 1, 30, 54, 321),
      ],
    })
    picker.calendar.setActiveEdge('end')
    picker.calendar.selectDay('2026-10-24')
    picker.calendar.selectDay('2026-10-25')
    expect(picker.state.get().end?.result.status).toBe('ambiguous')
    expect(selected(picker)).toEqual(['2026-10-24', '2026-10-25'])
    const choice = picker.state.get().end!.offsetChoices[1]!
    expect(choice.epochMs).toBe(Date.UTC(2026, 9, 25, 1, 30, 54, 321))
    expect(choice.selected).toBe(false)
  })

  it('sets an end without a start and closes when the missing start completes the pair', () => {
    const picker = rangePicker()
    picker.calendar.open()
    picker.calendar.setActiveEdge('end')
    picker.calendar.selectDay('2026-10-21')
    expect(picker.calendar.state.get()).toMatchObject({
      activeEdge: 'start',
      open: true,
    })
    expect(picker.state.get().start.result.status).toBe('empty')
    picker.calendar.selectDay('2026-10-17')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-17', '2026-10-21'],
    })
    expect(picker.calendar.state.get().open).toBe(false)
  })

  it('moves month to the chosen active edge and gives no interior to a manually reversed pair', () => {
    const picker = rangePicker(['2026-10-21', '2026-11-17'])
    picker.calendar.open()
    picker.calendar.setActiveEdge('end')
    expect(grid(picker).focusDate).toBe('2026-11-17')
    picker.calendar.setActiveEdge('start')
    expect(grid(picker).focusDate).toBe('2026-10-21')
    picker.setValue(['2026-10-21', '2026-10-17'])
    expect(selected(picker)).toEqual(['2026-10-17', '2026-10-21'])
    expect(day(picker, '2026-10-18').span).toBe('none')
    picker.calendar.setActiveEdge('end')
    picker.calendar.hoverDay('2026-10-25')
    expect(day(picker, '2026-10-23').span).toBe('none')
  })

  it('still changes the active edge and closes when the selected date is unchanged', () => {
    const onChange = vi.fn()
    const picker = rangePicker(['2026-10-17', '2026-10-21'], { onChange })
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-17')
    expect(picker.calendar.state.get()).toMatchObject({
      activeEdge: 'end',
      open: true,
    })
    picker.calendar.selectDay('2026-10-21')
    expect(picker.calendar.state.get().open).toBe(false)
    expect(onChange).not.toHaveBeenCalled()
    const single = datePicker('2026-10-17', { onChange })
    single.calendar.open()
    single.calendar.selectDay('2026-10-17')
    expect(single.calendar.state.get().open).toBe(false)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('does not select before minDate, even with Enter, while manual earlier input remains allowed', () => {
    const onChange = vi.fn()
    const picker = datePicker('2026-10-17', { minDate: '2026-10-13', onChange })
    picker.calendar.selectDay('2026-10-20')
    picker.calendar.open()
    expect(day(picker, '2026-10-12').disabled).toBe(true)
    expect(day(picker, '2026-10-13').disabled).toBe(false)
    picker.calendar.selectDay('2026-10-12')
    picker.calendar.pressKey('ArrowUp', false)
    expect(grid(picker).focusDate).toBe('2026-10-10')
    expect(day(picker, '2026-10-10')).toMatchObject({
      focusTarget: true,
      disabled: true,
    })
    expect(picker.calendar.pressKey('Enter', false)).toBe(true)
    expect(onChange).not.toHaveBeenCalled()
    expect(selected(picker)).toEqual(['2026-10-17'])
    picker.setPartText('start', 'day', '12')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '2026-10-12',
    })
    expect(day(picker, '2026-10-12')).toMatchObject({
      selected: true,
      disabled: true,
    })
    picker.calendar.selectDay('2026-10-13')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '2026-10-13',
    })
  })

  it.each([
    ['Europe/Warsaw', '2026-03-29', 'gap'],
    ['Europe/Warsaw', '2026-10-25', 'ambiguous'],
    ['Pacific/Apia', '2011-12-30', 'gap'],
  ])(
    'keeps a selected day at %s %s when time resolves as %s',
    (zone, date, status) => {
      const picker = momentPicker(null, zone)
      picker.setPartText('start', 'hour', '02')
      picker.setPartText('start', 'minute', '30')
      picker.calendar.open()
      picker.calendar.selectDay(date!)
      expect(picker.state.get().start.result.status).toBe(status)
      expect(picker.state.get().result.status).toBe('unresolved')
      expect(selected(picker)).toEqual([date])
      expect(grid(picker).focusDate).toBe(date)
    },
  )

  it('rejects invalid dates and an end on single before changing anything', () => {
    const picker = datePicker()
    expect(() => picker.calendar.selectDay('2026-02-30')).toThrow(RangeError)
    expect(() => picker.calendar.setActiveEdge('end')).toThrow(RangeError)
    picker.calendar.open()
    const unchanged = picker.calendar.state.get()
    expect(() => picker.calendar.selectDay('bad')).toThrow(RangeError)
    expect(picker.calendar.state.get()).toBe(unchanged)
  })
})

describe('DateTimePicker calendar reactivity and lifetime', () => {
  it('retains the closed snapshot when the owner changes the selected date', () => {
    const picker = datePicker('2026-10-17')
    const before = picker.calendar.state.get()
    const publications = vi.fn()
    const stop = subscribeSignal(picker.calendar.state, publications)
    picker.setValue('2026-10-18')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '2026-10-18',
    })
    expect(picker.calendar.state.get()).toBe(before)
    expect(before).toEqual({ open: false, activeEdge: 'start', grid: null })
    expect(publications).not.toHaveBeenCalled()
    stop()
  })

  it('retains an open time-only snapshot through value and time edits', () => {
    const picker = createDateTimePickerController({
      kind: 'time',
      count: 'single',
      value: '02:30:00',
    })
    picker.calendar.open()
    const before = picker.calendar.state.get()
    const publications = vi.fn()
    const stop = subscribeSignal(picker.calendar.state, publications)
    picker.setValue('03:40:15')
    picker.setPartText('start', 'minute', '45')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: '03:45:15',
    })
    expect(picker.calendar.state.get()).toBe(before)
    expect(before).toEqual({ open: true, activeEdge: 'start', grid: null })
    expect(publications).not.toHaveBeenCalled()
    stop()
  })

  it('retains an open datetime snapshot and grid when only time changes', () => {
    const picker = momentPicker(Date.UTC(2026, 9, 17, 4, 0, 45, 123))
    picker.calendar.open()
    const before = picker.calendar.state.get()
    const publications = vi.fn()
    const stop = subscribeSignal(picker.calendar.state, publications)
    picker.setValue(Date.UTC(2026, 9, 17, 4, 30, 15, 123))
    picker.setPartText('start', 'minute', '40')
    picker.setShowSeconds(true)
    picker.setPartText('start', 'second', '20')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: Date.UTC(2026, 9, 17, 4, 40, 20, 123),
    })
    expect(picker.calendar.state.get()).toBe(before)
    expect(grid(picker)).toBe(before.grid)
    expect(selected(picker)).toEqual(['2026-10-17'])
    expect(publications).not.toHaveBeenCalled()
    stop()
  })

  it('publishes changed selection, names, focus and month with new snapshots', () => {
    const picker = datePicker('2026-10-17')
    picker.calendar.open()
    const before = picker.calendar.state.get()
    const publications = vi.fn()
    const stop = subscribeSignal(picker.calendar.state, publications)
    picker.setValue('2026-10-18')
    const selection = picker.calendar.state.get()
    expect(selection).not.toBe(before)
    expect(selected(picker)).toEqual(['2026-10-18'])
    expect(grid(picker).focusDate).toBe('2026-10-17')
    expect(publications).toHaveBeenCalledTimes(1)
    expect(publications).toHaveBeenLastCalledWith(selection)
    picker.setFactors({ dateOrder: ['month', 'day', 'year'] })
    const names = picker.calendar.state.get()
    expect(names).not.toBe(selection)
    expect(day(picker, '2026-10-18').label).toBe('Sunday, October 18 2026')
    expect(publications).toHaveBeenCalledTimes(2)
    expect(publications).toHaveBeenLastCalledWith(names)
    picker.calendar.pressKey('ArrowRight', false)
    const focus = picker.calendar.state.get()
    expect(focus).not.toBe(names)
    expect(grid(picker).focusDate).toBe('2026-10-18')
    expect(day(picker, '2026-10-18').focusTarget).toBe(true)
    expect(publications).toHaveBeenCalledTimes(3)
    expect(publications).toHaveBeenLastCalledWith(focus)
    picker.calendar.showNextMonth()
    const month = picker.calendar.state.get()
    expect(month).not.toBe(focus)
    expect(grid(picker)).toMatchObject({
      title: 'November 2026',
      focusDate: '2026-11-18',
    })
    expect(publications).toHaveBeenCalledTimes(4)
    expect(publications).toHaveBeenLastCalledWith(month)
    stop()
  })

  it.each(['date', 'datetime'] as const)(
    'keeps the %s calendar frozen when onChange disposes the picker',
    (kind) => {
      let frozen: DateTimePickerCalendarSnapshot | undefined
      let frozenData: DateTimePickerCalendarSnapshot | undefined
      const publications = vi.fn()
      const onChange = vi.fn((): void => {
        picker.dispose()
        frozen = picker.calendar.state.get()
        frozenData = structuredClone(frozen)
        publications.mockClear()
      })
      const picker =
        kind === 'date'
          ? datePicker(null, { onChange })
          : createDateTimePickerController({
              kind: 'datetime',
              count: 'single',
              value: null,
              today,
              timeZone: 'Europe/Warsaw',
              onChange,
            })
      picker.calendar.open()
      const stop = subscribeSignal(picker.calendar.state, publications)
      picker.calendar.selectDay('2026-10-17')
      expect(onChange).toHaveBeenCalledTimes(1)
      expect(frozen).toMatchObject({
        open: true,
        activeEdge: 'start',
        grid: { focusDate: '2026-10-13' },
      })
      expect(picker.calendar.state.get()).toBe(frozen)
      expect(picker.calendar.state.get()).toEqual(frozenData)
      expect(selected(picker)).toEqual(['2026-10-17'])
      expect(publications).not.toHaveBeenCalled()
      stop()
    },
  )

  it.each(['busy', 'disabled'] as const)(
    'blocks opening and selection while %s but allows navigation and closing',
    (guard) => {
      let blocked = true
      const picker = datePicker('2026-10-17', { [guard]: () => blocked })
      picker.calendar.open()
      expect(picker.calendar.state.get().open).toBe(false)
      blocked = false
      picker.calendar.open()
      blocked = true
      picker.calendar.selectDay('2026-10-20')
      expect(selected(picker)).toEqual(['2026-10-17'])
      picker.calendar.pressKey('ArrowRight', false)
      expect(grid(picker).focusDate).toBe('2026-10-18')
      picker.calendar.showNextMonth()
      expect(grid(picker).month).toBe(11)
      picker.calendar.showToday()
      expect(grid(picker).focusDate).toBe('2026-10-13')
      picker.calendar.close()
      expect(picker.calendar.state.get().open).toBe(false)
    },
  )

  it('freezes the last snapshot and makes every calendar method a no-op after disposal', () => {
    const readToday = vi.fn(today)
    const picker = datePicker('2026-10-17', { today: readToday })
    picker.calendar.open()
    const snapshot = picker.calendar.state.get()
    const draft = picker.state.get()
    picker.dispose()
    picker.calendar.open()
    picker.calendar.close()
    picker.calendar.setActiveEdge('end')
    picker.calendar.showPreviousMonth()
    picker.calendar.showNextMonth()
    picker.calendar.showToday()
    picker.calendar.hoverDay('2026-10-21')
    picker.calendar.selectDay('bad')
    expect(picker.calendar.pressKey('Enter', false)).toBe(false)
    expect(picker.calendar.state.get()).toBe(snapshot)
    expect(picker.state.get()).toBe(draft)
    expect(readToday).toHaveBeenCalledTimes(1)
  })

  it('keeps navigation separate from picker publications and suppresses unchanged calendar snapshots', () => {
    const onChange = vi.fn()
    const picker = rangePicker(null, { onChange })
    picker.setEndpointValue('start', '2026-10-17')
    const draft = picker.state.get()
    const publications = vi.fn()
    const calendarPublications = vi.fn()
    const stop = subscribeSignal(picker.state, publications)
    const stopCalendar = subscribeSignal(
      picker.calendar.state,
      calendarPublications,
    )
    picker.calendar.open()
    picker.calendar.hoverDay('2026-10-21')
    const hovered = picker.calendar.state.get()
    const count = calendarPublications.mock.calls.length
    picker.calendar.hoverDay('2026-10-21')
    expect(picker.calendar.state.get()).toBe(hovered)
    expect(calendarPublications).toHaveBeenCalledTimes(count)
    picker.calendar.hoverDay(null)
    picker.calendar.setActiveEdge('start')
    picker.calendar.showPreviousMonth()
    picker.calendar.showNextMonth()
    picker.calendar.showToday()
    picker.calendar.pressKey('Home', false)
    const home = picker.calendar.state.get()
    picker.calendar.pressKey('Home', false)
    expect(picker.calendar.state.get()).toBe(home)
    picker.calendar.close()
    const closed = picker.calendar.state.get()
    picker.calendar.close()
    picker.calendar.setActiveEdge('end')
    expect(picker.calendar.state.get()).toBe(closed)
    expect(picker.state.get()).toBe(draft)
    expect(publications).not.toHaveBeenCalled()
    expect(onChange).not.toHaveBeenCalled()
    stop()
    stopCalendar()
  })

  it('recomputes factors and external selection without moving the visible month or focus', () => {
    const picker = datePicker('2026-10-17')
    picker.calendar.open()
    picker.calendar.showNextMonth()
    picker.setValue('2026-12-20')
    expect(grid(picker).focusDate).toBe('2026-11-17')
    expect(selected(picker)).toEqual([])
    picker.setFactors({
      firstWeekday: 0,
      weekendDays: [5, 6],
      dateOrder: ['year', 'month', 'day'],
      monthNames: DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames.map((name) => ({
        short: `Short ${name.short}`,
        full: `Full ${name.full}`,
      })),
      weekdayNames: DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames.map(
        (name) => ({ short: `Short ${name.short}`, full: `Full ${name.full}` }),
      ),
    })
    expect(grid(picker)).toMatchObject({
      title: 'Full November 2026',
      focusDate: '2026-11-17',
      month: 11,
    })
    expect(grid(picker).weekdays[0]).toEqual({
      weekday: 0,
      short: 'Short Sun',
      full: 'Full Sunday',
      weekend: false,
    })
    expect(day(picker, '2026-11-17').label).toBe(
      'Full Tuesday, 2026 Full November 17',
    )
    picker.setPartText('start', 'month', '11')
    expect(selected(picker)).toEqual(['2026-11-20'])
    expect(grid(picker).focusDate).toBe('2026-11-17')
    picker.setFactors({ dateOrder: ['month', 'day', 'year'] })
    expect(day(picker, '2026-11-17').label).toBe('Tuesday, November 17 2026')
  })

  it('uses no current clock, locale, host-local date getters or Intl for a date calendar', () => {
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
    for (const name of [
      'getFullYear',
      'getMonth',
      'getDate',
      'getDay',
      'getHours',
      'getMinutes',
      'getSeconds',
      'toLocaleString',
      'toLocaleDateString',
    ] as const)
      vi.spyOn(OriginalDate.prototype, name).mockImplementation(() => {
        throw new Error('Host-local date read')
      })
    const picker = rangePicker()
    picker.calendar.open()
    picker.calendar.selectDay('2026-10-17')
    picker.calendar.hoverDay('2026-10-21')
    expect(day(picker, '2026-10-21').span).toBe('preview-end')
    picker.calendar.pressKey('PageDown', true)
    picker.calendar.pressKey('Home', false)
    picker.calendar.showToday()
    expect(grid(picker).title).toBe('October 2026')
    picker.calendar.selectDay('2026-10-21')
    expect(picker.state.get().result).toEqual({
      status: 'valid',
      value: ['2026-10-17', '2026-10-21'],
    })
    expect(now).not.toHaveBeenCalled()
    expect(intl).not.toHaveBeenCalled()
    expect(implicitDates).toBe(0)
  })
})
