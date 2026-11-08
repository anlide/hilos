import {
  addCalendarDays,
  createCalendarDate,
  parseCalendarDate,
  type CalendarDate,
} from '../calendar/calendarDate.js'
import type {
  DateTimeZoneAdapter,
  DateTimeZoneResolution,
  LocalDateTimeParts,
} from '../timeZone/dateTimeZoneAdapter.js'
import {
  fillDateTimePickerTemplate,
  type DateTimePickerFactors,
  type DateTimePickerLabels,
} from './dateTimePickerFactors.js'
import {
  inputPickerDigit,
  pickerHour12,
  pickerHour23,
  pickerOffsetText,
  pickerPartInvalid,
  pickerPartRange,
  pickerPartWidth,
  readPickerPart,
  stepPickerNumber,
  type DateTimePickerTexts,
} from './dateTimePickerPart.js'
import type {
  DateTimePickerEndpointResult,
  DateTimePickerEndpointSnapshot,
  DateTimePickerKind,
  DateTimePickerNumericPart,
  DateTimePickerPart,
  DateTimePickerPartDraft,
  DateTimePickerValueMap,
} from './dateTimePickerValue.js'

/** Internal context captured by the controller; no environment defaults. */
export interface PickerEndpointContext {
  readonly kind: DateTimePickerKind
  readonly factors: Readonly<DateTimePickerFactors>
  readonly labels: Readonly<DateTimePickerLabels>
  readonly showSeconds: boolean
  readonly adapter: DateTimeZoneAdapter | undefined
  readonly timeZone: string
  readonly today: (() => CalendarDate) | undefined
}

/** Private precision and occurrence selection belong to the same draft as the text. */
export interface PickerEndpointDraft {
  readonly texts: DateTimePickerTexts
  readonly meridiem: '' | 'am' | 'pm'
  readonly millisecond: number
  readonly selected: number | null
  readonly loaded: {
    readonly epochMs: number
    readonly resolution: DateTimeZoneResolution
  } | null
  readonly unresolvedHour: {
    readonly text: string
    readonly meridiem: '' | 'am' | 'pm'
  } | null
}

const dateParts = ['year', 'month', 'day'] as const
const timeParts = ['hour', 'minute', 'second'] as const

function numericParts(
  context: PickerEndpointContext,
): readonly DateTimePickerNumericPart[] {
  return context.kind === 'date'
    ? dateParts
    : context.kind === 'time'
      ? timeParts
      : [...dateParts, ...timeParts]
}

function visibleParts(
  context: PickerEndpointContext,
  fold = false,
): DateTimePickerPart[] {
  const parts: DateTimePickerPart[] =
    context.kind === 'time' ? [] : [...context.factors.dateOrder]
  if (context.kind !== 'date') {
    parts.push('hour', 'minute')
    if (context.showSeconds) parts.push('second')
    if (context.factors.hourCycle === 'h12') parts.push('meridiem')
  }
  if (fold) parts.push('offset')
  return parts
}

export function emptyPickerEndpoint(): PickerEndpointDraft {
  return {
    texts: {
      year: '',
      month: '',
      day: '',
      hour: '',
      minute: '',
      second: '',
    },
    meridiem: '',
    millisecond: 0,
    selected: null,
    loaded: null,
    unresolvedHour: null,
  }
}

