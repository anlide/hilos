import {
  parseCalendarDate,
  type CalendarDate,
} from '../calendar/calendarDate.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import {
  createIntlDateTimeZoneAdapter,
  type DateTimeZoneAdapter,
} from '../timeZone/dateTimeZoneAdapter.js'
import {
  datePickerEndpoint,
  digitPickerEndpoint,
  editPickerText,
  leavePickerPart,
  loadPickerEndpoint,
  meridiemPickerEndpoint,
  selectPickerOffset,
  snapshotPickerEndpoint,
  stepPickerEndpoint,
  translatePickerHour,
  type PickerEndpointContext,
  type PickerEndpointDraft,
} from './dateTimePickerEndpoint.js'
import {
  resolveDateTimePickerFactors,
  resolveDateTimePickerLabels,
  type DateTimePickerFactors,
  type DateTimePickerLabels,
} from './dateTimePickerFactors.js'
import { validatePickerPartText } from './dateTimePickerPart.js'
import {
  validateDateTimePickerEndpointValue,
  validateDateTimePickerValue,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerKind,
  type DateTimePickerNumericPart,
  type DateTimePickerPart,
  type DateTimePickerResult,
  type DateTimePickerSnapshot,
  type DateTimePickerValue,
  type DateTimePickerValueMap,
} from './dateTimePickerValue.js'

export interface DateTimePickerCommonOptions<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> {
  readonly kind: K
  readonly count: C
  readonly value: DateTimePickerValue<K, C> | null
  readonly factors?: Partial<DateTimePickerFactors>
  readonly labels?: Partial<DateTimePickerLabels>
  readonly showSeconds?: boolean
  readonly disabled?: () => boolean
  readonly busy?: () => boolean
  readonly onChange?: (snapshot: DateTimePickerSnapshot<K, C>) => void
}

export type DateTimePickerOptions<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> = DateTimePickerCommonOptions<K, C> &
  (K extends 'datetime'
    ? {
        readonly timeZone: string
        readonly adapter?: DateTimeZoneAdapter
        readonly today: () => CalendarDate
        readonly minDate?: CalendarDate
      }
    : K extends 'date'
      ? {
          readonly timeZone?: never
          readonly adapter?: never
          readonly today: () => CalendarDate
          readonly minDate?: CalendarDate
        }
      : {
          readonly timeZone?: never
          readonly adapter?: never
          readonly today?: never
          readonly minDate?: never
        })

/** One coherent draft; the parent owns lifetime and explicit resets. */
export interface DateTimePickerController<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> {
  readonly kind: K
  readonly count: C
  readonly state: ReadonlySignal<DateTimePickerSnapshot<K, C>>
  readonly labels: Readonly<DateTimePickerLabels>
  readonly disabled: ReadonlySignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  setValue(value: DateTimePickerValue<K, C> | null): void
  setEndpointValue(
    edge: DateTimePickerEdge,
    value: DateTimePickerValueMap[K] | null,
  ): void
  setFactors(factors: Partial<DateTimePickerFactors>): void
  setShowSeconds(show: boolean): void
  setPartText(
    edge: DateTimePickerEdge,
    part: DateTimePickerNumericPart,
    text: string,
  ): void
  inputDigit(
    edge: DateTimePickerEdge,
    part: DateTimePickerNumericPart,
    digit: string,
  ): boolean
  leavePart(edge: DateTimePickerEdge, part: DateTimePickerPart): void
  setMeridiem(edge: DateTimePickerEdge, value: 'am' | 'pm'): void
  clearPart(edge: DateTimePickerEdge, part: DateTimePickerPart): void
  stepPart(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
    direction: -1 | 1,
  ): void
  selectOffset(edge: DateTimePickerEdge, epochMs: number): void
  setDate(edge: DateTimePickerEdge, date: CalendarDate): void
  dispose(): void
}

/**
 * Create a DOM-free segmented editor using only explicit calendar and zone inputs.
 * @throws RangeError When configuration or an external value is invalid.
 */
