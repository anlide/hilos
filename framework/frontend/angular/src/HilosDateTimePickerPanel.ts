// HilosDateTimePickerPanel — the wide calendar and time panel of one field.
// Internal to HilosDateTimePicker and not exported: the field draws it while
// the controller's calendar is open and hands down the snapshots it mirrors.
// The draft, the grid and every check stay in the controller; this view binds
// DOM, focus and lifecycle. The host element is the dialog itself. The narrow
// layout, its edge buttons and Done, and screen-keyboard digits are separate.
import {
  ApplicationRef,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  revealPickerPanel,
  type CalendarDate,
  type DateTimePickerCalendarDay,
  type DateTimePickerCalendarSnapshot,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
  type DateTimePickerPartDraft,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'

import { handleDateTimePickerPartKey } from './dateTimePickerPartKeys.js'

type PanelController = DateTimePickerController<
  DateTimePickerKind,
  DateTimePickerCount
>

type PanelEndpoint = DateTimePickerEndpointSnapshot<DateTimePickerKind>

/** The parts a time row draws, in the order the controller lists them. */
const TIME_PARTS: readonly DateTimePickerPart[] = [
  'hour',
  'minute',
  'second',
  'meridiem',
]

/** The parts that make an endpoint's date complete. */
const DATE_PARTS: readonly DateTimePickerPart[] = ['year', 'month', 'day']

/** One edge of the current snapshot. */
interface PanelEdge {
  readonly edge: DateTimePickerEdge
  readonly snapshot: PanelEndpoint
}

/** One time row: the edge it edits, its caption and its time parts. */
interface PanelTimeRow {
  readonly edge: DateTimePickerEdge
  readonly invalid: boolean
  readonly label: string | null
  readonly parts: readonly DateTimePickerPartDraft[]
}

/** The time part that holds focus inside this panel. */
interface PanelFocus {
  readonly edge: DateTimePickerEdge
  readonly part: DateTimePickerPart
}

/** The wide month grid, time rows, reasons and offset choice of one field. */
@Component({
  selector: 'div[hilosDateTimePickerPanel]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class:
      'position-absolute top-100 start-0 z-3 mt-1 border rounded-3 shadow bg-body p-2',
    '[attr.role]': "'dialog'",
    '[attr.tabindex]': "'-1'",
    '[id]': "fieldId() + '-panel'",
    '[attr.aria-label]':
      "picker().kind === 'time' ? picker().labels.chooseTime : picker().labels.chooseDate",
  },
  template: `
    @if (calendar().grid; as grid) {
      <div class="d-flex align-items-center gap-1 mb-1">
        <button
          type="button"
          class="btn btn-sm btn-link text-body-secondary"
          [attr.aria-label]="picker().labels.previousMonth"
          [disabled]="!grid.canPreviousMonth"
          (click)="showPreviousMonth($event)"
        >
          <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        <div
          class="flex-grow-1 text-center fw-semibold small"
          aria-live="polite"
          [id]="fieldId() + '-month'"
        >
          {{ grid.title }}
        </div>
        <button
          type="button"
          class="btn btn-sm btn-link text-body-secondary"
          [attr.aria-label]="picker().labels.nextMonth"
          [disabled]="!grid.canNextMonth"
          (click)="showNextMonth($event)"
        >
          <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
      </div>
      <table
        class="table table-sm table-borderless text-center mb-1"
        role="grid"
        [attr.aria-labelledby]="fieldId() + '-month'"
        (pointerleave)="hoverDay($event, null)"
      >
        <thead>
          <tr>
            @for (weekday of grid.weekdays; track weekday.weekday) {
              <th
                scope="col"
                class="fw-normal small text-body-secondary px-0 py-1"
                [class.bg-body-tertiary]="weekday.weekend"
                [attr.aria-label]="weekday.full"
              >
                {{ weekday.short }}
              </th>
            }
          </tr>
        </thead>
        <tbody>
          @for (week of grid.weeks; track $index) {
            <tr>
              @for (day of week; track $index; let column = $index) {
                @if (day === null) {
                  <td
                    class="p-0"
                    [class.bg-body-tertiary]="grid.weekdays[column].weekend"
                  ></td>
                } @else {
                  <td
                    role="presentation"
                    class="p-0"
                    [class.bg-primary-subtle]="day.span === 'inside'"
                    [class.bg-secondary-subtle]="inPreview(day)"
                    [class.bg-body-tertiary]="
                      day.span !== 'inside' && !inPreview(day) && day.weekend
                    "
                  >
                    <button
                      type="button"
                      role="gridcell"
                      class="btn w-100 btn-sm px-2"
                      [class.btn-primary]="day.selected"
                      [class.text-body-tertiary]="!day.selected && day.disabled"
                      [class.border-primary]="
                        !day.selected &&
                        (day.today || day.span === 'preview-end')
                      "
                      [attr.tabindex]="day.focusTarget ? 0 : -1"
                      [attr.aria-label]="day.label"
                      [attr.aria-selected]="day.selected ? 'true' : null"
                      [attr.aria-disabled]="
                        day.disabled || blocked() ? 'true' : null
                      "
                      [attr.aria-current]="day.today ? 'date' : null"
                      (click)="selectDay($event, day.date)"
                      (pointerover)="hoverDay($event, day.date)"
                      (keydown)="onDayKeydown($event)"
                      (keyup)="onDayKeyup($event)"
                    >
                      {{ day.day }}
                    </button>
                  </td>
                }
              }
            </tr>
          }
        </tbody>
      </table>
    }
    @for (row of timeRows(); track row.edge) {
      <div
        class="d-flex align-items-center gap-2"
        [class.py-1]="picker().kind === 'time'"
        [class.border-top]="picker().kind !== 'time'"
        [class.pt-2]="picker().kind !== 'time'"
        [class.mt-1]="picker().kind !== 'time'"
      >
        @if (row.label !== null) {
          <span class="small text-body-secondary flex-grow-1">{{
            row.label
          }}</span>
        }
        <div
          class="d-flex align-items-center gap-1"
          [class.mx-auto]="row.label === null"
        >
          @for (part of row.parts; track part.part) {
            @if (part.part === 'minute' || part.part === 'second') {
              <span class="text-body-secondary" aria-hidden="true">:</span>
            }
            <div class="d-flex flex-column align-items-center">
              <button
                type="button"
                tabindex="-1"
                class="btn btn-link btn-sm p-0 lh-1 text-body-secondary"
                [attr.aria-label]="
                  picker().labels.increase +
                  ' ' +
                  partLabel(row.edge, part.label)
                "
                [disabled]="blocked()"
                (click)="stepPart($event, row.edge, part.part, 1)"
              >
                <i class="bi bi-chevron-up" aria-hidden="true"></i>
              </button>
              <input
                type="text"
                role="spinbutton"
                autocomplete="off"
                spellcheck="false"
                class="border-0 p-0 text-center"
                [class.text-bg-primary]="partFocused(row.edge, part.part)"
                [class.bg-transparent]="!partFocused(row.edge, part.part)"
                [class.text-body-tertiary]="
                  !partFocused(row.edge, part.part) && part.text === ''
                "
                [class.text-body]="
                  !partFocused(row.edge, part.part) && part.text !== ''
                "
                [size]="max(part.text.length, part.placeholder.length)"
                [value]="part.text || part.placeholder"
                [attr.aria-label]="partLabel(row.edge, part.label)"
                [attr.aria-valuemin]="part.valueMin"
                [attr.aria-valuemax]="part.valueMax"
                [attr.aria-valuenow]="part.valueNow"
                [attr.aria-valuetext]="part.valueText"
                [attr.aria-invalid]="row.invalid ? 'true' : null"
                [attr.aria-describedby]="description(row.edge)"
                [disabled]="blocked()"
                (beforeinput)="onBeforeInput($event)"
                (focus)="focusPart($event, row.edge, part.part)"
                (blur)="leavePart($event, row.edge, part.part)"
                (keydown)="onPartKeydown($event, row.edge, part.part)"
              />
              <button
                type="button"
                tabindex="-1"
                class="btn btn-link btn-sm p-0 lh-1 text-body-secondary"
                [attr.aria-label]="
                  picker().labels.decrease +
                  ' ' +
                  partLabel(row.edge, part.label)
                "
                [disabled]="blocked()"
                (click)="stepPart($event, row.edge, part.part, -1)"
              >
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
              </button>
            </div>
          }
        </div>
      </div>
    }
    @for (endpoint of endpoints(); track endpoint.edge) {
      @if (endpoint.snapshot.reason) {
        <div
          class="small pt-2"
          [class.text-danger-emphasis]="isInvalid(endpoint.snapshot)"
        >
          {{ endpoint.snapshot.reason }}
        </div>
      }
      @if (endpoint.snapshot.offsetChoices.length > 0) {
        <div
          class="mb-2 btn-group-vertical btn-group-sm w-100"
          role="radiogroup"
          [attr.aria-label]="
            partLabel(endpoint.edge, picker().labels.chooseOffset)
          "
        >
          @for (
            choice of endpoint.snapshot.offsetChoices;
            track choice.epochMs;
            let index = $index
          ) {
            <input
              type="radio"
              class="btn-check"
              [id]="offsetId(endpoint.edge, index)"
              [name]="fieldId() + '-' + endpoint.edge + '-offset'"
              [checked]="choice.selected"
              [disabled]="blocked()"
              (change)="selectOffset($event, endpoint.edge, choice.epochMs)"
            />
            <label
              class="btn btn-outline-secondary"
              [for]="offsetId(endpoint.edge, index)"
            >
              {{ offsetText(index, choice.text) }}
            </label>
          }
        </div>
      }
    }
    @if (picker().count === 'range') {
      <span class="visually-hidden" aria-live="polite">{{
        activeEdgeText()
      }}</span>
    }
  `,
})
export class HilosDateTimePickerPanel {
  /** The field's controller; the field's parent owns its disposal. */
  readonly picker = input.required<PanelController>()

