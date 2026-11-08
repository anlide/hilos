# DateTimePicker

Read this before implementing or consuming the shared date, time or datetime
picker, its calendar, its time-zone adapter, or its SDK views. This is the
normative contract for HIL-1965. It specifies future implementation; the
markers identify the leaf that makes each block true, following
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
}
```

(not in the code yet — HIL-1968)

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

(not in the code yet — HIL-1968)

String aliases do not prove validity. Accept only real Gregorian dates in years
`0001..9999`, clock times from `00:00:00` through `23:59:59`, and finite integer
epoch milliseconds whose local Gregorian year can be edited. Zero and negative
moments are valid; only `null` means absence. Do not normalize an overflowing
date into a different date. Clock times carry no date, zone or fractions;
datetime values identify moments, not a number adjusted to look like a local
epoch. The constructor, `setValue()` and `setEndpointValue()` reject invalid
configuration or external values with `RangeError` before changing state.
(not in the code yet — HIL-1968)

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

(not in the code yet — HIL-1968)

Publish one immutable snapshot: visible parts and results must agree in every
publication. `parts` holds the visible parts in factor order and their raw
text, including incomplete input. Hidden precision lives inside the same
controller, not in the SDK view or a form's copy. For `single`, `end` is `null`.
For `range`, both endpoint snapshots remain available even while neither can
be saved. Date-only and time-only endpoints do not produce `gap` or
`ambiguous` during valid operation.
(not in the code yet — HIL-1968)

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
(not in the code yet — HIL-1968)

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
  readonly placeholders: Readonly<Record<
    'year' | 'month' | 'day' | 'hour' | 'minute' | 'second', string
  >>
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

(not in the code yet — HIL-1968)

`firstWeekday` and `weekdayNames` use Sunday = 0; `monthNames` uses month minus
one. Supply exactly 12 month names and seven weekday names. `dateOrder` is a
permutation of three distinct date parts; `weekendDays` is a set of weekdays,
not a rule about the grid's left and right columns. Complete partial factors
from one default set. Arrays and `placeholders` replace their respective
values whole; there is no implicit locale/`Intl` fallback. `setFactors()` may
change order, names and hour cycle without losing the value, hidden precision
or the semantic parts already entered. An unresolved draft stays unresolved;
missing input is not guessed.
(not in the code yet — HIL-1968)

The temporary default set uses English month/day names, day/month/year with
`/`, `h23`, Monday (`1`), weekends `[0, 6]`, placeholders `yyyy`, `mm`, `dd`,
`hh`, `mm`, `ss` for their named parts, and `AM`/`PM`. Default labels include
`From`, `To`, `Today`, `Done` and English accessible names and reasons.
`showSeconds` defaults to `false`. This concrete default is not a permanent
product-language requirement; the later i18n provider uses the same factor
input without changing picker semantics.
(not in the code yet — HIL-1968)

## Controller and lifetime

The public core vocabulary in this document, including the adapter types and
factory, belongs to `@hilos/core/base`. The declarations below complete that
future API. Keep it at that existing entry; add no compatibility-root import
or new package subpath for the picker.

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
  setValue(value: DateTimePickerValue<K, C> | null): void
  setEndpointValue(edge: DateTimePickerEdge, value: DateTimePickerValueMap[K] | null): void
  setFactors(factors: Partial<DateTimePickerFactors>): void
  setShowSeconds(show: boolean): void
  setPartText(edge: DateTimePickerEdge, part: DateTimePickerNumericPart, text: string): void
  inputDigit(edge: DateTimePickerEdge, part: DateTimePickerNumericPart, digit: string): void
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

(not in the code yet — HIL-1968)

`kind`, `count`, `timeZone`, `adapter`, `labels` and `minDate` are instance
configuration. A different meaning requires an explicit new instance, not
reinterpretation of the old draft. Only `start` is addressable in `single`;
addressing `end` is a caller error. With no adapter supplied for `datetime`,
use `createIntlDateTimeZoneAdapter()`. A date receives `today()` but no zone or
adapter; a time receives no calendar, `minDate`, `today()` or `timeZone`.
(not in the code yet — HIL-1968)

`setValue()` is the owner's explicit full replacement or reset of the draft.
`setEndpointValue()` replaces or clears only the addressed endpoint, preserving
the other endpoint's incomplete draft. Both publish state without calling
`onChange`, preventing echo writes. `setPartText()` edits numeric raw text and
may leave it incomplete or invalid. `inputDigit()` accepts exactly one ASCII
digit. `setMeridiem()` takes `am` or `pm` independently of their labels.
`clearPart()` and `stepPart()` also accept the meridiem and offset parts;
offset choices must come from the current candidates.
(not in the code yet — HIL-1968)

Call `onChange` once after a user draft edit, including a transition to
incomplete or invalid, with the coherent snapshot. Focus changes, month
navigation, hover, factor changes and `showSeconds` changes are not value
edits. `disabled` and `busy` default to false and block user editing methods;
they do not prevent the owner's explicit `setValue()` or
`setEndpointValue()`. The parent owns the instance lifetime, subscriptions,
reset and merge. Release the controller and associated effects on replacement
or unmount. `dispose()` is idempotent; a disposed instance no longer publishes
user changes.
(not in the code yet — HIL-1968)

## Preserve every part the person did not edit

Policy A is fixed: change only the explicitly edited calendar parts. Preserve
the others, including hidden seconds and milliseconds. For a loaded local
`06:00:42.123`, changing minutes to `10` gives `06:10:42.123`; independently,
changing seconds to `15` gives `06:00:15.123`. An untouched datetime preserves
its exact epoch-ms number and loaded fold occurrence. Do not reconstruct it
through the old parser, which loses milliseconds. Time-only has no fractions
by its canonical contract; date-only creates no time parts.
(not in the code yet — HIL-1968)

For a new value, hidden seconds and fractions start at zero. Visible unfilled
required parts remain incomplete. Clearing an entire endpoint also removes
its retained hidden precision. `setShowSeconds()` only changes visibility; it
does not zero seconds. Hiding an incomplete or invalid segment does not make
it valid: retain its input until correction or an explicit reset.
(not in the code yet — HIL-1968)

`setDate()` changes calendar date parts and preserves time, then resolves the
new local coordinate in the same zone. It does not sort a range or choose its
next active endpoint. Choosing a date for an empty datetime leaves the visible
time incomplete until entered. In `h12`, display hours `1..12` with a required
separate meridiem; canonical hours remain `0..23`. Stepping wraps only the
chosen part without carrying into its neighbors (`59` to `00`). A month change
with day `31` may become invalid; do not silently move the day.
(not in the code yet — HIL-1968)

## Gaps, folds and the captured zone

A typed or selected local time in a gap produces no value and is not silently
shifted. Hour stepping skips the missing interval using adapter facts and
their projections, retaining minutes and seconds while seeking an existing
local part. Do not assume the gap is one hour, or skip a whole date by adding
24 elapsed hours. The adapter reports facts; the controller owns the user
choice and the labels explaining it.
(not in the code yet — HIL-1968)

Map an unresolved fold to `ambiguous` with its candidates and offsets; publish
no value until a choice. `selectOffset()` accepts only an `epochMs` belonging
to the current ambiguous endpoint's candidates. Reject a stale choice without
replacing the value. An actual change to significant local parts clears a
previous manual choice; a new ambiguous set requires a new choice. The same
unchanged ambiguous set retains its choice. A loaded epoch-ms value already
identifies its occurrence. Display factors and `showSeconds` do not demand
another offset choice.
(not in the code yet — HIL-1968)

`today()` supplies an explicit calendar date when opening the calendar or
invoking Today. For datetime, the owner computes it in the supplied zone; for
date-only it supplies a day without attaching a zone to the value. A fixture
may supply a fixed day. The controller does not read the OS zone, global
`hilosTimeZone` or locale itself. `minDate` is a lower calendar-day bound for
disabling calendar cells; it neither prevents typing an earlier date nor
proves business validity.
(not in the code yet — HIL-1968)

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
columns/cells. The future controller supplies the current month and owns
navigation, focus, today, selection and bounds. This example only consumes
the shared math.

Compose the day/range selection and navigation model with the same picker
controller. It owns selection policy and navigation, not a second copy of the
value draft. Its internal cells, coordinates and DOM bindings are not another
public contract specified here. Open on the selected month, or the month from
`today()` when there is no selected day, with the selected or today's day as
the initial focus target. Today uses the explicit calendar context.
(not in the code yet — HIL-1969)

A range has one calendar and a separately accessible active endpoint. In the
two-click range sequence, the first click chooses the start, hover previews
the interval, and the next click chooses the end. An earlier second click
starts again from that day and leaves the end waiting. One day may serve as
both endpoints. This is calendar selection policy over `setDate()`, not behavior
inside `setDate()`. Manual entry never sorts or swaps the pair. Range ordering,
inclusivity and business admissibility belong to the consumer.
(not in the code yet — HIL-1969)

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

(not in the code yet — HIL-1969)

## SDK field contract

Each SDK exposes `HilosDateTimePicker` from its existing `/base` entry. The
shared view contract accepts `picker`, `id`, `label`, and optional
`describedBy` and `dataId`. `picker` is the same controller instance; `id` is a
stable group id; `label` is visible and accessible. For datetime, the label
also names the actual configured zone. Render and edit the controller directly:
no parallel SDK draft and no second `value`/`v-model` binding. Do not recreate
the controller on every render or echoed prop update.
(not in the code yet — HIL-1970)
(not in the code yet — HIL-1973)
(not in the code yet — HIL-1976)

In the segmented field, Left/Right selects the neighboring part. ASCII digits
fill a numeric part and advance when it is complete; Backspace clears the
part. Up/Down and the step buttons invoke cyclic stepping. AM/PM and the fold
offset are separate parts. Alt+Down opens the calendar or time controls; the
opener also works with Enter and Space. Time-only has a time control with no
month grid. Disabled/busy prevents user changes and opening while retaining
the visible draft. A server refusal belongs to the form's error row, not to
local segment validity.
(not in the code yet — HIL-1970)
(not in the code yet — HIL-1973)
(not in the code yet — HIL-1976)

Expose a visibly named group and named `spinbutton` parts, including meridiem
and offset. Supply `aria-valuemin`, `aria-valuemax`, `aria-valuenow` and
`aria-valuetext` as appropriate to the part's current state. An incomplete
part must not announce a valid numeric value; expose its placeholder or
reason. Use full words, such as the month's full name, and the configured
part/endpoint labels. Show invalid, gap and ambiguous reasons in text and
connect them to the field through ARIA, preserving supplied descriptions.
Announce changes once through a permanent live region; the visible reason
does not repeat its live role. Color alone is insufficient. Reserve room for
reasons so their appearance does not move neighboring content, following the
existing [styling rules](styling-rules.md).
(not in the code yet — HIL-1970)
(not in the code yet — HIL-1973)
(not in the code yet — HIL-1976)

## SDK calendar and narrow view

Render the common calendar model as a named dialog with a named grid. Give
each day its full accessible name, selected state through `aria-selected`
and prohibited state through `aria-disabled`. Bind the model's keyboard
intents, and focus its selected or today's day on opening. An active range
endpoint must be identifiable and operable independently of color. Selecting
a day changes only that endpoint's date and preserves its time.
(not in the code yet — HIL-1971)
(not in the code yet — HIL-1974)
(not in the code yet — HIL-1977)

Date-only closes after a complete choice: one date, or both range dates.
Datetime and time controls close explicitly, including Done or Escape; Done
and Escape neither save the form nor undo parts already entered. Escape
returns focus to the opener. Within a parent modal, do not create a second
independent modal focus trap. Shared DOM focus and scroll effects belong in
the common browser helper, not the DOM-free controller or three separate
implementations.
(not in the code yet — HIL-1971)
(not in the code yet — HIL-1974)
(not in the code yet — HIL-1977)

On a narrow screen, expand the calendar under the field inside the parent
sheet's body, using its width. Keep the sheet header and footer in place and
bring the field into view within the body. Do not stack another popup layer
over the sheet. Wrap a range's second endpoint as a whole, expose the active
endpoint and Done, use touch targets of at least 44 px and numeric keyboard
input for the numeric parts. This is the narrow view's behavior, not merely
the desktop popup squeezed smaller.
(not in the code yet — HIL-1972)
(not in the code yet — HIL-1975)
(not in the code yet — HIL-1978)

## Future usage examples

These are contract examples, not runnable imports in today's tree. The core
types, controller and constants below will be available from the existing
`/base` entry.

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
form. (not in the code yet — HIL-1968)

The same types also accept date and time ranges without a zone:

```ts
const dates: DateTimePickerValue<'date', 'range'> = [
  '2026-10-17', '2026-10-21',
]
const times: DateTimePickerValue<'time', 'range'> = ['06:00:00', '07:00:00']
```

(not in the code yet — HIL-1968)

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

(not in the code yet — HIL-1968)

Reset explicitly, or apply an accepted programmatic merge to just one edge.
Neither call invokes `onChange`; the second does not copy or replace the
other edge's draft, even if that draft is incomplete.

```ts
datePicker.setValue(null)
rangePicker.setEndpointValue('end', 1792213200000)
```

(not in the code yet — HIL-1968)

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

The SDK imports below are also future exports. Mount the stable controller
with the same `picker`, `id`, `label`, optional `describedBy` and optional
`dataId` contract in each framework. A datetime range label may read
`Maintenance window, Europe/Warsaw`, naming the configured zone.

```ts
import { HilosDateTimePicker } from '@hilos/vue/base'
```

(not in the code yet — HIL-1970)

```ts
import { HilosDateTimePicker } from '@hilos/react/base'
```

(not in the code yet — HIL-1973)

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
The remaining markers name the implementation owner at each boundary:
HIL-1968 for picker values, draft, factors, controller and core exports; HIL-1969
for calendar
selection/navigation; the SDK field/calendar/narrow sequences HIL-1970–1972,
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
