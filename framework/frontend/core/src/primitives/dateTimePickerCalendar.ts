import {
  addCalendarDays,
  addCalendarMonths,
  addCalendarYears,
  calendarWeekEnd,
  calendarWeekStart,
  parseCalendarDate,
  type CalendarDate,
} from '../calendar/calendarDate.js'
import { calendarMonthMatrix } from '../calendar/monthMatrix.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import type {
  DateTimePickerFactors,
  DateTimePickerWeekday,
} from './dateTimePickerFactors.js'
import type {
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerEndpointSnapshot,
  DateTimePickerKind,
  DateTimePickerSnapshot,
} from './dateTimePickerValue.js'

/** Completed and provisional range interiors, independently of selected edges. */
export type DateTimePickerCalendarSpan =
  'none' | 'inside' | 'preview' | 'preview-end'

/** One real day, including the semantic state a calendar view exposes. */
export interface DateTimePickerCalendarDay {
  readonly date: CalendarDate
  readonly day: number
  readonly label: string
  readonly weekend: boolean
  readonly today: boolean
  /** The day matches either endpoint, even when its time is unresolved. */
  readonly selected: boolean
  readonly disabled: boolean
  /** The single roving focus target; a disabled day can still receive focus. */
  readonly focusTarget: boolean
  readonly span: DateTimePickerCalendarSpan
}

export interface DateTimePickerCalendarWeekday {
  readonly weekday: DateTimePickerWeekday
  readonly short: string
  readonly full: string
  readonly weekend: boolean
}

export interface DateTimePickerCalendarGrid {
  readonly year: number
  readonly month: number
  readonly title: string
  readonly weekdays: readonly DateTimePickerCalendarWeekday[]
  readonly weeks: readonly (readonly (DateTimePickerCalendarDay | null)[])[]
  readonly focusDate: CalendarDate
  readonly canPreviousMonth: boolean
  readonly canNextMonth: boolean
}

export interface DateTimePickerCalendarSnapshot {
  readonly open: boolean
  readonly activeEdge: DateTimePickerEdge
  /** Closed panels and time-only controls have no month grid. */
  readonly grid: DateTimePickerCalendarGrid | null
}

/** DOM-free navigation and selection over the owning picker's one draft. */
export interface DateTimePickerCalendar {
  readonly state: ReadonlySignal<DateTimePickerCalendarSnapshot>
  /** Read explicit today once and focus the active, other or today's date. */
  open(): void
  /** Close without committing, reverting or completing the draft. */
  close(): void
  /** Address the next day selection and focus that edge's date if it exists. */
  setActiveEdge(edge: DateTimePickerEdge): void
  showPreviousMonth(): void
  showNextMonth(): void
  /** Refresh explicit today and move focus without selecting a day. */
  showToday(): void
  /** Return whether the open grid handled the key, including boundary no-ops. */
  pressKey(key: string, shiftKey: boolean): boolean
  hoverDay(date: CalendarDate | null): void
  /** Apply one atomic date gesture, preserving both endpoints' time precision. */
  selectDay(date: CalendarDate): void
}

/** Internal bridge to the picker; never owns another copy of its value draft. */
export interface PickerCalendarHost {
  readonly kind: DateTimePickerKind
  readonly count: DateTimePickerCount
  readonly state: ReadonlySignal<
    DateTimePickerSnapshot<DateTimePickerKind, DateTimePickerCount>
  >
  readonly today: (() => CalendarDate) | undefined
  readonly minDate: CalendarDate | undefined
  factors(): Readonly<DateTimePickerFactors>
  blocked(): boolean
  disposed(): boolean
  /** Undefined preserves an edge; null clears only its date. */
  applyDates(
    start: CalendarDate | null | undefined,
    end: CalendarDate | null | undefined,
  ): void
}

interface PickerCalendarState {
  readonly open: boolean
  readonly activeEdge: DateTimePickerEdge
  readonly focus: CalendarDate | null
  readonly hover: CalendarDate | null
  readonly today: CalendarDate | null
}

/** A complete date still belongs to its day when its time is incomplete or a gap. */
function endpointDate(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind> | null,
): CalendarDate | null {
  const values = (['year', 'month', 'day'] as const).map(
    (part) =>
      endpoint?.parts.find((candidate) => candidate.part === part)?.valueNow,
  )
  if (values.some((value) => value === null || value === undefined)) return null
  const date = values
    .map((value, index) => String(value).padStart(index === 0 ? 4 : 2, '0'))
    .join('-')
  return parseCalendarDate(date) === null ? null : date
}