  /**
   * The field's id, which prefixes this panel's own ids. Not named `id`: on a
   * `div[…]` host that input would capture the element's own `[id]` binding.
   */
  readonly fieldId = input.required<string>()

  /** The calendar snapshot the field mirrors. */
  readonly calendar = input.required<DateTimePickerCalendarSnapshot>()

  /** The draft snapshot the field mirrors. */
  readonly state =
    input.required<
      DateTimePickerSnapshot<DateTimePickerKind, DateTimePickerCount>
    >()

  /** Disabled or busy: the controls are disabled and days marked unavailable. */
  readonly blocked = input.required<boolean>()

  /** Extra description ids supplied by the mounting form. */
  readonly describedBy = input<string>()

  protected readonly focused = signal<PanelFocus | null>(null)

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef)
  private readonly injector = inject(Injector)

  /**
   * The controller this panel was drawn for. A field whose controller is
   * replaced closes the old calendar, so a late event of this panel must not
   * reach the replacement.
   */
  private owner: PanelController | null = null

  protected readonly endpoints = computed((): readonly PanelEdge[] => {
    const snapshot = this.state()
    const rows: PanelEdge[] = [{ edge: 'start', snapshot: snapshot.start }]
    if (snapshot.end !== null)
      rows.push({ edge: 'end', snapshot: snapshot.end })
    return rows
  })

  /**
   * Date fields have no time row; time draws both edges. A datetime range
   * draws the end's time once that edge has a complete date.
   */
  protected readonly timeRows = computed((): readonly PanelTimeRow[] => {
    const picker = this.picker()
    if (picker.kind === 'date') return []
    return this.endpoints()
      .filter(
        ({ edge, snapshot }) =>
          edge === 'start' || picker.kind === 'time' || this.hasDate(snapshot),
      )
      .map(({ edge, snapshot }) => ({
        edge,
        invalid: this.isInvalid(snapshot),
        label: this.rowLabel(picker, edge),
        parts: snapshot.parts.filter((part) => TIME_PARTS.includes(part.part)),
      }))
  })

  constructor() {
    // Mounted on open: bring the panel into view and focus its active day or
    // first time part, or the dialog itself when the panel is blocked.
    afterNextRender(() => {
      const element = this.host.nativeElement
      if (this.isCurrent(element)) revealPickerPanel(element)
    })
  }

  /** Whether this time part is the one the panel marks as focused. */
  protected partFocused(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): boolean {
    const current = this.focused()
    return current !== null && current.edge === edge && current.part === part
  }

  /** Invalid and gap mark the part; ambiguous stays a neutral question. */
  protected isInvalid(endpoint: PanelEndpoint): boolean {
    return (
      endpoint.result.status === 'invalid' || endpoint.result.status === 'gap'
    )
  }

  /** A provisional range interior, up to and including its hovered end. */
  protected inPreview(day: DateTimePickerCalendarDay): boolean {
    return day.span === 'preview' || day.span === 'preview-end'
  }

  /** The edge's permanent live region, plus the form's own description ids. */
  protected description(edge: DateTimePickerEdge): string {
    return [`${this.fieldId()}-${edge}-reason`, this.describedBy()]
      .filter(Boolean)
      .join(' ')
  }

  /** A range control is named with the controller's endpoint word. */
  protected partLabel(edge: DateTimePickerEdge, label: string): string {
    const picker = this.picker()
    if (picker.count === 'single') return label
    return `${picker.labels[edge === 'start' ? 'from' : 'to']} ${label}`
  }

  /** Wider of the typed text and the placeholder, so the part does not clip. */
  protected max(left: number, right: number): number {
    return Math.max(left, right)
  }

  /** The id one offset radio shares with its label. */
  protected offsetId(edge: DateTimePickerEdge, index: number): string {
    return `${this.fieldId()}-${edge}-offset-${index}`
  }

  /** The repeated hour's occurrences, named in the order the clock meets them. */
  protected offsetText(index: number, text: string): string {
    const labels = this.picker().labels
    if (index === 0) return `${labels.firstOccurrence} · ${text}`
    if (index === 1) return `${labels.secondOccurrence} · ${text}`
    return text
  }

  /** The edge the next day selection addresses, spoken for a range. */
  protected activeEdgeText(): string {
    const labels = this.picker().labels
    const edge = this.calendar().activeEdge === 'start' ? 'from' : 'to'
    return `${labels.chooseDate}: ${labels[edge]}`
  }

  protected showPreviousMonth(event: MouseEvent): void {
    if (this.isCurrent(event.currentTarget)) {
      this.picker().calendar.showPreviousMonth()
    }
  }

  protected showNextMonth(event: MouseEvent): void {
    if (this.isCurrent(event.currentTarget)) {
      this.picker().calendar.showNextMonth()
    }
  }

  protected hoverDay(event: PointerEvent, date: CalendarDate | null): void {
    if (this.isCurrent(event.currentTarget)) {
      this.picker().calendar.hoverDay(date)
    }
  }

  protected selectDay(event: MouseEvent, date: CalendarDate): void {
    if (this.isCurrent(event.currentTarget)) {
      this.picker().calendar.selectDay(date)
    }
  }

  protected selectOffset(
    event: Event,
    edge: DateTimePickerEdge,
    epochMs: number,
  ): void {
    if (this.isCurrent(event.currentTarget)) {
      this.picker().selectOffset(edge, epochMs)
    }
  }

  /**
   * Browser shortcuts are not the grid's keys. A handled key moves focus to
   * the new focus target before keydown returns, the same way a field part
   * moves on: the next key then reaches the day this render marked.
   */
  protected onDayKeydown(event: KeyboardEvent): void {
    if (
      !this.isCurrent(event.currentTarget) ||
      event.ctrlKey ||
      event.metaKey ||
      event.altKey
    ) {
      return
    }
    const element = this.host.nativeElement
    if (!this.picker().calendar.pressKey(event.key, event.shiftKey)) return
    event.preventDefault()
    this.afterRender(() => {
      if (this.isCurrent(element)) revealPickerPanel(element)
    })
  }

  /** Space selected on keydown; its keyup must not activate the day again. */
  protected onDayKeyup(event: KeyboardEvent): void {
    if (event.key === ' ') event.preventDefault()
  }

  /**
   * The field's part keys, with this panel as the container. Enter is
   * canceled so it does not submit the surrounding form; a handler must not
   * return false, which Angular would treat as preventDefault for every key.
   */
  protected onPartKeydown(
    event: KeyboardEvent,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    const target = event.currentTarget
    if (!this.isCurrent(target)) return
    if (event.key === 'Enter') {
      event.preventDefault()
      return
    }
    if (event.ctrlKey || event.metaKey || event.altKey) return
    handleDateTimePickerPartKey(
      event,
      this.picker(),
      edge,
      part,
      () => this.host.nativeElement,
      () => this.isCurrent(target),
      (callback) => {
        this.afterRender(callback)
      },
    )
  }

  /** Native insertion never lands in a part; screen-keyboard digits are separate. */
  protected onBeforeInput(event: Event): void {
    event.preventDefault()
  }

  protected focusPart(
    event: FocusEvent,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (this.isCurrent(event.currentTarget)) this.focused.set({ edge, part })
  }

  protected leavePart(
    event: FocusEvent,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!this.isCurrent(event.currentTarget)) return
    this.focused.set(null)
    // leavePart throws for a part the controller does not draw, so pad only a
    // part the current draft still shows.
    const picker = this.picker()
    if (
      !picker.state
        .get()
        [edge]?.parts.some((candidate) => candidate.part === part)
    ) {
      return
    }
    picker.leavePart(edge, part)
  }

  /** Step from an arrow button, then put focus on the part it changed. */
  protected stepPart(
    event: MouseEvent,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
    delta: 1 | -1,
  ): void {
    const button = event.currentTarget
    if (!this.isCurrent(button) || !(button instanceof HTMLElement)) return
    const column = button.parentElement
    this.picker().stepPart(edge, part, delta)
    column?.querySelector<HTMLInputElement>('input')?.focus()
  }

  /** The caption of a time row: an edge word in a range, Time for datetime. */
  private rowLabel(
    picker: PanelController,
    edge: DateTimePickerEdge,
  ): string | null {
    if (picker.count === 'range') {
      return picker.labels[edge === 'start' ? 'from' : 'to']
    }
    return picker.kind === 'datetime' ? picker.labels.time : null
  }

  /** Whether every date part of the endpoint holds a value. */
  private hasDate(endpoint: PanelEndpoint): boolean {
    return DATE_PARTS.every(
      (name) =>
        endpoint.parts.find((part) => part.part === name)?.valueNow != null,
    )
  }

  /**
   * Register `callback` after the next render, then draw now, so it runs
   * before the key event returns. The tick draws the field as well, and the
   * field is what hands this panel the snapshot the key just produced.
   */
  private afterRender(callback: () => void): void {
    afterNextRender(callback, { injector: this.injector })
    this.injector.get(ApplicationRef).tick()
  }

  /**
   * True while this panel is connected, `target` is inside it, the controller
   * is the one it was drawn for, and that controller's calendar is still open.
   */
  private isCurrent(target: EventTarget | null): boolean {
    const element = this.host.nativeElement
    this.owner ??= this.picker()
    const owner = this.owner
    return (
      element.isConnected &&
      element.contains(target as Node | null) &&
      this.picker() === owner &&
      owner.calendar.state.get().open
    )
  }
}
