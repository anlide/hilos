# DateTimePicker

Read this before implementing or consuming the shared date, time or datetime
picker, its calendar, its time-zone adapter, or its SDK views. This is the
normative contract for HIL-1965. The markers identify the leaf that makes each
block true, following
[rule-authoring.md](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Existing foundations and scope

Keep the distinction between a calendar date, a clock time and an absolute
moment from [time-zones.md](../architecture/time-zones.md). Ordinary moment
display continues through `formatMoment()`. Editing calendar fields in an
explicit zone does not introduce another moment display formatter.

Follow the controller/view split in
[multiframework-core.md](multiframework-core.md#primitives-a-headless-controller-plus-a-thin-view),
the owning public entries in [sdk-packaging.md](sdk-packaging.md#public-entrypoints-and-their-graph),
and the structural rules in [accessibility.md](accessibility.md).
`ReadonlySignal` is existing local browser reactivity, not a wire signal or
`Hilos::$rt`. The [modal](../../../framework/frontend/core/src/primitives/modal.ts)
and [loading-button](../../../framework/frontend/core/src/primitives/loadingButton.ts)
controllers already demonstrate that split.

The existing [local date-time helpers](../../../framework/frontend/core/src/timeZone/localDateTime.ts)
fill and parse an explicit-zone editor. `localDateTimeValue()` discards
subsecond precision; `parseLocalDateTimeValue()` returns `null` for gaps and
folds as well as invalid input. They are not the precise adapter specified
below. The existing [calendar engine](../../../framework/frontend/core/src/timeZone/calendarPeriods.ts)
supplies Gregorian coordinates, explicit `Intl` field reading and IANA offset
intervals. The current [maintenance form](../../../framework/frontend/core/src/admin/status/hilosStatusWorkActions.ts)
uses the old helpers and has its own limited preservation of an unchanged saved
second; that is not the picker's general precision policy.

This document changes no executable code, package exports, DB fields or
migrations, runtime item fields, wire signals, DTOs, topology, product routes
or test commands. It adds no page, table or subscription owner. Implementation
and component tests belong to the leaves named below. The i18n factor provider
(HIL-1424), scheduler Calendar (HIL-145), table `date_range` design, native-input
guards and a bundled time-zone database are outside this contract.

## Values and parts

Use two independent axes, `kind` and `count`, for one component. A range is a
pair of the same kind; it is not a string containing two formatted values.

```ts
import type { CalendarDate } from '@hilos/core/base'

type DateTimePickerKind = 'date' | 'time' | 'datetime'
type DateTimePickerCount = 'single' | 'range'
type ClockTime = string // Strict 24-hour HH:mm:ss, no date, zone or fractions.
interface DateTimePickerValueMap {
  date: CalendarDate
  time: ClockTime
  datetime: number // Integer epoch milliseconds, editable Gregorian local year.
}
type DateTimePickerValue<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> = C extends 'range'
  ? readonly [DateTimePickerValueMap[K], DateTimePickerValueMap[K]]
  : DateTimePickerValueMap[K]
type DateTimePickerEdge = 'start' | 'end'
type DateTimePickerPart =
  | 'year' | 'month' | 'day' | 'hour' | 'minute' | 'second'
  | 'meridiem' | 'offset'
type DateTimePickerNumericPart = Exclude<DateTimePickerPart, 'meridiem' | 'offset'>
interface DateTimePickerPartDraft {
  readonly part: DateTimePickerPart
  readonly text: string
  readonly prefix: string
  readonly placeholder: string
  readonly label: string
  readonly valueNow: number | null
  readonly valueMin: number
  readonly valueMax: number
  readonly valueText: string
}
interface DateTimePickerOffsetChoice {
  readonly epochMs: number
  readonly offsetSeconds: number
  readonly text: string
  readonly selected: boolean
}
```

All six combinations have the following value types. `null` is the external
empty value; a partial range exists only as a draft, never as a canonical pair.

| Kind | Count | `DateTimePickerValue<K, C>` |
|---|---|---|
| `date` | `single` | `CalendarDate` |
| `date` | `range` | `readonly [CalendarDate, CalendarDate]` |
| `time` | `single` | `ClockTime` |
| `time` | `range` | `readonly [ClockTime, ClockTime]` |
| `datetime` | `single` | `number` |
| `datetime` | `range` | `readonly [number, number]` |

String aliases do not prove validity. Accept only real Gregorian dates in years
`0001..9999`, clock times from `00:00:00` through `23:59:59`, and finite integer
epoch milliseconds whose local Gregorian year can be edited. Zero and negative
moments are valid; only `null` means absence. Do not normalize an overflowing
date into a different date. Clock times carry no date, zone or fractions;
datetime values identify moments, not a number adjusted to look like a local
epoch. The constructor, `setValue()` and `setEndpointValue()` reject invalid
configuration or external values with `RangeError` before changing state.

## Time-zone facts

The adapter resolves local calendar parts and projects absolute moments. Its
types carry facts, without display labels or a policy for choosing a fold.

```ts
interface LocalDateTimeParts {
  readonly year: number
  readonly month: number
  readonly day: number
  readonly hour: number
  readonly minute: number
  readonly second: number
  readonly millisecond: number
}
interface DateTimeZoneCandidate {
  readonly epochMs: number
  readonly offsetSeconds: number // Signed local-minus-UTC seconds.
}
type DateTimeZoneResolution =
  | { readonly status: 'invalid'; readonly reason: 'date' | 'time' | 'zone' }
  | { readonly status: 'unique'; readonly candidate: DateTimeZoneCandidate }
  | {
      readonly status: 'gap'
      readonly before: DateTimeZoneCandidate
      readonly after: DateTimeZoneCandidate
    }
  | {
      readonly status: 'fold'
      readonly candidates: readonly [
        DateTimeZoneCandidate, DateTimeZoneCandidate, ...DateTimeZoneCandidate[],
      ]
    }
type DateTimeZoneProjection =
  | { readonly status: 'invalid'; readonly reason: 'moment' | 'zone' }
  | {
      readonly status: 'valid'
      readonly parts: LocalDateTimeParts
      readonly candidate: DateTimeZoneCandidate
    }
interface DateTimeZoneAdapter {
  resolve(parts: LocalDateTimeParts, timeZone: string): DateTimeZoneResolution
  project(epochMs: number, timeZone: string): DateTimeZoneProjection
}
declare function createIntlDateTimeZoneAdapter(): DateTimeZoneAdapter
```

`resolve()` distinguishes an invalid date, time or zone from a valid local
coordinate that lies in a gap or fold. `unique` carries one candidate. A fold
carries at least two candidates ordered by `epochMs`. `offsetSeconds` is signed
local-minus-UTC seconds, including historical second offsets; do not round it
to hours or minutes. `project()` returns the exact candidate and all local
parts, including `millisecond`, for a loaded moment. An unusable zone is
reported, never replaced by another zone.

For a gap, `before` is the last existing moment immediately before the missing
local interval, and `after` is the first existing moment after it. Their
projections provide the calendar parts and the size of the missing interval.
These facts must also describe half-hour transitions and whole skipped dates;
neither a gap nor a local day is assumed to be a fixed one-hour or 24-hour
duration.

Build the standard adapter on the existing `localDateTime` / `calendarPeriods`
engine, preserving compatibility of the old parser/value helpers. UTC works
without `Intl`. For other supported IANA zones, browser `Intl` may read numeric
calendar fields and offsets with explicit Gregorian calendar, `latn` digits
and `h23` hours. It must not derive display factors or call a server. This is
the accepted D-297 boundary: equal inputs and equal zone rules give equal
behavior; the picker does not supply a uniform tzdata version or a second
time-zone engine.

Resolve and project a fixed Warsaw fold without consulting the clock:

```ts
import { createIntlDateTimeZoneAdapter } from '@hilos/core/base'

const adapter = createIntlDateTimeZoneAdapter()
const timeZone = 'Europe/Warsaw'
const parts = {
  year: 2026,
  month: 10,
  day: 25,
  hour: 2,
  minute: 30,
  second: 45,
  millisecond: 123,
}
const resolution = adapter.resolve(parts, timeZone)

if (resolution.status !== 'fold') throw new Error('Expected a fold')

console.log('Candidates:', resolution.candidates)

const selectedCandidate = resolution.candidates[1]
const projection = adapter.project(selectedCandidate.epochMs, timeZone)
if (
  projection.status !== 'valid' ||
  projection.candidate.epochMs !== selectedCandidate.epochMs ||
  projection.candidate.offsetSeconds !== selectedCandidate.offsetSeconds ||
  projection.parts.millisecond !== parts.millisecond
) {
  throw new Error('Projection did not preserve the selected candidate')
}
console.log('Projection:', projection)
```

## One coherent draft

Each endpoint retains its own status. Only a complete, unambiguous endpoint
has a value. The overall result publishes a value only when all required
endpoints do.

```ts
type DateTimePickerEndpointResult<K extends DateTimePickerKind> =
  | { readonly status: 'empty' }
  | { readonly status: 'incomplete' }
  | { readonly status: 'invalid'; readonly reason: 'date' | 'time' | 'zone' }
  | { readonly status: 'gap' }
  | {
      readonly status: 'ambiguous'
      readonly candidates: readonly [
        DateTimeZoneCandidate, DateTimeZoneCandidate, ...DateTimeZoneCandidate[],
      ]
    }
  | { readonly status: 'valid'; readonly value: DateTimePickerValueMap[K] }
interface DateTimePickerEndpointSnapshot<K extends DateTimePickerKind> {
  readonly parts: readonly DateTimePickerPartDraft[]
  readonly result: DateTimePickerEndpointResult<K>
  readonly reason: string | null
  readonly offsetChoices: readonly DateTimePickerOffsetChoice[]
}
type DateTimePickerResult<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> =
  | { readonly status: 'empty' }
  | { readonly status: 'unresolved' }
  | { readonly status: 'valid'; readonly value: DateTimePickerValue<K, C> }
interface DateTimePickerSnapshot<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> {
  readonly start: DateTimePickerEndpointSnapshot<K>
  readonly end: C extends 'range' ? DateTimePickerEndpointSnapshot<K> : null
  readonly result: DateTimePickerResult<K, C>
}
```

Publish one immutable snapshot: visible parts and results must agree in every
publication, and publish a new snapshot only when its state changes. `parts`
holds visible parts in factor order, with raw text and ready-to-render display
and accessible values, including incomplete input. Each part's `prefix`,
`placeholder` and `label` provide its separator, empty hint and accessible name;
the latter two come from `factors.placeholders[part]` and `labels[part]`.
`text` is the current input; meridiem uses the factor's AM/PM label and offset
uses the selected candidate's text. `valueNow` is set only for a readable
in-range value; an h12 hour is `1..12`, meridiem is `0` for AM or `1` for PM,
and offset is the selected candidate index. `valueMin` and `valueMax` give the
part's range; offset's maximum is candidate count minus one, or zero when there
are no choices. `valueText` is the full month name, the meridiem or offset text,
a zero-padded number, the placeholder when empty, or raw text when incomplete
or out of range. `reason` contains the ready-to-render invalid, gap or
ambiguous message, otherwise `null`; `offsetChoices` contains the fold
candidates with each choice's `selected` flag, or `[]` when the local time is
not ambiguous. Offset text is `UTC±HH:MM`, includes seconds only when the
offset has them (for example, `UTC+05:41:16`), and uses `UTC+00:00` for zero.
Hidden precision lives inside the same controller, not in the SDK view or a
form's copy. For `single`, `end` is `null`.
For `range`, both endpoint snapshots remain available even while neither can
be saved. Date-only and time-only endpoints do not produce `gap` or
`ambiguous` during valid operation.

The whole result is `empty` only when the whole field is empty. Any nonempty
field without all required valid endpoints is `unresolved`, including a range
with one valid and one empty endpoint. A range with both endpoints valid is
`valid` even if their order is unacceptable to a consumer. Neither `empty` nor
`unresolved` has a `value`; endpoint `incomplete`, `invalid`, `gap` and
`ambiguous` do not have one either. Clearing a required part immediately makes
the previous value unavailable for saving. A form must not substitute its
last valid moment or pair. This is conversion of an editing draft into a
representable value; business permission, ordering and admissibility remain
with the consuming form/server.

## Explicit display factors and labels

Factors describe presentation; labels supply visible and accessible words.
Neither is inferred from the browser's locale or from the IANA adapter.

```ts
type DateTimePickerWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6 // Sunday = 0.
type DateTimePickerDatePart = 'day' | 'month' | 'year'
interface DateTimePickerName {
  readonly short: string
  readonly full: string
}
interface DateTimePickerFactors {
  readonly firstWeekday: DateTimePickerWeekday
  readonly weekendDays: readonly DateTimePickerWeekday[]
  readonly dateOrder: readonly [
    DateTimePickerDatePart, DateTimePickerDatePart, DateTimePickerDatePart,
  ]
  readonly dateSeparator: string
  readonly hourCycle: 'h12' | 'h23'
  readonly monthNames: readonly DateTimePickerName[]
  readonly weekdayNames: readonly DateTimePickerName[]
  readonly placeholders: Readonly<Record<DateTimePickerPart, string>>
  readonly amLabel: string
  readonly pmLabel: string
}
interface DateTimePickerLabels {
  readonly from: string
  readonly to: string
  readonly today: string
  readonly done: string
  readonly previousMonth: string
  readonly nextMonth: string
  readonly chooseDate: string
  readonly chooseTime: string
  readonly chooseOffset: string
  readonly openCalendar: string
  readonly time: string
  readonly increase: string
  readonly decrease: string
  readonly firstOccurrence: string
  readonly secondOccurrence: string
  readonly year: string
  readonly month: string
  readonly day: string
  readonly hour: string
  readonly minute: string
  readonly second: string
  readonly meridiem: string
  readonly offset: string
  readonly invalidDate: string
  readonly invalidTime: string
  readonly invalidZone: string
  readonly gap: string
  readonly ambiguous: string
}
```

`firstWeekday` and `weekdayNames` use Sunday = 0; `monthNames` uses month minus
one. Supply exactly 12 month names and seven weekday names. `dateOrder` is a
permutation of three distinct date parts; `weekendDays` is a set of weekdays,
not a rule about the grid's left and right columns. Complete partial factors
from one default set. Arrays and `placeholders` replace their respective
values whole; there is no implicit locale/`Intl` fallback. `setFactors()` may
change order, names and hour cycle without losing the value, hidden precision
or the semantic parts already entered. An unresolved draft stays unresolved;
missing input is not guessed.

The controller validates factor and label values before changing state:
`dateSeparator` and every placeholder must be strings; name fields, `amLabel`,
`pmLabel` and labels must be nonempty. Invalid values raise `RangeError`;
unknown keys are ignored at runtime.

The default factors are `firstWeekday: 1`, `weekendDays: [0, 6]`,
`dateOrder: ['day', 'month', 'year']`, `dateSeparator: '/'`, and
`hourCycle: 'h23'`. Month names are January–December with short names Jan–Dec;
weekday names are Sunday–Saturday with short names Sun–Sat (index `0` is
Sunday). These names are the pairs Jan/January through Dec/December and
Sun/Sunday through Sat/Saturday. The eight placeholders are `year: 'yyyy'`,
`month: 'mm'`, `day: 'dd'`,
`hour: '--'`, `minute: '--'`, `second: '--'`, `meridiem: '--'`, and
`offset: 'UTC±--'`; the default `amLabel` and `pmLabel` are `'AM'` and `'PM'`.
`DEFAULT_DATE_TIME_PICKER_FACTORS` is frozen, including its arrays, name objects
and placeholders. `DEFAULT_DATE_TIME_PICKER_LABELS` is frozen and contains
`from: 'From'`, `to: 'To'`, `today: 'Today'`, `done: 'Done'`,
`previousMonth: 'Previous month'`, `nextMonth: 'Next month'`,
`chooseDate: 'Choose date'`, `chooseTime: 'Choose time'`,
`chooseOffset: 'Choose UTC offset'`, `openCalendar: 'Open calendar'`,
`time: 'Time'`, `increase: 'Increase'`, `decrease: 'Decrease'`,
`firstOccurrence: 'First'`, `secondOccurrence: 'Second'`,
`year: 'Year'`, `month: 'Month'`, `day: 'Day'`, `hour: 'Hours'`,
`minute: 'Minutes'`, `second: 'Seconds'`,
`meridiem: 'AM/PM'`, `offset: 'UTC offset'`,
`invalidDate: 'This date does not exist.'`,
`invalidTime: 'This time does not exist.'`,
`invalidZone: '{zone} is not a usable time zone.'`,
`gap: '{time} does not exist on {date} in {zone}: at {from} the clocks jump to {to}.'`,
and `ambiguous: '{time} happens twice on {date} in {zone}. Which one?'`.
`showSeconds` defaults to `false`. These defaults do not constrain the later
i18n provider, which supplies explicit factors and labels without changing
picker semantics.

Reason templates substitute the five known slots `{time}`, `{date}`, `{zone}`,
`{from}` and `{to}`; an unknown slot remains literal text. Visible date parts
follow `dateOrder`; time parts follow the fixed order hour, minute, optional
second, optional meridiem, and datetime adds offset only while the local time is
ambiguous. The prefix before each part is empty for the first part, the date
separator between date parts, `, ` before time, `:` between time fields, and a
space before meridiem or offset; these delimiters are fixed outputs, not extra
factors. Numeric fields have width four for year and two otherwise. Their
ranges are year `1..9999`, month `1..12`, day `1..` the known month's length
(or `31` until its month and, for February, year are known), hour `0..23` in
h23 or `1..12` in h12, and minute/second `0..59`. `inputDigit()` appends a
digit unless the field already has full
width or the newly full-width value is out of range, in which case it starts
with the new digit. A completed value receives a leading zero, and the method
returns `true` when the view should advance; completion means full width or no
next digit can fit.
Year is readable only at four digits; other numeric fields are readable from
one in-range digit. `leavePart()` pads an unambiguous one-digit reading when
focus leaves without changing its value or calling `onChange()`. Numeric
stepping wraps within the
part's range; an hour step crosses a gap using the projected boundary in its
direction while preserving smaller units, checks at most one full hour cycle,
and does not cross a skipped local date. Minute and second steps do not cross a
gap. Endpoint status is evaluated in this order: all visible parts empty gives
`empty`; any entered value outside its range gives `invalid`; missing or
unreadable required input gives `incomplete` (a short prefix below its minimum
is incomplete, not invalid); an impossible calendar date gives
`invalid/date`; then a complete datetime is resolved as invalid zone, gap,
ambiguous, or valid by the adapter. Date and time endpoints become valid after
their own complete, in-range parts.

## Controller and lifetime

The public core vocabulary in this document, including the adapter types and
factory, belongs to `@hilos/core/base`. The declarations below describe the
implemented public API. Keep it at that existing entry; add no compatibility-
root import or new package subpath for the picker.

```ts
interface DateTimePickerCommonOptions<
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
type DateTimePickerOptions<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> = DateTimePickerCommonOptions<K, C> & (
  K extends 'datetime'
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
        }
)
interface DateTimePickerController<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
> {
  readonly kind: K
  readonly count: C
  readonly state: ReadonlySignal<DateTimePickerSnapshot<K, C>>
  readonly calendar: DateTimePickerCalendar
  readonly labels: Readonly<DateTimePickerLabels>
  readonly disabled: ReadonlySignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  setValue(value: DateTimePickerValue<K, C> | null): void
  setEndpointValue(edge: DateTimePickerEdge, value: DateTimePickerValueMap[K] | null): void
  setFactors(factors: Partial<DateTimePickerFactors>): void
  setShowSeconds(show: boolean): void
  setPartText(edge: DateTimePickerEdge, part: DateTimePickerNumericPart, text: string): void
  inputDigit(edge: DateTimePickerEdge, part: DateTimePickerNumericPart, digit: string): boolean
  leavePart(edge: DateTimePickerEdge, part: DateTimePickerPart): void
  setMeridiem(edge: DateTimePickerEdge, value: 'am' | 'pm'): void
  clearPart(edge: DateTimePickerEdge, part: DateTimePickerPart): void
  stepPart(edge: DateTimePickerEdge, part: DateTimePickerPart, direction: -1 | 1): void
  selectOffset(edge: DateTimePickerEdge, epochMs: number): void
  setDate(edge: DateTimePickerEdge, date: CalendarDate): void
  dispose(): void
}
declare function createDateTimePickerController<
  K extends DateTimePickerKind,
  C extends DateTimePickerCount,
>(options: DateTimePickerOptions<K, C>): DateTimePickerController<K, C>
declare const DEFAULT_DATE_TIME_PICKER_FACTORS: Readonly<DateTimePickerFactors>
declare const DEFAULT_DATE_TIME_PICKER_LABELS: Readonly<DateTimePickerLabels>
```

`kind`, `count`, `timeZone`, `adapter`, `labels` and `minDate` are instance
configuration. A different meaning requires an explicit new instance, not
reinterpretation of the old draft. Only `start` is addressable in `single`;
addressing `end` is a caller error. With no adapter supplied for `datetime`,
use `createIntlDateTimeZoneAdapter()`. A date receives `today()` but no zone or
adapter; a time receives no calendar, `minDate`, `today()` or `timeZone`.

`setValue()` is the owner's explicit full replacement or reset of the draft.
`setEndpointValue()` replaces or clears only the addressed endpoint, preserving
the other endpoint's incomplete draft. When either changes the draft, it
publishes state without calling `onChange()`, preventing echo writes. Changing
factors or seconds visibility also publishes state without calling
`onChange()`. `setPartText()` edits numeric raw
text and may leave it incomplete or invalid; it accepts `''` or ASCII digits no
longer than the part width, otherwise it throws `RangeError`. `inputDigit()`
accepts exactly one ASCII digit. `setMeridiem()` takes `am` or `pm` independently of
their labels. `clearPart()` and `stepPart()` also accept the meridiem and offset
parts; offset choices must come from the current candidates.
The controller exposes the resolved labels and computed read-only `disabled`
and `busy` signals to the view. Each user method rechecks both option callbacks;
when either is true, the method is a no-op and `inputDigit()` returns `false`.

Call `onChange` once after a user draft edit that changes the draft, including a
transition to incomplete or invalid, with the coherent snapshot. A no-op user
edit does not publish. `leavePart()` pads a readable one-digit number on focus
departure without calling `onChange()`. Focus changes, month navigation,
hover, factor changes and `showSeconds` changes are not value edits. `disabled`
and `busy` default to false and block user editing methods;
they do not prevent the owner's explicit `setValue()` or
`setEndpointValue()`. The parent owns the instance lifetime, subscriptions,
reset and merge. Release the controller and associated effects on replacement
or unmount. `dispose()` is idempotent; a disposed instance no longer publishes
user changes, every method is a no-op, and the state keeps its last snapshot.

## Preserve every part the person did not edit

Policy A is fixed: change only the explicitly edited calendar parts. Preserve
the others, including hidden seconds and milliseconds. For a loaded local
`06:00:42.123`, changing minutes to `10` gives `06:10:42.123`; independently,
changing seconds to `15` gives `06:00:15.123`. An untouched datetime preserves
its exact epoch-ms number and loaded fold occurrence. Do not reconstruct it
through the old parser, which loses milliseconds. Time-only has no fractions
by its canonical contract; date-only creates no time parts.

For a new value, hidden seconds and fractions start at zero. Visible unfilled
required parts remain incomplete. Clearing an entire endpoint also removes
its retained hidden precision. `setShowSeconds()` only changes visibility; it
does not zero seconds. Hiding an incomplete or invalid segment does not make
it valid: retain its input until correction or an explicit reset.

`setDate()` changes calendar date parts and preserves time, then resolves the
new local coordinate in the same zone. It does not sort a range or choose its
next active endpoint. Choosing a date for an empty datetime leaves the visible
time incomplete until entered. In `h12`, display hours `1..12` with a required
separate meridiem; canonical hours remain `0..23`. Stepping wraps only the
chosen part without carrying into its neighbors (`59` to `00`). A month change
with day `31` may become invalid; do not silently move the day.

## Gaps, folds and the captured zone

A typed or selected local time in a gap produces no value and is not silently
shifted. Hour stepping skips a missing interval using adapter facts and their
projections, retaining minutes, seconds and fractions. It uses the projected
edge in the step direction; if that projected hour is still missing, it
continues stepping through hours for at most one full cycle. Do not assume the
gap is one hour or skip a whole date by adding 24 elapsed hours: on a wholly
skipped date the hour remains at the neighboring value and the endpoint stays
`gap`. Minute and second stepping does not skip gaps. The adapter reports facts;
the controller owns the user choice and the labels explaining it.

Map an unresolved fold to `ambiguous` with its candidates and offsets; publish
no value until a choice. `selectOffset()` accepts only an `epochMs` belonging
to the current ambiguous endpoint's candidates. Reject a stale choice without
replacing the value. An actual change to significant local parts clears a
previous manual choice; a new ambiguous set requires a new choice. The same
unchanged ambiguous set retains its choice. A loaded epoch-ms value already
identifies its occurrence. Display factors and `showSeconds` do not demand
another offset choice.

`today()` supplies an explicit calendar date when opening the calendar or
invoking Today. For datetime, the owner computes it in the supplied zone; for
date-only it supplies a day without attaching a zone to the value. A fixture
may supply a fixed day. The controller does not read the OS zone, global
`hilosTimeZone` or locale itself. `minDate` is a lower calendar-day bound for
disabling calendar cells; it neither prevents typing an earlier date nor
proves business validity.

## Calendar ownership and interaction

Share Gregorian date arithmetic and the month matrix so both the picker and
the scheduler Calendar can use the same mathematics. Calendar's own
period/navigation state and week/day time grid stay with Calendar; sharing
math does not make it the picker's state machine.

The shared Gregorian API is available from `@hilos/core/base`:

```ts
export type CalendarDate = string
export type CalendarWeekday = 0 | 1 | 2 | 3 | 4 | 5 | 6
export interface CalendarDateParts {
  readonly year: number
  readonly month: number
  readonly day: number
}
export interface CalendarMonthMatrix {
  readonly weekdays: readonly CalendarWeekday[]
  readonly weeks: readonly (readonly (CalendarDate | null)[])[]
}
export function parseCalendarDate(value: string): CalendarDateParts | null
export function createCalendarDate(year: number, month: number, day: number): CalendarDate
export function calendarDaysInMonth(year: number, month: number): number
export function calendarDateWeekday(date: CalendarDate): CalendarWeekday
export function addCalendarDays(date: CalendarDate, delta: number): CalendarDate
export function addCalendarMonths(date: CalendarDate, delta: number): CalendarDate
export function addCalendarYears(date: CalendarDate, delta: number): CalendarDate
export function calendarWeekStart(date: CalendarDate, firstWeekday: CalendarWeekday): CalendarDate
export function calendarWeekEnd(date: CalendarDate, firstWeekday: CalendarWeekday): CalendarDate
export function calendarMonthMatrix(
  year: number, month: number, firstWeekday: CalendarWeekday,
): CalendarMonthMatrix
```

`CalendarDate` comes from this shared math, rather than the future picker
types. It is strict Gregorian `YYYY-MM-DD` in years `0001..9999`.
`parseCalendarDate()` returns `null` for invalid strings or impossible dates,
without trimming, coercion or overflow normalization. Other operations throw
`RangeError` on invalid inputs: year/month/day must be integers identifying
an existing date, `delta` must be a safe integer and `firstWeekday` must be
explicitly supplied as Sunday `0` through Saturday `6`.

Day shifts cross month/year boundaries. Month/year shifts clamp only the day
to the target month's last day; each call starts from its supplied date, with
no retained preferred day. Thus January 31 → February 28 → March 28. A zero
shift preserves the date. Results outside `0001..9999`, including a week
start/end beyond an outer boundary, throw `RangeError`; the future consumer
decides how to disable such a transition.

The matrix has seven weekday columns starting at `firstWeekday` and the
minimum four to six rows of seven cells. It contains every date of the chosen
month exactly once in order, with `null` at unused edges. It includes dates
skipped in an IANA zone, such as `2011-12-30`. There is no implicit current
date, locale or time zone, and this pure API does not use `Intl`. Its UTC
coordinates are calculation workspace, not absolute moments of the dates.
Readonly is the TypeScript contract; runtime deep freezing is not required.

```ts
import {
  addCalendarDays,
  addCalendarMonths,
  calendarMonthMatrix,
  type CalendarWeekday,
} from '@hilos/core/base'

const matrix = calendarMonthMatrix(2026, 10, 1)
const weekdayColumns = matrix.weekdays // [1, 2, 3, 4, 5, 6, 0]
const firstWeek = matrix.weeks[0]
// [null, null, null, '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']
const nextWeek = addCalendarDays('2026-10-17', 7) // '2026-10-24'
const nextMonth = addCalendarMonths('2026-01-31', 1) // '2026-02-28'
const weekendColumns = (weekendDays: readonly CalendarWeekday[]) =>
  weekdayColumns.map((weekday) => weekendDays.includes(weekday))
weekendColumns([0, 6]) // [false, false, false, false, false, true, true]
weekendColumns([5, 6]) // [false, false, false, false, true, true, false]
```

Weekend marking reads `weekdays`; it does not change `weeks` or remove empty
columns/cells. The picker controller supplies the current month and owns
navigation, focus, today, selection and bounds. This example only consumes
the shared math.

The picker composes `picker.calendar` with its existing draft. The model owns
navigation, focus, today, preview and selection policy; it holds no second
value draft. Its public types belong only to `@hilos/core/base`. The factory
and its host bridge stay internal. Views render the following snapshot and
send intents to this model; DOM focus, tabindex, positioning and dismissal
listeners belong to the views.

```ts
type DateTimePickerCalendarSpan = 'none' | 'inside' | 'preview' | 'preview-end'
interface DateTimePickerCalendarDay {
  readonly date: CalendarDate
  readonly day: number
  readonly label: string
  readonly weekend: boolean
  readonly today: boolean
  readonly selected: boolean
  readonly disabled: boolean
  readonly focusTarget: boolean
  readonly span: DateTimePickerCalendarSpan
}
interface DateTimePickerCalendarWeekday {
  readonly weekday: DateTimePickerWeekday
  readonly short: string
  readonly full: string
  readonly weekend: boolean
}
interface DateTimePickerCalendarGrid {
  readonly year: number
  readonly month: number
  readonly title: string
  readonly weekdays: readonly DateTimePickerCalendarWeekday[]
  readonly weeks: readonly (readonly (DateTimePickerCalendarDay | null)[])[]
  readonly focusDate: CalendarDate
  readonly canPreviousMonth: boolean
  readonly canNextMonth: boolean
}
interface DateTimePickerCalendarSnapshot {
  readonly open: boolean
  readonly activeEdge: DateTimePickerEdge
  readonly grid: DateTimePickerCalendarGrid | null
}
interface DateTimePickerCalendar {
  readonly state: ReadonlySignal<DateTimePickerCalendarSnapshot>
  open(): void
  close(): void
  setActiveEdge(edge: DateTimePickerEdge): void
  showPreviousMonth(): void
  showNextMonth(): void
  showToday(): void
  pressKey(key: string, shiftKey: boolean): boolean
  hoverDay(date: CalendarDate | null): void
  selectDay(date: CalendarDate): void
}
```

Before the first `open()`, the snapshot is
`{ open: false, activeEdge: 'start', grid: null }`. Closed panels and time-only
controls have `grid: null`. A time control can open and close without reading
`today()`. Opening an already open, disabled or busy picker is a no-op. For a
date or datetime, opening reads `today()` once and retains it until closing;
an invalid returned date raises `RangeError('today() must return a calendar date')`
without changing the snapshot.

An endpoint's date comes from its published year/month/day `valueNow` parts,
validated by `parseCalendarDate()`, rather than from its result. Incomplete
time, a gap or an unresolved fold still has a selected day. An incomplete or
impossible date has none. Opening sets the active edge to `start`, except a
range with a start date and no end date resumes at `end`. Focus uses the active
edge's date, otherwise the other edge's date, otherwise today. The visible
month always follows focus; there is no separate displayed-month state.

`setActiveEdge()` works on an open panel and moves focus to that edge's date
when one exists. A single picker always addresses `start`; addressing `end`
raises `RangeError`. `selectDay()` raises `RangeError` for time-only or an
invalid calendar date. On a closed, disabled or busy picker, or before
`minDate`, a valid selection is a no-op. For a single picker it changes the
start date, closes date-only, and leaves datetime open.

A range has one calendar and a separately accessible active endpoint. Let
`S` and `E` be the dates before selection, `A` the active edge, and `d` the
selected day. Apply the first matching row:

| Condition | Start date | End date | Next active edge |
|---|---|---|---|
| `A=start`, `E` exists, `d>E` | `d` | Clear date | `end` |
| `A=start`, otherwise | `d` | Preserve | `end` |
| `A=end`, `S` exists, `d<S` | `d` | Clear date | `end` |
| `A=end`, `S` exists, `d>=S` | Preserve | `d` | `start` |
| `A=end`, `S` absent | Preserve | `d` | `start` |

One day may serve as both endpoints. Date-only closes when the gesture leaves
both dates present and either selects the end with a start already present,
or fills a previously absent start. Editing the start of a completed pair
leaves the panel open for the next end selection. Datetime stays open.
Clearing the end date clears only year/month/day, preserving its time and
hidden seconds/milliseconds while resetting its offset choice on coordinate
change. For date-only, that clears the whole endpoint. Each gesture publishes
one coherent picker snapshot and calls `onChange` once, including a restart
that changes both edges. An unchanged draft calls no `onChange`, while active
edge, focus and closing still follow the table. Focus moves to `d`.
`setDate()` itself does not acquire this selection policy. Manual entry never
sorts or swaps a pair; ordering, inclusivity and business admissibility remain
with the consumer. Selecting a gap/fold date preserves time and exposes the
existing endpoint's gap/ambiguous state.

While the active edge is `end`, a start date exists and no end date exists,
`hoverDay()` supplies the preview target; without a pointer target, focus is
the target. A target later than the start gives strictly intervening days
`preview` and the target `preview-end`; an earlier or equal target gives no
span. A completed, forward pair marks only its strict interior `inside`, with
no preview. A manually reversed pair has selected endpoints and no interior.

Grid titles use the full month name and year, such as `October 2026`. Day
labels use the full weekday name followed by the date in `dateOrder`, with
spaces and a full month name, such as `Saturday, 17 October 2026`. Years have
four digits. `weekend` follows `weekendDays` for both cells and column headers;
unused matrix edges remain `null`. `selected` matches either endpoint date,
`today` matches the captured today, and `disabled` means before `minDate`.
Exactly one real day has `focusTarget`, including when that day is disabled.
`setFactors()` rebuilds names and marks without moving month or focus. Typing
and `setValue()` change selection marks without moving month or focus.

The calendar model supplies these keyboard transitions. Disabled dates cannot
be selected through the calendar.

| Key | Calendar intent |
|---|---|
| Left / Right | Move one day |
| Up / Down | Move one week |
| PageUp / PageDown | Move one month |
| Shift + PageUp / PageDown | Move one year |
| Home / End | First / last day of the week, using `firstWeekday` |
| Enter / Space | Select the focused day |

`pressKey()` accepts the literal keys `ArrowLeft`, `ArrowRight`, `ArrowUp`,
`ArrowDown`, `PageUp`, `PageDown`, `Home`, `End`, `Enter` and `' '`. It returns
`true` for a handled key, including a no-op at the calendar boundary or Enter
on a disabled day. It returns `false` for an unknown key, a closed panel or a
time-only control. Escape is the view's call to `close()`. Month/year shifts
clamp from the current focus without remembering an earlier preferred day:
January 31 → February 28 → March 28. Home/End and arrows can cross a month
boundary. A shift outside `0001..9999` is a no-op; month-button availability
is exposed by `canPreviousMonth` and `canNextMonth`.

Month buttons shift focus the same way; their actual DOM focus stays with the
view. `showToday()` rereads and validates today, refreshing both focus and the
today mark without selecting a day. It does nothing on a closed panel or a
time control. `close()` neither saves, reverts nor completes the draft.
Navigation, hover, active edge and closing work while disabled/busy, and none
of these operations or opening calls `onChange` or publishes `picker.state`.
A repeated hover or unchanged navigation does not publish a new calendar
snapshot. After picker disposal every calendar method is a no-op,
`pressKey()` returns `false`, and the last snapshot remains available.

## SDK field contract

Each SDK exposes `HilosDateTimePicker` from its existing `/base` entry. The
shared view contract accepts `picker`, `id`, `label`, and optional
`describedBy` and `dataId`. `picker` is the same controller instance; `id` is a
stable group id; `label` is visible and accessible. For datetime, the label
also names the actual configured zone. Render and edit the controller directly:
no parallel SDK draft and no second `value`/`v-model` binding. Do not recreate
the controller on every render or echoed prop update.
(not in the code yet — HIL-1976)

In the segmented field, Left/Right selects the neighboring part. ASCII digits
fill a numeric part and advance when it is complete; Backspace clears the
part. Up/Down invokes cyclic stepping. AM/PM and the fold offset are separate
parts. Disabled/busy prevents user changes while retaining the visible draft.
A server refusal belongs to the form's error row, not to local segment
validity.

Step buttons invoke cyclic stepping. Alt+Down opens the calendar or time
controls; the opener also works with Enter and Space. Time-only has a time
control with no month grid. Disabled/busy prevents opening while retaining the
visible draft.
(not in the code yet — HIL-1974)
(not in the code yet — HIL-1977)
(not in the code yet — HIL-1976)

Expose a visibly named group and named `spinbutton` parts, including meridiem
and offset. Supply `aria-valuemin`, `aria-valuemax`, `aria-valuenow` and
`aria-valuetext` as appropriate to the part's current state. An incomplete
part must not announce a valid numeric value; expose its placeholder or
reason. Use full words, such as the month's full name, and the configured
part/endpoint labels. Keep a permanent, visually hidden `aria-live="polite"`
node for each edge, containing that edge's reason. Each part references its
edge's node and the parent's supplied description through `aria-describedby`.
Show one visible, `aria-hidden` reason tooltip only while the field has focus.
It shows the focused edge's reason, or the other edge's reason when the focused
edge has none. Overlay it beneath the field over neighboring content; do not
reserve a row for it. Invalid and gap style the field and tooltip as errors and
set `aria-invalid="true"` on that edge's parts. Ambiguous is a neutral
question; leave the field and parts without invalid state. Empty and incomplete
states have no reason. The permanent live nodes announce changes; the visible
tooltip has no live role. Do not show the field tooltip while its panel is open;
the panel states the same reason itself. Color alone is insufficient.
(not in the code yet — HIL-1976)

## SDK calendar and narrow view

Render the common calendar model as a named dialog with a named grid. Give
each day its full accessible name, selected state through `aria-selected`
and prohibited state through `aria-disabled`. Bind the model's keyboard
intents, and focus its selected or today's day on opening. An active range
endpoint must be identifiable and operable independently of color. Selecting
a day changes only that endpoint's date and preserves its time. On a wide view,
name the active range endpoint in the panel's visually hidden polite live text
with `labels.chooseDate` and `labels.from` or `labels.to`; only the narrow view
exposes an explicit endpoint switch.
(not in the code yet — HIL-1974)
(not in the code yet — HIL-1977)

Date-only closes after a complete choice: one date, or both range dates.
Datetime and time controls close explicitly, including Done or Escape; Done
and Escape neither save the form nor undo parts already entered. Escape
returns focus to the opener. Within a parent modal, do not create a second
independent modal focus trap. Shared DOM focus, scroll and dismissal effects
live in browser-only core DOM helpers; the controller stays DOM-free and views
use the shared helpers. The `@hilos/core/base` entry exports
`bindPopupDismiss`, `revealPickerPanel`, `bindNarrowScreen` and
`revealNarrowPickerPanel`. Keep the panel in the DOM
inside the picker component so a parent modal's focus trap includes it. In a
modal the wide panel stays inside the modal's scrolling body, so the whole
panel is visible at once only when that body is at least as tall as the panel;
a shorter form reaches the rest by scrolling the body. When the panel is open,
Escape must not reach the parent modal.
(not in the code yet — HIL-1974)
(not in the code yet — HIL-1977)

The narrow layout applies at `(max-width: 767.98px)`. `bindNarrowScreen()`
synchronously reports the initial media-query result so the first render uses
the matching layout, including when the calendar is already open. It reports
later `change` events and returns an unsubscribe function. Without a browser
media query, including when querying it is unavailable or throws, it reports
`false`. A picker component owns one width subscription independently of its
controller and releases it when unmounted.

When the narrow panel opens, `revealNarrowPickerPanel` scrolls the field label
to the start of the sheet body, then focuses the active day with scrolling
prevented. With no month grid, as in a time-only picker, it focuses the dialog
itself rather than a time input. After calendar-key navigation, the existing
`revealPickerPanel` effect continues to reveal the active day. Keep the panel
in normal flow directly under the field, at the available width, without
absolute positioning or a shadow. Keep the sheet header and footer in place;
keep its `role="dialog"` and accessible name the same in both layouts. The
panel remains inside the picker component, so the parent modal's focus trap
includes it.

Give each endpoint its own full-width outer item. Its parts may wrap within
that item; keep each part's prefix with its input, and keep the range dash
with the second endpoint's first part. Expose range endpoint buttons only
when a range calendar grid is present. Label them with `labels.from` and
`labels.to`, expose the active edge with `aria-pressed`, and show each edge's
parts as the concatenation of its prefixes and text or placeholder, including
an offset. Activating an edge keeps focus on its button and lets the calendar
model update the month and focused day. Keep the polite announcement of the
active date edge. When a month grid is present, show only the active edge's
time row, even if that edge has no complete date. Without a month grid, retain
the existing time-row behavior. Keep the reason and offset choice available
for either edge. Use touch targets of at least 44 px.

Put Done after the other panel controls as its final visible control, using
`type="button"` and `labels.done`. It closes the panel without saving the form
or changing the draft, and focus returns to the calendar opener through the
existing close path. The narrow panel closes
through Done, a field click, the calendar button, Escape, or the calendar's
existing date-selection behavior. Do not close it on an outside press or when
focus leaves it. Escape still stops before the parent modal. Disabled or busy
state continues to block day and time editing; range-edge controls and Done
remain usable.

On narrow view, a press in the segmented field is resolved at `mousedown`;
the calendar button is outside this behavior:

- With the panel closed and no field part focused, cancel `mousedown` so no
  part takes focus or opens the screen keyboard; the following click opens
  the panel.
- With the panel open, a clicked part takes its normal focus and the click
  closes the panel, leaving focus on that part. Clicking the empty field group
  closes the panel and returns focus to the calendar button.
- With the panel closed and a part already focused for typing, the press moves
  focus normally and does not open the panel.

Field presses on a wide screen retain their existing behavior. Tab,
Alt+Down and Escape behave the same at either width. On every width, set
`inputmode="numeric"` for numeric parts and `inputmode="none"` for AM/PM and
offset. Always cancel `beforeinput`: a single digit follows the same
`inputDigit()` path and auto-advance as a keyboard digit, deletion calls
`clearPart()`, and other input is ignored. Desktop keydown cancels handled
input before `beforeinput` can duplicate it; Android may report an
`Unidentified` keydown, so the `beforeinput` path is needed there.

If width changes while the panel is open, keep it open and leave the value and
draft untouched. Add or remove the outside-dismiss listeners as the layout
changes. If the responsive render removes the focused control and focus falls
to the document body, move focus to the dialog after rendering without
scrolling. Leave focus alone when it is still on a connected target. The
existing picker replacement and unmount rules continue to apply.
(not in the code yet — HIL-1975)
(not in the code yet — HIL-1978)

## Core API examples

The core types, controller and constants are available from the existing
`@hilos/core/base` entry. These examples use that public API.

```ts
import {
  createDateTimePickerController,
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  type DateTimePickerController,
  type DateTimePickerFactors,
  type DateTimePickerValue,
} from '@hilos/core/base'

const datePicker = createDateTimePickerController<'date', 'single'>({
  kind: 'date',
  count: 'single',
  value: '2026-10-17',
  today: () => '2026-10-13',
})

const timePicker = createDateTimePickerController<'time', 'single'>({
  kind: 'time',
  count: 'single',
  value: '06:00:00',
  showSeconds: true,
})

const momentPicker = createDateTimePickerController<'datetime', 'single'>({
  kind: 'datetime',
  count: 'single',
  value: 1792209600000,
  timeZone: 'Europe/Warsaw',
  today: () => '2026-10-13',
  showSeconds: true,
})

const rangePicker = createDateTimePickerController<'datetime', 'range'>({
  kind: 'datetime',
  count: 'range',
  value: [1792209600000, 1792213200000],
  timeZone: 'Europe/Warsaw',
  today: () => '2026-10-13',
  showSeconds: true,
  labels: { from: 'Starts', to: 'Ends' },
})
```

The moment is 17 October 2026 at 06:00 in Europe/Warsaw; the range ends at
07:00 on that day. The fixed `today()` is an explicit example input, not a
clock the component discovers. This example does not replace the maintenance
form.

The same types also accept date and time ranges without a zone:

```ts
const dates: DateTimePickerValue<'date', 'range'> = [
  '2026-10-17', '2026-10-21',
]
const times: DateTimePickerValue<'time', 'range'> = ['06:00:00', '07:00:00']
```

A full factor object can reuse the explicit default names and placeholders
while changing first weekday, order and hour cycle. It contains every factor;
no browser locale is consulted. Applying it to the moment example changes
presentation while its value remains exactly `1792209600000`.

```ts
const alternateFactors: DateTimePickerFactors = {
  firstWeekday: 0,
  weekendDays: [0, 6],
  dateOrder: ['month', 'day', 'year'],
  dateSeparator: '/',
  hourCycle: 'h12',
  monthNames: DEFAULT_DATE_TIME_PICKER_FACTORS.monthNames,
  weekdayNames: DEFAULT_DATE_TIME_PICKER_FACTORS.weekdayNames,
  placeholders: DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
  amLabel: 'AM',
  pmLabel: 'PM',
}
momentPicker.setFactors(alternateFactors)
```

Reset explicitly, or apply an accepted programmatic merge to just one edge.
Neither call invokes `onChange`; the second does not copy or replace the
other edge's draft, even if that draft is incomplete.

```ts
datePicker.setValue(null)
rangePicker.setEndpointValue('end', 1792213200000)
```

A form reads the current result on both Save enablement and submit. The
following helpers express only whether a pair exists; the form combines that
with its existing busy, dirty, conflict and permission rules. No earlier valid
pair is retained as a fallback.

```ts
function hasPair(picker: DateTimePickerController<'datetime', 'range'>): boolean {
  return picker.state.get().result.status === 'valid'
}

function submitPair(
  picker: DateTimePickerController<'datetime', 'range'>,
  send: (startsAt: number, endsAt: number) => void,
): void {
  const snapshot = picker.state.get()
  if (snapshot.result.status !== 'valid') return
  const [startsAt, endsAt] = snapshot.result.value
  send(startsAt, endsAt)
}
```

When `hasPair()` is false, Save is unavailable and Enter sends nothing. These
functions illustrate the future form boundary, not a second value store or
business validator. (not in the code yet — HIL-1979)

## SDK view imports

The Vue and React imports are available; the Angular import below remains a
future export. Mount the stable controller with the same `picker`, `id`, `label`,
optional `describedBy` and optional `dataId` contract in each framework. A
datetime range label may read `Maintenance window, Europe/Warsaw`, naming the
configured zone.

```ts
import { HilosDateTimePicker } from '@hilos/vue/base'
```

```ts
import { HilosDateTimePicker } from '@hilos/react/base'
```

```ts
import { HilosDateTimePicker } from '@hilos/angular/base'
```

(not in the code yet — HIL-1976)

## Maintenance integration and implementation ownership

The maintenance create/edit/merge integration captures `hilosTimeZone` when
the form opens and passes that zone explicitly. A later global zone change
does not reinterpret the open draft. Use a datetime range with seconds,
`Starts`/`Ends`, and the explicit today/minimum-day context. Keep the server
wire's `startsAt` and `endsAt` as epoch milliseconds. Form/server rules decide
period ordering and inclusivity; the picker does not decide whether work is
allowed or whether windows overlap. The form owns explicit resets and accepted
per-edge merges into the controller.
(not in the code yet — HIL-1979)

Adopt that integration in the two maintenance dialogs, preserving the form
refusal row and the hint about maintenance windows. Each view owns its own
adoption, separate from the generic component:

| Consumer | Implementation owner |
|---|---|
| Vue Schedule maintenance / Change scheduled maintenance | (not in the code yet — HIL-1980) |
| React Schedule maintenance / Change scheduled maintenance | (not in the code yet — HIL-1981) |
| Angular Schedule maintenance / Change scheduled maintenance | (not in the code yet — HIL-1982) |

Shared Gregorian math and its `/base` exports are implemented by HIL-1966.
Exact zone facts and their standard `/base` adapter are implemented by HIL-1967.
HIL-1968 implemented picker values, draft, factors, controller and core exports.
HIL-1969 implemented `picker.calendar`, atomic range selection, navigation and
its `/base` types. The remaining markers name the implementation owner at each
boundary: the SDK field/calendar/narrow sequences HIL-1970–1972,
HIL-1973–1975 and HIL-1976–1978 for Vue, React and Angular; and HIL-1979–1982
for maintenance integration and adoption. Do not invent new internal file
names from this contract or implement a neighboring leaf to make an example
import resolve.

## Validation

For this documentation, review all six kind/count combinations, the exact
declarations and canonical examples, value absence for unresolved states,
preserved hidden precision, gap/fold and stale-offset behavior, the separation
of display factors from `Intl`, and the ownership markers. Check relative
links, both navigation entries, English/public wording and `git diff --check`.
This review does not execute the future API or demonstrate its implementation.

Each implementation leaf owes its own relevant tests when it lands; do not
postpone them to final integration. Final cross-layer verification cleanup
belongs to HIL-1983. It does not stand in for adapter, controller, keyboard or
SDK behavior tests in the implementing leaves.
(not in the code yet — HIL-1983)

No new unit/e2e code, runtime suite, future-API build, clock wait, command or
stand change is required for this documentation leaf. Do not assign cosmetic
e2e checks or claim a real screen-reader pass from this text. Follow the
existing accessibility validation rules when the components are implemented.