/** Only a calendar arithmetic boundary becomes a navigation no-op. */
function shiftedDate(shift: () => CalendarDate): CalendarDate | null {
  try {
    return shift()
  } catch (error) {
    if (error instanceof RangeError) return null
    throw error
  }
}

/** Compose a calendar with the picker's existing draft and atomic write path. */
export function createPickerCalendar(
  host: PickerCalendarHost,
): DateTimePickerCalendar {
  const local = createSignal<PickerCalendarState>({
    open: false,
    activeEdge: 'start',
    focus: null,
    hover: null,
    today: null,
  })
  function update(next: PickerCalendarState): void {
    if (host.disposed()) return
    if (JSON.stringify(next) !== JSON.stringify(local.get())) local.set(next)
  }
  function readToday(): CalendarDate {
    const date = host.today?.()
    if (date === undefined || parseCalendarDate(date) === null)
      throw new RangeError('today() must return a calendar date')
    return date
  }
  let lastSnapshot: DateTimePickerCalendarSnapshot | undefined
  function retainSnapshot(
    next: DateTimePickerCalendarSnapshot,
  ): DateTimePickerCalendarSnapshot {
    if (
      lastSnapshot === undefined ||
      JSON.stringify(next) !== JSON.stringify(lastSnapshot)
    )
      lastSnapshot = next
    return lastSnapshot
  }
  const state = computedSignal<DateTimePickerCalendarSnapshot>(() => {
    const current = local.get()
    if (!current.open || host.kind === 'time')
      return retainSnapshot({
        open: current.open,
        activeEdge: current.activeEdge,
        grid: null,
      })
    const picker = host.state.get()
    const focus = current.focus!
    const { year, month } = parseCalendarDate(focus)!
    const factors = host.factors()
    const start = endpointDate(picker.start)
    const end = endpointDate(picker.end)
    const target = current.hover ?? focus
    const preview =
      current.activeEdge === 'end' && start !== null && end === null
    const matrix = calendarMonthMatrix(year, month, factors.firstWeekday)
    return retainSnapshot({
      open: true,
      activeEdge: current.activeEdge,
      grid: {
        year,
        month,
        title: `${factors.monthNames[month - 1]!.full} ${String(year).padStart(4, '0')}`,
        weekdays: matrix.weekdays.map((weekday) => ({
          weekday,
          ...factors.weekdayNames[weekday]!,
          weekend: factors.weekendDays.includes(weekday),
        })),
        weeks: matrix.weeks.map((week) =>
          week.map((date, column): DateTimePickerCalendarDay | null => {
            if (date === null) return null
            const day = parseCalendarDate(date)!.day
            const weekday = matrix.weekdays[column]!
            const words = {
              day: String(day),
              month: factors.monthNames[month - 1]!.full,
              year: String(year).padStart(4, '0'),
            }
            let span: DateTimePickerCalendarSpan = 'none'
            if (start !== null && end !== null && date > start && date < end)
              span = 'inside'
            else if (preview && target > start!) {
              if (date > start! && date < target) span = 'preview'
              else if (date === target) span = 'preview-end'
            }
            return {
              date,
              day,
              label: `${factors.weekdayNames[weekday]!.full}, ${factors.dateOrder.map((part) => words[part]).join(' ')}`,
              weekend: factors.weekendDays.includes(weekday),
              today: date === current.today,
              selected: date === start || date === end,
              disabled: host.minDate !== undefined && date < host.minDate,
              focusTarget: date === focus,
              span,
            }
          }),
        ),
        focusDate: focus,
        canPreviousMonth: year > 1 || month > 1,
        canNextMonth: year < 9999 || month < 12,
      },
    })
  })
  function move(shift: (date: CalendarDate) => CalendarDate): void {
    const current = local.get()
    if (!current.open || host.kind === 'time') return
    const focus = shiftedDate(() => shift(current.focus!))
    if (focus !== null) update({ ...current, focus })
  }
  function selectDay(date: CalendarDate): void {
    if (host.disposed()) return
    if (host.kind === 'time' || parseCalendarDate(date) === null)
      throw new RangeError(
        'Expected a calendar date for a date or datetime picker',
      )
    const current = local.get()
    if (
      !current.open ||
      host.blocked() ||
      (host.minDate !== undefined && date < host.minDate)
    )
      return
    const picker = host.state.get()
    const start = endpointDate(picker.start)
    const end = endpointDate(picker.end)
    let nextStart: CalendarDate | undefined
    let nextEnd: CalendarDate | null | undefined
    let activeEdge: DateTimePickerEdge = 'start'
    let close = host.kind === 'date' && host.count === 'single'
    if (host.count === 'single') nextStart = date
    else {
      if (
        (current.activeEdge === 'start' && end !== null && date > end) ||
        (current.activeEdge === 'end' && start !== null && date < start)
      ) {
        nextStart = date
        nextEnd = null
        activeEdge = 'end'
      } else if (current.activeEdge === 'start') {
        nextStart = date
        activeEdge = 'end'
      } else nextEnd = date
      const hasStart = (nextStart ?? start) !== null
      const hasEnd = (nextEnd === undefined ? end : nextEnd) !== null
      close =
        host.kind === 'date' &&
        hasStart &&
        hasEnd &&
        ((current.activeEdge === 'end' && start !== null) ||
          (current.activeEdge === 'start' && start === null))
    }
    host.applyDates(nextStart, nextEnd)
    update({
      ...current,
      open: !close,
      activeEdge,
      focus: date,
      hover: null,
      today: close ? null : current.today,
    })
  }
  return {
    state,
    open(): void {
      if (host.disposed()) return
      if (local.get().open || host.blocked()) return
      const picker = host.state.get()
      const start = endpointDate(picker.start)
      const end = endpointDate(picker.end)
      const activeEdge =
        host.count === 'range' && start !== null && end === null
          ? 'end'
          : 'start'
      const today = host.kind === 'time' ? null : readToday()
      const focus =
        activeEdge === 'start'
          ? (start ?? end ?? today)
          : (end ?? start ?? today)
      update({ open: true, activeEdge, focus, hover: null, today })
    },
    close(): void {
      if (host.disposed()) return
      update({ ...local.get(), open: false, hover: null, today: null })
    },
    setActiveEdge(edge): void {
      if (host.disposed()) return
      if (
        (edge !== 'start' && edge !== 'end') ||
        (edge === 'end' && host.count === 'single')
      )
        throw new RangeError('Invalid DateTimePicker edge')
      const current = local.get()
      if (!current.open) return
      update({
        ...current,
        activeEdge: edge,
        focus: endpointDate(host.state.get()[edge]) ?? current.focus,
      })
    },
    showPreviousMonth(): void {
      if (host.disposed()) return
      move((date) => addCalendarMonths(date, -1))
    },
    showNextMonth(): void {
      if (host.disposed()) return
      move((date) => addCalendarMonths(date, 1))
    },
    showToday(): void {
      if (host.disposed()) return
      const current = local.get()
      if (!current.open || host.kind === 'time') return
      const today = readToday()
      update({ ...current, focus: today, today })
    },
    pressKey(key, shiftKey): boolean {
      if (host.disposed()) return false
      if (!local.get().open || host.kind === 'time') return false
      switch (key) {
        case 'ArrowLeft':
          move((date) => addCalendarDays(date, -1))
          break
        case 'ArrowRight':
          move((date) => addCalendarDays(date, 1))
          break
        case 'ArrowUp':
          move((date) => addCalendarDays(date, -7))
          break
        case 'ArrowDown':
          move((date) => addCalendarDays(date, 7))
          break
        case 'PageUp':
          move((date) =>
            shiftKey ? addCalendarYears(date, -1) : addCalendarMonths(date, -1),
          )
          break
        case 'PageDown':
          move((date) =>
            shiftKey ? addCalendarYears(date, 1) : addCalendarMonths(date, 1),
          )
          break
        case 'Home':
          move((date) => calendarWeekStart(date, host.factors().firstWeekday))
          break
        case 'End':
          move((date) => calendarWeekEnd(date, host.factors().firstWeekday))
          break
        case 'Enter':
        case ' ':
          selectDay(local.get().focus!)
          break
        default:
          return false
      }
      return true
    },
    hoverDay(date): void {
      if (host.disposed()) return
      const current = local.get()
      const picker = host.state.get()
      if (
        !current.open ||
        host.kind === 'time' ||
        current.activeEdge !== 'end' ||
        endpointDate(picker.start) === null ||
        endpointDate(picker.end) !== null
      )
        return
      update({ ...current, hover: date })
    },
    selectDay,
  }
}