export function createDateTimePickerController<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
>(options: DateTimePickerOptions<K, C>): DateTimePickerController<K, C> {
  const { kind, count } = options
  if (
    !['date', 'time', 'datetime'].includes(kind) ||
    !['single', 'range'].includes(count)
  )
    throw new RangeError('Invalid DateTimePicker kind or count')
  if (
    kind === 'time'
      ? options.today !== undefined ||
        options.minDate !== undefined ||
        options.timeZone !== undefined ||
        options.adapter !== undefined
      : typeof options.today !== 'function'
  )
    throw new RangeError('Invalid DateTimePicker calendar context')
  if (
    kind === 'date' &&
    (options.timeZone !== undefined || options.adapter !== undefined)
  )
    throw new RangeError('A date picker has no time zone')
  if (
    kind === 'datetime' &&
    (typeof options.timeZone !== 'string' || options.timeZone.trim() === '')
  )
    throw new RangeError('A datetime picker requires a time zone')
  if (
    options.minDate !== undefined &&
    parseCalendarDate(options.minDate) === null
  )
    throw new RangeError('Invalid DateTimePicker minimum date')
  if (
    options.showSeconds !== undefined &&
    typeof options.showSeconds !== 'boolean'
  )
    throw new RangeError('Invalid DateTimePicker seconds visibility')
  for (const getter of [options.disabled, options.busy, options.onChange]) {
    if (getter !== undefined && typeof getter !== 'function')
      throw new RangeError('Invalid DateTimePicker callback')
  }
  const adapter =
    kind === 'datetime'
      ? (options.adapter ?? createIntlDateTimeZoneAdapter())
      : undefined
  if (
    adapter !== undefined &&
    (typeof adapter.project !== 'function' ||
      typeof adapter.resolve !== 'function')
  )
    throw new RangeError('Invalid DateTimePicker adapter')
  const labels = resolveDateTimePickerLabels(options.labels)
  let context: PickerEndpointContext = {
    kind,
    factors: resolveDateTimePickerFactors(options.factors),
    labels,
    showSeconds: options.showSeconds ?? false,
    adapter,
    timeZone: options.timeZone ?? '',
    today: options.today,
  }
  validateDateTimePickerValue(
    kind,
    count,
    options.value,
    adapter,
    context.timeZone,
  )
  let disposed = false
  const disabled = computedSignal(() => options.disabled?.() ?? false)
  const busy = computedSignal(() => options.busy?.() ?? false)
  // These guards call the original getters: plain getters need not be reactive.
  const blocked = (): boolean =>
    disposed || (options.disabled?.() ?? false) || (options.busy?.() ?? false)
  const endpoints = (
    value: DateTimePickerValue<K, C> | null,
  ): { start: PickerEndpointDraft; end: PickerEndpointDraft | null } => {
    const pair =
      count === 'range' && value !== null
        ? (value as readonly [
            DateTimePickerValueMap[K],
            DateTimePickerValueMap[K],
          ])
        : null
    return {
      start: loadPickerEndpoint(
        pair === null ? (value as DateTimePickerValueMap[K] | null) : pair[0],
        context,
      ),
      end:
        count === 'range'
          ? loadPickerEndpoint(pair?.[1] ?? null, context)
          : null,
    }
  }
  let drafts = endpoints(options.value)
  function snapshot(): DateTimePickerSnapshot<K, C> {
    const start = snapshotPickerEndpoint<K>(drafts.start, context)
    const end =
      drafts.end === null
        ? null
        : snapshotPickerEndpoint<K>(drafts.end, context)
    let result: DateTimePickerResult<K, C>
    if (
      start.result.status === 'empty' &&
      (end === null || end.result.status === 'empty')
    )
      result = { status: 'empty' }
    else if (
      start.result.status === 'valid' &&
      (end === null || end.result.status === 'valid')
    )
      result = {
        status: 'valid',
        value: (end === null
          ? start.result.value
          : [
              start.result.value,
              end.result.status === 'valid' ? end.result.value : null,
            ]) as DateTimePickerValue<K, C>,
      }
    else result = { status: 'unresolved' }
    return { start, end: end as DateTimePickerSnapshot<K, C>['end'], result }
  }
  const state = createSignal(snapshot())
  function publish(user: boolean): void {
    const next = snapshot()
    state.set(next)
    if (user) options.onChange?.(next)
  }
  function requireEdge(edge: DateTimePickerEdge): void {
    if (
      (edge !== 'start' && edge !== 'end') ||
      (edge === 'end' && count === 'single')
    )
      throw new RangeError('Invalid DateTimePicker edge')
  }
  function visible(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): boolean {
    requireEdge(edge)
    if (
      ![
        'year',
        'month',
        'day',
        'hour',
        'minute',
        'second',
        'meridiem',
        'offset',
      ].includes(part) ||
      (kind === 'date' &&
        ['hour', 'minute', 'second', 'meridiem', 'offset'].includes(part)) ||
      (kind === 'time' && ['year', 'month', 'day', 'offset'].includes(part))
    )
      throw new RangeError('Part does not belong to this DateTimePicker kind')
    return (
      state.get()[edge]?.parts.some((candidate) => candidate.part === part) ??
      false
    )
  }
  function update(
    edge: DateTimePickerEdge,
    next: PickerEndpointDraft,
    user = true,
  ): void {
    if (JSON.stringify(drafts[edge]) === JSON.stringify(next)) return
    drafts = { ...drafts, [edge]: next }
    publish(user)
  }
  return {
    kind,
    count,
    state,
    labels,
    disabled,
    busy,
    setValue(value): void {
      if (disposed) return
      validateDateTimePickerValue(kind, count, value, adapter, context.timeZone)
      const next = endpoints(value)
      if (JSON.stringify(next) === JSON.stringify(drafts)) return
      drafts = next
      publish(false)
    },
    setEndpointValue(edge, value): void {
      if (disposed) return
      requireEdge(edge)
      if (value !== null)
        validateDateTimePickerEndpointValue(
          kind,
          value,
          adapter,
          context.timeZone,
        )
      update(edge, loadPickerEndpoint(value, context), false)
    },
    setFactors(factors): void {
      if (disposed) return
      const next = {
        ...context,
        factors: resolveDateTimePickerFactors(factors),
      }
      if (JSON.stringify(next.factors) === JSON.stringify(context.factors))
        return
      drafts = {
        start: translatePickerHour(drafts.start, context, next),
        end:
          drafts.end === null
            ? null
            : translatePickerHour(drafts.end, context, next),
      }
      context = next
      publish(false)
    },
    setShowSeconds(show): void {
      if (disposed) return
      if (typeof show !== 'boolean')
        throw new RangeError('Expected seconds visibility')
      if (context.showSeconds === show) return
      context = { ...context, showSeconds: show }
      publish(false)
    },
    setPartText(edge, part, text): void {
      if (blocked() || !visible(edge, part)) return
      validatePickerPartText(part, text)
      update(edge, editPickerText(drafts[edge]!, part, text, context))
    },
    inputDigit(edge, part, digit): boolean {
      if (blocked() || !visible(edge, part)) return false
      if (
        typeof digit !== 'string' ||
        digit.length !== 1 ||
        /[^0-9]/.test(digit)
      )
        throw new RangeError('Expected one ASCII digit')
      const next = digitPickerEndpoint(drafts[edge]!, part, digit, context)
      update(edge, next.draft)
      return next.complete
    },
    leavePart(edge, part): void {
      if (
        blocked() ||
        !visible(edge, part) ||
        part === 'meridiem' ||
        part === 'offset'
      )
        return
      update(edge, leavePickerPart(drafts[edge]!, part, context), false)
    },
    setMeridiem(edge, value): void {
      if (blocked() || !visible(edge, 'meridiem')) return
      if (value !== 'am' && value !== 'pm')
        throw new RangeError('Expected am or pm')
      update(edge, meridiemPickerEndpoint(drafts[edge]!, value, context))
    },
    clearPart(edge, part): void {
      if (blocked() || !visible(edge, part)) return
      const draft = drafts[edge]!
      update(
        edge,
        part === 'offset'
          ? draft.selected === null
            ? draft
            : { ...draft, selected: null, loaded: null }
          : part === 'meridiem'
            ? meridiemPickerEndpoint(draft, '', context)
            : editPickerText(draft, part, '', context),
      )
    },
    stepPart(edge, part, direction): void {
      if (blocked() || !visible(edge, part)) return
      if (direction !== -1 && direction !== 1)
        throw new RangeError('Expected a step direction')
      update(edge, stepPickerEndpoint(drafts[edge]!, part, direction, context))
    },
    selectOffset(edge, epochMs): void {
      if (blocked() || !visible(edge, 'offset')) return
      update(edge, selectPickerOffset(drafts[edge]!, epochMs, context))
    },
    setDate(edge, date): void {
      if (blocked()) return
      requireEdge(edge)
      if (kind === 'time' || parseCalendarDate(date) === null)
        throw new RangeError(
          'Expected a calendar date for a date or datetime picker',
        )
      update(edge, datePickerEndpoint(drafts[edge]!, date, context))
    },
    dispose(): void {
      disposed = true
    },
  }
}
