// HilosDateTimePicker — the segmented field for one parent-owned controller,
// with its opener and the wide calendar and time panel it draws while the
// controller's calendar is open. The controller arrives as an input and may be
// replaced; this view mirrors its signals, closes the calendar it leaves, and
// never disposes it. On a narrow screen the panel opens in flow under the
// field, and the parts take digits from a screen keyboard at any width.
// Angular keeps a static `id` on the host even when that name is an input, so
// the host clears it: otherwise the host and the group would share one id.
import {
  ApplicationRef,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  DestroyRef,
  ElementRef,
  Injector,
  afterNextRender,
  afterRenderEffect,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core'
import {
  bindNarrowScreen,
  bindPopupDismiss,
  revealPickerPanel,
  type DateTimePickerCalendarSnapshot,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'

import { HilosDateTimePickerPanel } from './HilosDateTimePickerPanel.js'
import {
  DateTimePickerPartInput,
  dateTimePickerPartInputMode,
  handleDateTimePickerPartInput,
  handleDateTimePickerPartKey,
} from './dateTimePickerPartKeys.js'
import { mirrorHilosSignal } from './hilosSignal.js'

type FieldController = DateTimePickerController<
  DateTimePickerKind,
  DateTimePickerCount
>

type FieldSnapshot = DateTimePickerSnapshot<
  DateTimePickerKind,
  DateTimePickerCount
>

/** The controller instance this render is bound to, and whether it still is. */
interface FieldOwner {
  readonly picker: FieldController
  active: boolean
}

/** The part that holds focus, so a removed part can be told from a blur. */
interface FieldFocus {
  readonly edge: DateTimePickerEdge
  readonly part: DateTimePickerPart
  readonly input: HTMLInputElement
}

/** One edge of the current snapshot, drawn with the owner that produced it. */
interface FieldEndpoint {
  readonly edge: DateTimePickerEdge
  readonly snapshot: DateTimePickerEndpointSnapshot<DateTimePickerKind>
  readonly owner: FieldOwner
}

/** Segmented date, time or datetime field bound to one parent-owned controller. */
@Component({
  selector: 'hilos-date-time-picker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDateTimePickerPanel, DateTimePickerPartInput],
  host: { class: 'd-block', '[attr.id]': 'null' },
  template: `
    <span class="form-label d-block small mb-1" [id]="id() + '-label'">{{
      label()
    }}</span>
    <div #root class="position-relative" (keydown)="onRootKeydown($event)">
      <div
        class="input-group flex-nowrap"
        [class.input-group-lg]="narrow()"
        [class.input-group-sm]="!narrow()"
        [class.has-validation]="invalid()"
      >
        <div
          #group
          class="form-control d-flex flex-wrap align-items-center"
          role="group"
          [id]="id()"
          [attr.aria-labelledby]="id() + '-label'"
          [attr.data-id]="dataId() ?? null"
          [class.is-invalid]="invalid()"
          [class.border-primary]="focused() !== null && !invalid()"
          [class.bg-body-secondary]="disabled() || busy()"
          (mousedown)="onFieldMousedown($event)"
          (click)="onFieldClick($event)"
        >
          @for (endpoint of endpoints(); track endpoint.edge) {
            <div
              class="d-inline-flex align-items-center"
              [class]="
                narrow() ? 'w-100 mw-100 flex-wrap' : 'flex-nowrap text-nowrap'
              "
            >
              @for (
                part of endpoint.snapshot.parts;
                track part.part;
                let first = $first
              ) {
                <div class="d-inline-flex align-items-center flex-shrink-0">
                  @if (endpoint.edge === 'end' && first) {
                    <span class="mx-1 text-body-secondary" aria-hidden="true"
                      >–</span
                    >
                  }
                  <span aria-hidden="true">{{ part.prefix }}</span>
                  <input
                    hilosDateTimePickerPartInput
                    type="text"
                    role="spinbutton"
                    autocomplete="off"
                    spellcheck="false"
                    class="border-0 text-center"
                    [class]="
                      narrow()
                        ? 'form-control form-control-lg w-auto px-2 py-2'
                        : 'p-0'
                    "
                    [attr.inputmode]="partInputMode(part.part)"
                    [class.text-bg-primary]="
                      partFocused(endpoint.edge, part.part)
                    "
                    [class.bg-transparent]="
                      !partFocused(endpoint.edge, part.part)
                    "
                    [class.text-body-tertiary]="
                      !partFocused(endpoint.edge, part.part) && part.text === ''
                    "
                    [class.text-body]="
                      !partFocused(endpoint.edge, part.part) && part.text !== ''
                    "
                    [size]="max(part.text.length, part.placeholder.length)"
                    [value]="part.text || part.placeholder"
                    [attr.aria-label]="partLabel(endpoint.edge, part.label)"
                    [attr.aria-valuemin]="part.valueMin"
                    [attr.aria-valuemax]="part.valueMax"
                    [attr.aria-valuenow]="part.valueNow"
                    [attr.aria-valuetext]="part.valueText"
                    [attr.aria-invalid]="
                      isInvalid(endpoint.snapshot) ? 'true' : null
                    "
                    [attr.aria-describedby]="description(endpoint.edge)"
                    [disabled]="disabled() || busy()"
                    (keydown)="
                      onKeydown(
                        $event,
                        endpoint.owner,
                        endpoint.edge,
                        part.part
                      )
                    "
                    (beforeinput)="
                      onBeforeInput(
                        $event,
                        endpoint.owner,
                        endpoint.edge,
                        part.part
                      )
                    "
                    (focus)="
                      focusPart(
                        $event,
                        endpoint.owner,
                        endpoint.edge,
                        part.part
                      )
                    "
                    (blur)="
                      leavePart(
                        $event,
                        endpoint.owner,
                        endpoint.edge,
                        part.part
                      )
                    "
                  />
                </div>
              }
            </div>
          }
        </div>
        <button
          #opener
          type="button"
          class="btn btn-outline-secondary"
          aria-haspopup="dialog"
          [class.active]="open()"
          [disabled]="blocked()"
          [attr.aria-label]="
            picker().kind === 'time'
              ? picker().labels.chooseTime
              : picker().labels.openCalendar
          "
          [attr.aria-expanded]="open()"
          [attr.aria-controls]="open() ? id() + '-panel' : null"
          (click)="toggleCalendar($event)"
        >
          <i
            class="bi"
            [class.bi-clock]="picker().kind === 'time'"
            [class.bi-calendar3]="picker().kind !== 'time'"
            aria-hidden="true"
          ></i>
        </button>
      </div>
      @for (endpoint of endpoints(); track endpoint.edge) {
        <span
          class="visually-hidden"
          aria-live="polite"
          [id]="id() + '-' + endpoint.edge + '-reason'"
          >{{ endpoint.snapshot.reason ?? '' }}</span
        >
      }
      @if (visibleReason(); as reason) {
        <div
          aria-hidden="true"
          [class]="
            isInvalid(reason)
              ? 'invalid-tooltip d-block'
              : 'position-absolute top-100 start-0 z-3 mw-100 mt-1 px-2 py-1 small rounded text-bg-secondary'
          "
        >
          {{ reason.reason }}
        </div>
      }
      @if (panel(); as current) {
        <div
          hilosDateTimePickerPanel
          [picker]="picker()"
          [fieldId]="id()"
          [calendar]="current.calendar"
          [state]="current.state"
          [blocked]="blocked()"
          [describedBy]="describedBy()"
          [narrow]="narrow()"
        ></div>
      }
    </div>
  `,
})
export class HilosDateTimePicker {
  /** The parent's controller. The parent owns disposal; this view never calls it. */
  readonly picker = input.required<FieldController>()

  /** Stable id of the named group and its descriptions. */
  readonly id = input.required<string>()

  /** Visible group name. For datetime the parent includes the configured zone. */
  readonly label = input.required<string>()

  /** Extra description ids supplied by the mounting form. */
  readonly describedBy = input<string>()

  /** The mounting form's stable selector for the group. */
  readonly dataId = input<string>()

  private readonly state = signal<FieldSnapshot | null>(null)
  protected readonly disabled = signal(false)
  protected readonly busy = signal(false)
  private readonly calendar = signal<DateTimePickerCalendarSnapshot | null>(
    null,
  )
  private readonly owner = signal<FieldOwner | null>(null)
  protected readonly focused = signal<FieldFocus | null>(null)
  protected readonly narrow = signal(false)

  private readonly injector = inject(Injector)
  private readonly root = viewChild<ElementRef<HTMLElement>>('root')
  private readonly group = viewChild<ElementRef<HTMLElement>>('group')
  private readonly opener = viewChild<ElementRef<HTMLButtonElement>>('opener')

  /**
   * Whether closing the calendar puts focus back on the opener. The opener,
   * Escape and a completed date return it; a press or focus outside, and a
   * replaced or removed controller, do not.
   */
  private returnFocus = true

  /**
   * The owner a press on the field started with, and whether that press opens
   * the calendar. Decided on mousedown from the live state and checked again
   * on click, so a gesture that outlives its controller does nothing.
   */
  private fieldPress: {
    readonly source: FieldOwner
    readonly opens: boolean
  } | null = null

  /** Whether the last render drew the calendar open, so a closed mount moves no focus. */
  private wasOpen = false

  protected readonly open = computed(() => this.calendar()?.open ?? false)

  protected readonly blocked = computed(() => this.disabled() || this.busy())

  /** What the open panel draws; null while closed or before the first mirror. */
  protected readonly panel = computed(() => {
    const calendar = this.calendar()
    const state = this.state()
    if (calendar === null || !calendar.open || state === null) return null
    return { calendar, state }
  })

  /**
   * Edges of the current snapshot. The first template pass can run before the
   * effect has mirrored a controller, and an empty list is what that pass draws.
   */
  protected readonly endpoints = computed((): readonly FieldEndpoint[] => {
    const snapshot = this.state()
    const source = this.owner()
    if (snapshot === null || source === null) return []
    const rows: FieldEndpoint[] = [
      { edge: 'start', snapshot: snapshot.start, owner: source },
    ]
    if (snapshot.end !== null) {
      rows.push({ edge: 'end', snapshot: snapshot.end, owner: source })
    }
    return rows
  })

  /** The focused edge's reason, or the other edge's when the focused one has none. */
  protected readonly visibleReason = computed(() => {
    const currentFocus = this.focused()
    const snapshot = this.state()
    if (currentFocus === null || snapshot === null || this.open()) return null
    const current = snapshot[currentFocus.edge]
    if (current?.reason) return current
    return (
      this.endpoints().find((endpoint) => endpoint.snapshot.reason)?.snapshot ??
      null
    )
  })

  protected readonly invalid = computed(() =>
    this.endpoints().some((endpoint) => this.isInvalid(endpoint.snapshot)),
  )

  constructor() {
    // One width subscription per field, independent of the controller. The
    // first value arrives synchronously, so the first render uses the
    // matching layout.
    inject(DestroyRef).onDestroy(
      bindNarrowScreen((value) => {
        this.onNarrow(value)
      }),
    )
    // The controller arrives via input and can be replaced. Mirrors live in
    // the effect so its cleanup drops the previous instance; the view never
    // disposes a controller, and a late callback sees `active` go false.
    // A replacement's calendar is closed before it is drawn: nobody opened it
    // here. The first run keeps an already open calendar open.
    let mounted = false
    effect((onCleanup) => {
      const picker = this.picker()
      if (mounted) picker.calendar.close()
      mounted = true
      const source: FieldOwner = { picker, active: true }
      this.owner.set(source)
      const stops = [
        mirrorHilosSignal(picker.state, this.state),
        mirrorHilosSignal(picker.disabled, this.disabled),
        mirrorHilosSignal(picker.busy, this.busy),
        mirrorHilosSignal(picker.calendar.state, this.calendar),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        source.active = false
        this.returnFocus = false
        picker.calendar.close()
      })
    })
    // While open and wide, a press or focus outside the root closes without
    // returning focus; the narrow panel lies in flow and has no such close.
    // Closing otherwise returns focus to the opener once the panel is gone; a
    // field mounted closed has no previous state and moves nothing.
    afterRenderEffect((onCleanup) => {
      const open = this.open()
      const narrow = this.narrow()
      const element = this.root()?.nativeElement
      const wasOpen = this.wasOpen
      this.wasOpen = open
      if (open) {
        this.returnFocus = true
        if (element === undefined || narrow) return
        onCleanup(
          bindPopupDismiss(element, () => {
            this.returnFocus = false
            this.picker().calendar.close()
          }),
        )
      } else if (wasOpen && this.returnFocus) {
        this.opener()?.nativeElement.focus()
      }
    })
    // Native disabled can drop focus to body during the render that applies
    // it, so the focused control is captured here, before this component's
    // template is checked, and the dialog takes focus after that render.
    effect(() => {
      if (this.blocked()) untracked(() => this.keepFocusInPanel())
    })
    // Removing a focused part does not emit blur in every DOM implementation.
    // Read the snapshot so this runs after the render that dropped the part.
    afterRenderEffect(() => {
      const snapshot = this.state()
      const current = this.focused()
      if (current === null) return
      const group = this.group()?.nativeElement
      if (
        snapshot === null ||
        group === undefined ||
        !group.contains(current.input) ||
        current.input.ownerDocument.activeElement !== current.input
      ) {
        this.focused.set(null)
      }
    })
  }

  /** Whether this part is the one the field currently marks as focused. */
  protected partFocused(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): boolean {
    const current = this.focused()
    return current !== null && current.edge === edge && current.part === part
  }

  /** Invalid and gap mark the field; ambiguous stays a neutral question. */
  protected isInvalid(
    endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
  ): boolean {
    return (
      endpoint.result.status === 'invalid' || endpoint.result.status === 'gap'
    )
  }

  /** The edge's permanent live region, plus the form's own description ids. */
  protected description(edge: DateTimePickerEdge): string {
    return [`${this.id()}-${edge}-reason`, this.describedBy()]
      .filter(Boolean)
      .join(' ')
  }

  /** A range part is named with the controller's endpoint word. */
  protected partLabel(edge: DateTimePickerEdge, label: string): string {
    if (this.picker().count === 'single') return label
    return `${this.picker().labels[edge === 'start' ? 'from' : 'to']} ${label}`
  }

  /** Wider of the typed text and the placeholder, so the part does not clip. */
  protected max(left: number, right: number): number {
    return Math.max(left, right)
  }

  /** The screen keyboard a part offers: numeric, or none for AM/PM and offset. */
  protected partInputMode(part: DateTimePickerPart): 'none' | 'numeric' {
    return dateTimePickerPartInputMode(part)
  }

  /**
   * Browser shortcuts are not this field's keys. Tab and Enter are not
   * canceled either: a handler must not return false, which Angular would
   * treat as preventDefault.
   */
  protected onKeydown(
    event: KeyboardEvent,
    source: FieldOwner,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    const target = event.target
    if (!this.isCurrent(target, source)) return
    if (
      event.altKey &&
      !event.ctrlKey &&
      !event.metaKey &&
      event.key === 'ArrowDown'
    ) {
      event.preventDefault()
      this.openFromField(source)
      return
    }
    if (event.ctrlKey || event.metaKey || event.altKey) return
    handleDateTimePickerPartKey(
      event,
      source.picker,
      edge,
      part,
      () => this.group()?.nativeElement ?? null,
      () => this.isCurrent(target, source),
      (callback) => {
        this.renderNow(callback)
      },
    )
  }

  /**
   * A screen-keyboard digit or deletion takes the path a key takes. Native
   * insertion is always canceled, here and by the part's own directive.
   */
  protected onBeforeInput(
    event: InputEvent,
    source: FieldOwner,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    const target = event.target
    handleDateTimePickerPartInput(
      event,
      source.picker,
      edge,
      part,
      () => this.group()?.nativeElement ?? null,
      () => this.isCurrent(target, source),
      (callback) => {
        this.renderNow(callback)
      },
    )
  }

  /** The opener toggles the calendar. */
  protected toggleCalendar(event: MouseEvent): void {
    if (!this.isCurrent(event.target)) return
    const calendar = this.picker().calendar
    if (calendar.state.get().open) calendar.close()
    else calendar.open()
  }

  /**
   * Narrow only: a press on the closed field with no part focused opens the
   * calendar instead of focusing a part, so the press is decided here, from
   * the live state, and the focus it would move is canceled.
   */
  protected onFieldMousedown(event: MouseEvent): void {
    const source = this.owner()
    if (source === null) return
    const opens =
      this.narrow() &&
      !source.picker.calendar.state.get().open &&
      this.focused() === null &&
      this.isCurrent(event.target, source)
    this.fieldPress = { source, opens }
    if (opens) event.preventDefault()
  }

  /**
   * Narrow only: the press decided on mousedown opens the calendar, or closes
   * an open one. Focus stays in a pressed part and returns to the opener
   * otherwise. A press whose owner has since been replaced does nothing.
   */
  protected onFieldClick(event: MouseEvent): void {
    const press = this.fieldPress
    this.fieldPress = null
    if (
      press === null ||
      !this.narrow() ||
      !this.isCurrent(event.target, press.source)
    ) {
      return
    }
    if (press.opens) press.source.picker.calendar.open()
    else if (press.source.picker.calendar.state.get().open) {
      this.returnFocus = !(event.target instanceof HTMLInputElement)
      press.source.picker.calendar.close()
    }
  }

  /**
   * Escape anywhere inside the root closes an open calendar, and only that.
   * The close is drawn before keydown returns, as a part key's render is:
   * without a zone the next render would come a task later, and until then
   * the opener still reports the panel open and the panel is still there.
   */
  protected onRootKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !this.isCurrent(event.target)) return
    const calendar = this.picker().calendar
    if (!calendar.state.get().open) return
    event.preventDefault()
    event.stopPropagation()
    calendar.close()
    this.injector.get(ApplicationRef).tick()
  }

  /** Alt+ArrowDown opens the calendar, or brings an open panel back into focus. */
  private openFromField(source: FieldOwner): void {
    const calendar = source.picker.calendar
    if (!calendar.state.get().open) {
      calendar.open()
      return
    }
    const panel =
      this.root()?.nativeElement.querySelector<HTMLElement>('[role="dialog"]')
    if (panel) revealPickerPanel(panel)
  }

  /**
   * A width change keeps the open panel, its value and its draft, but a
   * control only one width draws can hold focus. It is captured here, before
   * the render that removes it, and the dialog takes focus after that render
   * when focus fell to the body, unless the field, its controller or the
   * calendar changed in between.
   */
  private onNarrow(value: boolean): void {
    if (value === this.narrow()) return
    const element = this.root()?.nativeElement
    const source = this.owner()
    const target = element?.ownerDocument.activeElement
    if (
      this.open() &&
      element !== undefined &&
      source !== null &&
      target instanceof HTMLElement &&
      element.contains(target)
    ) {
      afterNextRender(
        () => {
          if (
            this.root()?.nativeElement === element &&
            this.owner() === source &&
            source.active &&
            this.open() &&
            !target.isConnected &&
            element.ownerDocument.activeElement === element.ownerDocument.body
          ) {
            element
              .querySelector<HTMLElement>('[role="dialog"]')
              ?.focus({ preventScroll: true })
          }
        },
        { injector: this.injector },
      )
    }
    this.narrow.set(value)
  }

  /**
   * A completed digit can insert a part that is not in the DOM yet. Register
   * `callback` with `afterNextRender`, draw this component, then tick so that
   * it runs before the event returns. The next key then reaches the part this
   * render added.
   */
  private renderNow(callback: () => void): void {
    afterNextRender(callback, { injector: this.injector })
    this.injector.get(ChangeDetectorRef).detectChanges()
    this.injector.get(ApplicationRef).tick()
  }

  /**
   * Called while blocking is being applied, before the render that disables
   * the controls. When focus was on one of them, the dialog takes it after
   * that render, unless the field, its controller or the calendar changed in
   * between, or focus has since moved somewhere on purpose.
   */
  private keepFocusInPanel(): void {
    if (!this.open()) return
    const element = this.root()?.nativeElement
    const source = this.owner()
    const target = element?.ownerDocument.activeElement
    if (
      element === undefined ||
      source === null ||
      !(
        target instanceof HTMLInputElement ||
        target instanceof HTMLButtonElement
      ) ||
      !element.contains(target)
    ) {
      return
    }
    afterNextRender(
      () => {
        const active = element.ownerDocument.activeElement
        if (
          this.root()?.nativeElement === element &&
          this.owner() === source &&
          source.active &&
          this.open() &&
          element.contains(target) &&
          target.disabled &&
          (active === target || active === element.ownerDocument.body)
        ) {
          element
            .querySelector<HTMLElement>('[role="dialog"]')
            ?.focus({ preventScroll: true })
        }
      },
      { injector: this.injector },
    )
  }

  protected focusPart(
    event: FocusEvent,
    source: FieldOwner,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!this.isCurrent(event.target, source)) return
    const input = event.currentTarget
    if (!(input instanceof HTMLInputElement)) return
    this.focused.set({ edge, part, input })
  }

  protected leavePart(
    event: FocusEvent,
    source: FieldOwner,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!this.isCurrent(event.target, source)) return
    if (this.focused()?.input === event.currentTarget) this.focused.set(null)
    // Removing a focused part blurs it during render. leavePart throws when
    // the part is not one of this controller's own, so pad only a part the
    // current draft still shows.
    if (
      !source.picker.state
        .get()
        [edge]?.parts.some((candidate) => candidate.part === part)
    )
      return
    source.picker.leavePart(edge, part)
  }

  /**
   * True when `source` is still the controller this view is bound to and
   * `target` is still inside it. A replaced controller's late events fail here.
   */
  private isCurrent(
    target: EventTarget | null,
    source: FieldOwner | null = this.owner(),
  ): boolean {
    const element = this.root()?.nativeElement
    return (
      source !== null &&
      source.active &&
      source === this.owner() &&
      source.picker === this.picker() &&
      element !== undefined &&
      element.isConnected &&
      element.contains(target as Node | null)
    )
  }
}