export function loadPickerEndpoint<K extends DateTimePickerKind>(
  value: DateTimePickerValueMap[K] | null,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  const empty = emptyPickerEndpoint()
  if (value === null) return empty
  if (context.kind === 'date') {
    const date = parseCalendarDate(value as string)!
    return {
      ...empty,
      texts: {
        ...empty.texts,
        year: String(date.year).padStart(4, '0'),
        month: pad(date.month),
        day: pad(date.day),
      },
    }
  }
  if (context.kind === 'time') {
    const [hour, minute, second] = (value as string).split(':')
    return {
      ...empty,
      texts: {
        ...empty.texts,
        hour:
          context.factors.hourCycle === 'h12'
            ? pickerHour12(Number(hour))
            : hour!,
        minute: minute!,
        second: second!,
      },
      meridiem: Number(hour) < 12 ? 'am' : 'pm',
    }
  }
  const projection = context.adapter!.project(value as number, context.timeZone)
  if (projection.status !== 'valid')
    throw new RangeError('Cannot project DateTimePicker value')
  const parts = projection.parts
  return {
    texts: {
      year: String(parts.year).padStart(4, '0'),
      month: pad(parts.month),
      day: pad(parts.day),
      hour:
        context.factors.hourCycle === 'h12'
          ? pickerHour12(parts.hour)
          : pad(parts.hour),
      minute: pad(parts.minute),
      second: pad(parts.second),
    },
    meridiem: parts.hour < 12 ? 'am' : 'pm',
    millisecond: parts.millisecond,
    selected: value as number,
    loaded: {
      epochMs: value as number,
      resolution: context.adapter!.resolve(parts, context.timeZone),
    },
    unresolvedHour: null,
  }
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

function read(
  draft: PickerEndpointDraft,
  part: DateTimePickerNumericPart,
  context: PickerEndpointContext,
): number | null {
  return readPickerPart(
    part,
    draft.texts[part],
    pickerPartRange(part, draft.texts, context.factors.hourCycle),
  )
}

/** Semantic identity ignores padding, labels and hour-cycle presentation. */
function coordinateKey(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): string {
  const values = numericParts(context).map((part) => {
    const value = read(draft, part, context)
    if (value === null) return `${part}:${draft.texts[part]}`
    if (part === 'hour' && context.factors.hourCycle === 'h12') {
      return draft.meridiem === ''
        ? `hour:${draft.texts.hour}:unresolved`
        : pickerHour23(value, draft.meridiem)
    }
    return value
  })
  return JSON.stringify([values, draft.millisecond])
}

function isEmpty(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): boolean {
  return visibleParts(context).every((part) =>
    part === 'meridiem'
      ? draft.meridiem === ''
      : draft.texts[part as DateTimePickerNumericPart] === '',
  )
}

function finishEdit(
  previous: PickerEndpointDraft,
  next: PickerEndpointDraft,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  if (isEmpty(next, context)) return emptyPickerEndpoint()
  // Hidden zero precision starts with the first edit, not with visibility.
  const initialized =
    context.kind !== 'date' &&
    !context.showSeconds &&
    isEmpty(previous, context) &&
    next.texts.second === ''
      ? { ...next, texts: { ...next.texts, second: '00' } }
      : next
  return coordinateKey(previous, context) ===
    coordinateKey(initialized, context)
    ? initialized
    : { ...initialized, selected: null, loaded: null }
}

export function editPickerText(
  draft: PickerEndpointDraft,
  part: DateTimePickerNumericPart,
  text: string,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  if (draft.texts[part] === text) return draft
  return finishEdit(
    draft,
    {
      ...draft,
      texts: { ...draft.texts, [part]: text },
      unresolvedHour: part === 'hour' ? null : draft.unresolvedHour,
    },
    context,
  )
}

export function digitPickerEndpoint(
  draft: PickerEndpointDraft,
  part: DateTimePickerNumericPart,
  digit: string,
  context: PickerEndpointContext,
): { draft: PickerEndpointDraft; complete: boolean } {
  const next = inputPickerDigit(
    part,
    draft.texts[part],
    digit,
    pickerPartRange(part, draft.texts, context.factors.hourCycle),
  )
  return {
    draft: editPickerText(draft, part, next.text, context),
    complete: next.complete,
  }
}

export function meridiemPickerEndpoint(
  draft: PickerEndpointDraft,
  meridiem: '' | 'am' | 'pm',
  context: PickerEndpointContext,
): PickerEndpointDraft {
  if (meridiem === draft.meridiem) return draft
  return finishEdit(
    draft,
    {
      ...draft,
      meridiem,
      unresolvedHour: meridiem !== draft.meridiem ? null : draft.unresolvedHour,
    },
    context,
  )
}

export function datePickerEndpoint(
  draft: PickerEndpointDraft,
  date: CalendarDate,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  const parts = parseCalendarDate(date)!
  return finishEdit(
    draft,
    {
      ...draft,
      texts: {
        ...draft.texts,
        year: String(parts.year).padStart(4, '0'),
        month: pad(parts.month),
        day: pad(parts.day),
      },
    },
    context,
  )
}

export function leavePickerPart(
  draft: PickerEndpointDraft,
  part: DateTimePickerNumericPart,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  const value = read(draft, part, context)
  return value === null
    ? draft
    : {
        ...draft,
        texts: {
          ...draft.texts,
          [part]: String(value).padStart(pickerPartWidth(part), '0'),
        },
      }
}

/** Translate meaning; retain an unresolved h12 hour across a round trip. */
export function translatePickerHour(
  draft: PickerEndpointDraft,
  previous: PickerEndpointContext,
  next: PickerEndpointContext,
): PickerEndpointDraft {
  if (
    previous.kind === 'date' ||
    previous.factors.hourCycle === next.factors.hourCycle
  )
    return draft
  const hour = read(draft, 'hour', previous)
  if (next.factors.hourCycle === 'h23') {
    const invalid = pickerPartInvalid(
      'hour',
      draft.texts.hour,
      pickerPartRange('hour', draft.texts, previous.factors.hourCycle),
    )
    if (
      !invalid &&
      draft.texts.hour !== '' &&
      (hour === null || draft.meridiem === '')
    )
      return {
        ...draft,
        texts: { ...draft.texts, hour: '' },
        unresolvedHour: { text: draft.texts.hour, meridiem: draft.meridiem },
      }
    if (hour === null || draft.meridiem === '') return draft
    return {
      ...draft,
      texts: { ...draft.texts, hour: pad(pickerHour23(hour, draft.meridiem)) },
    }
  }
  if (draft.unresolvedHour !== null)
    return {
      ...draft,
      texts: { ...draft.texts, hour: draft.unresolvedHour.text },
      meridiem: draft.unresolvedHour.meridiem,
      unresolvedHour: null,
    }
  if (hour === null) return draft
  return {
    ...draft,
    texts: { ...draft.texts, hour: pickerHour12(hour) },
    meridiem: hour < 12 ? 'am' : 'pm',
  }
}

function localParts(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): LocalDateTimeParts | null {
  const year = read(draft, 'year', context),
    month = read(draft, 'month', context),
    day = read(draft, 'day', context)
  const hour = read(draft, 'hour', context),
    minute = read(draft, 'minute', context),
    second = read(draft, 'second', context)
  if (
    year === null ||
    month === null ||
    day === null ||
    hour === null ||
    minute === null ||
    second === null ||
    (context.factors.hourCycle === 'h12' && draft.meridiem === '')
  )
    return null
  return {
    year,
    month,
    day,
    hour:
      context.factors.hourCycle === 'h12'
        ? pickerHour23(hour, draft.meridiem as 'am' | 'pm')
        : hour,
    minute,
    second,
    millisecond: draft.millisecond,
  }
}

function endpointResolution(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): DateTimeZoneResolution | null {
  if (context.kind !== 'datetime') return null
  if (draft.loaded !== null) return draft.loaded.resolution
  const parts = localParts(draft, context)
  return parts === null
    ? null
    : context.adapter!.resolve(parts, context.timeZone)
}

export function selectPickerOffset(
  draft: PickerEndpointDraft,
  epochMs: number,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  const resolution = endpointResolution(draft, context)
  return epochMs !== draft.selected &&
    resolution?.status === 'fold' &&
    resolution.candidates.some((candidate) => candidate.epochMs === epochMs)
    ? { ...draft, selected: epochMs, loaded: null }
    : draft
}

/** Hour stepping follows the adapter's boundary facts, without crossing the local date. */
export function stepPickerEndpoint(
  draft: PickerEndpointDraft,
  part: DateTimePickerPart,
  direction: -1 | 1,
  context: PickerEndpointContext,
): PickerEndpointDraft {
  if (part === 'offset') {
    const resolution = endpointResolution(draft, context)
    if (resolution?.status !== 'fold') return draft
    const index = resolution.candidates.findIndex(
      (candidate) => candidate.epochMs === draft.selected,
    )
    const next = stepPickerNumber(
      index < 0 ? null : index,
      [0, resolution.candidates.length - 1],
      direction,
    )
    return {
      ...draft,
      selected: resolution.candidates[next]!.epochMs,
      loaded: null,
    }
  }
  if (part === 'meridiem')
    return meridiemPickerEndpoint(
      draft,
      draft.meridiem === ''
        ? direction === 1
          ? 'am'
          : 'pm'
        : draft.meridiem === 'am'
          ? 'pm'
          : 'am',
      context,
    )
  const range = pickerPartRange(part, draft.texts, context.factors.hourCycle)
  let value = stepPickerNumber(read(draft, part, context), range, direction)
  if (part === 'year' && draft.texts.year === '') {
    const today = parseCalendarDate(context.today!())
    if (today === null)
      throw new RangeError('today() must return a calendar date')
    value = today.year
  }
  const first = editPickerText(
    draft,
    part,
    String(value).padStart(pickerPartWidth(part), '0'),
    context,
  )
  if (part !== 'hour' || context.kind !== 'datetime') return first
  let next = first
  const visited = new Set<number>()
  for (let attempt = 0; attempt < range[1] - range[0] + 1; attempt++) {
    const resolution = endpointResolution(next, context)
    if (resolution?.status !== 'gap') return next
    const boundary = context.adapter!.project(
      (direction === 1 ? resolution.after : resolution.before).epochMs,
      context.timeZone,
    )
    const parts = localParts(next, context)
    if (
      boundary.status !== 'valid' ||
      parts === null ||
      boundary.parts.year !== parts.year ||
      boundary.parts.month !== parts.month ||
      boundary.parts.day !== parts.day
    )
      return first
    const projectedHour =
      context.factors.hourCycle === 'h12'
        ? Number(pickerHour12(boundary.parts.hour))
        : boundary.parts.hour
    value =
      visited.has(projectedHour) ||
      projectedHour === read(next, 'hour', context)
        ? stepPickerNumber(read(next, 'hour', context), range, direction)
        : projectedHour
    if (visited.has(value)) return first
    visited.add(value)
    next = editPickerText(draft, 'hour', pad(value), context)
  }
  return first
}

function endpointResult<K extends DateTimePickerKind>(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
  resolution: DateTimeZoneResolution | null,
): DateTimePickerEndpointResult<K> {
  if (isEmpty(draft, context)) return { status: 'empty' }
  const parts = numericParts(context)
  for (const part of parts) {
    if (
      pickerPartInvalid(
        part,
        draft.texts[part],
        pickerPartRange(part, draft.texts, context.factors.hourCycle),
      )
    )
      return {
        status: 'invalid',
        reason: dateParts.some((datePart) => datePart === part)
          ? 'date'
          : 'time',
      }
  }
  if (
    parts.some((part) => read(draft, part, context) === null) ||
    (context.kind !== 'date' &&
      context.factors.hourCycle === 'h12' &&
      draft.meridiem === '')
  )
    return { status: 'incomplete' }
  if (context.kind === 'date')
    return {
      status: 'valid',
      value: createCalendarDate(
        Number(draft.texts.year),
        Number(draft.texts.month),
        Number(draft.texts.day),
      ) as DateTimePickerValueMap[K],
    }
  if (context.kind === 'time') {
    const hour =
      context.factors.hourCycle === 'h12'
        ? pickerHour23(Number(draft.texts.hour), draft.meridiem as 'am' | 'pm')
        : Number(draft.texts.hour)
    return {
      status: 'valid',
      value:
        `${pad(hour)}:${pad(Number(draft.texts.minute))}:${pad(Number(draft.texts.second))}` as DateTimePickerValueMap[K],
    }
  }
  if (draft.loaded !== null)
    return {
      status: 'valid',
      value: draft.loaded.epochMs as DateTimePickerValueMap[K],
    }
  if (resolution === null) return { status: 'incomplete' }
  if (resolution.status === 'unique')
    return {
      status: 'valid',
      value: resolution.candidate.epochMs as DateTimePickerValueMap[K],
    }
  if (resolution.status === 'fold') {
    const candidate = resolution.candidates.find(
      (candidate) => candidate.epochMs === draft.selected,
    )
    return candidate === undefined
      ? { status: 'ambiguous', candidates: resolution.candidates }
      : {
          status: 'valid',
          value: candidate.epochMs as DateTimePickerValueMap[K],
        }
  }
  return resolution.status === 'gap' ? { status: 'gap' } : resolution
}

function formatDate(
  parts: Pick<LocalDateTimeParts, 'year' | 'month' | 'day'>,
  context: PickerEndpointContext,
): string {
  return context.factors.dateOrder
    .map((part) => String(parts[part]).padStart(part === 'year' ? 4 : 2, '0'))
    .join(context.factors.dateSeparator)
}

function formatTime(
  parts: Pick<LocalDateTimeParts, 'hour' | 'minute' | 'second'>,
  context: PickerEndpointContext,
  seconds: boolean,
): string {
  const h12 = context.factors.hourCycle === 'h12'
  return `${h12 ? pickerHour12(parts.hour) : pad(parts.hour)}:${pad(parts.minute)}${seconds ? `:${pad(parts.second)}` : ''}${h12 ? ` ${parts.hour < 12 ? context.factors.amLabel : context.factors.pmLabel}` : ''}`
}

/** Format entered date fields without requiring a valid calendar coordinate. */
function formatDraftDate(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): string | undefined {
  if (
    context.kind === 'time' ||
    draft.texts.year.length !== 4 ||
    dateParts.some((part) => draft.texts[part] === '')
  )
    return undefined
  return context.factors.dateOrder
    .map((part) => draft.texts[part].padStart(pickerPartWidth(part), '0'))
    .join(context.factors.dateSeparator)
}

/** Format only known visible clock fields, retaining invalid input verbatim. */
function formatDraftTime(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): string | undefined {
  const parts = timeParts.filter(
    (part) => part !== 'second' || context.showSeconds,
  )
  const h12 = context.factors.hourCycle === 'h12'
  if (
    context.kind === 'date' ||
    parts.some((part) => draft.texts[part] === '') ||
    (h12 && draft.meridiem === '')
  )
    return undefined
  return `${parts.map((part) => draft.texts[part].padStart(2, '0')).join(':')}${h12 ? ` ${draft.meridiem === 'am' ? context.factors.amLabel : context.factors.pmLabel}` : ''}`
}

/** Advance only the local millisecond; shared calendar arithmetic owns a day rollover. */
function nextLocalMillisecond(parts: LocalDateTimeParts): LocalDateTimeParts {
  const total =
    ((parts.hour * 60 + parts.minute) * 60 + parts.second) * 1000 +
    parts.millisecond +
    1
  const date =
    total < 86400000
      ? parts
      : parseCalendarDate(
          addCalendarDays(
            createCalendarDate(parts.year, parts.month, parts.day),
            1,
          ),
        )!
  return {
    year: date.year,
    month: date.month,
    day: date.day,
    hour: Math.floor(total / 3600000) % 24,
    minute: Math.floor(total / 60000) % 60,
    second: Math.floor(total / 1000) % 60,
    millisecond: total % 1000,
  }
}

function endpointReason<K extends DateTimePickerKind>(
  draft: PickerEndpointDraft,
  result: DateTimePickerEndpointResult<K>,
  resolution: DateTimeZoneResolution | null,
  context: PickerEndpointContext,
): string | null {
  if (
    result.status !== 'invalid' &&
    result.status !== 'gap' &&
    result.status !== 'ambiguous'
  )
    return null
  const date = formatDraftDate(draft, context)
  const slots = {
    date,
    time: formatDraftTime(draft, context),
    zone: context.timeZone,
  }
  if (result.status === 'invalid')
    return fillDateTimePickerTemplate(
      result.reason === 'date'
        ? context.labels.invalidDate
        : result.reason === 'time'
          ? context.labels.invalidTime
          : context.labels.invalidZone,
      slots,
    )
  const boundaries = {
    from: '',
    to: '',
  }
  if (resolution?.status === 'gap') {
    const before = context.adapter!.project(
      resolution.before.epochMs,
      context.timeZone,
    )
    const after = context.adapter!.project(
      resolution.after.epochMs,
      context.timeZone,
    )
    if (before.status === 'valid' && after.status === 'valid') {
      const boundaryText = (boundary: LocalDateTimeParts): string => {
        const boundaryDate = formatDate(boundary, context)
        return `${boundaryDate === date ? '' : `${boundaryDate}, `}${formatTime(boundary, context, context.showSeconds || boundary.second !== 0)}`
      }
      boundaries.from = boundaryText(nextLocalMillisecond(before.parts))
      boundaries.to = boundaryText(after.parts)
    }
  }
  return fillDateTimePickerTemplate(
    result.status === 'gap' ? context.labels.gap : context.labels.ambiguous,
    { ...slots, ...boundaries },
  )
}

export function snapshotPickerEndpoint<K extends DateTimePickerKind>(
  draft: PickerEndpointDraft,
  context: PickerEndpointContext,
): DateTimePickerEndpointSnapshot<K> {
  const resolution = endpointResolution(draft, context)
  const result = endpointResult<K>(draft, context, resolution)
  const candidates = resolution?.status === 'fold' ? resolution.candidates : []
  const selectedIndex = candidates.findIndex(
    (candidate) => candidate.epochMs === draft.selected,
  )
  const offsetChoices = candidates.map((candidate) => ({
    ...candidate,
    text: pickerOffsetText(candidate.offsetSeconds),
    selected: candidate.epochMs === draft.selected,
  }))
  const parts = visibleParts(context, candidates.length > 0).map(
    (part, index, visible): DateTimePickerPartDraft => {
      let text: string,
        valueNow: number | null,
        valueMin: number,
        valueMax: number
      if (part === 'meridiem') {
        text =
          draft.meridiem === ''
            ? ''
            : draft.meridiem === 'am'
              ? context.factors.amLabel
              : context.factors.pmLabel
        valueNow =
          draft.meridiem === '' ? null : draft.meridiem === 'am' ? 0 : 1
        valueMin = 0
        valueMax = 1
      } else if (part === 'offset') {
        text = offsetChoices[selectedIndex]?.text ?? ''
        valueNow = selectedIndex < 0 ? null : selectedIndex
        valueMin = 0
        valueMax = Math.max(0, candidates.length - 1)
      } else {
        text = draft.texts[part]
        ;[valueMin, valueMax] = pickerPartRange(
          part,
          draft.texts,
          context.factors.hourCycle,
        )
        valueNow = read(draft, part, context)
      }
      const prefix =
        index === 0
          ? ''
          : part === 'meridiem' || part === 'offset'
            ? ' '
            : part === 'hour'
              ? ', '
              : visible[index - 1] === 'hour' || part === 'second'
                ? ':'
                : context.factors.dateSeparator
      const valueText =
        text === ''
          ? context.factors.placeholders[part]
          : valueNow === null
            ? text
            : part === 'month'
              ? context.factors.monthNames[valueNow - 1]!.full
              : part === 'meridiem' || part === 'offset'
                ? text
                : String(valueNow).padStart(part === 'year' ? 4 : 2, '0')
      return {
        part,
        text,
        prefix,
        placeholder: context.factors.placeholders[part],
        label: context.labels[part],
        valueNow,
        valueMin,
        valueMax,
        valueText,
      }
    },
  )
  return {
    parts,
    result,
    reason: endpointReason(draft, result, resolution, context),
    offsetChoices,
  }
}
