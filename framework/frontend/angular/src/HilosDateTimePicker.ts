// HilosDateTimePicker — the segmented field for one parent-owned controller.
// The controller arrives as an input and may be replaced; this view mirrors
// its signals and never disposes it. The calendar, the narrow layout and
// screen-keyboard digits are separate surfaces.
// Angular keeps a static `id` on the host even when that name is an input, so
// the host clears it: otherwise the host and the group would share one id.
import {
  ApplicationRef,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  afterRenderEffect,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerEndpointSnapshot,
  DateTimePickerKind,
  DateTimePickerPart,
  DateTimePickerSnapshot,
} from '@hilos/core/base'

import { handleDateTimePickerPartKey } from './dateTimePickerPartKeys.js'
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
  host: { class: 'd-block', '[attr.id]': 'null' },
  template: `
    <span class="form-label d-block small mb-1" [id]="id() + '-label'">{{
      label()
    }}</span>
    <div #root class="position-relative">
      <div
        class="input-group input-group-sm flex-nowrap"
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
        >
          @for (endpoint of endpoints(); track endpoint.edge) {
            <div
              class="d-inline-flex align-items-center flex-nowrap text-nowrap"
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
                    type="text"
                    role="spinbutton"
                    autocomplete="off"
                    spellcheck="false"
                    class="border-0 p-0 text-center"
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
                    (beforeinput)="onBeforeInput($event)"
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
  private readonly owner = signal<FieldOwner | null>(null)
  protected readonly focused = signal<FieldFocus | null>(null)

  private readonly injector = inject(Injector)
  private readonly root = viewChild<ElementRef<HTMLElement>>('root')
  private readonly group = viewChild<ElementRef<HTMLElement>>('group')

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
    if (currentFocus === null || snapshot === null) return null
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
    // The controller arrives via input and can be replaced. Mirrors live in
    // the effect so its cleanup drops the previous instance; the view never
    // disposes a controller, and a late callback sees `active` go false.
    effect((onCleanup) => {
      const picker = this.picker()
      const source: FieldOwner = { picker, active: true }
      this.owner.set(source)
      const stops = [
        mirrorHilosSignal(picker.state, this.state),
        mirrorHilosSignal(picker.disabled, this.disabled),
        mirrorHilosSignal(picker.busy, this.busy),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        source.active = false
      })
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

  /**
   * Browser shortcuts are not this field's keys. Tab and Enter are not
   * canceled either: a handler must not return false, which Angular would
   * treat as preventDefault.
   *
   * A completed digit can insert a part that is not in the DOM yet. Register
   * the focus move with `afterNextRender`, draw this component, then tick so
   * that callback runs before keydown returns. The next key then reaches the
   * part this render added.
   */
  protected onKeydown(
    event: KeyboardEvent,
    source: FieldOwner,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    const target = event.target
    if (!this.isCurrent(target, source)) return
    if (event.ctrlKey || event.metaKey || event.altKey) return
    handleDateTimePickerPartKey(
      event,
      source.picker,
      edge,
      part,
      () => this.group()?.nativeElement ?? null,
      () => this.isCurrent(target, source),
      (callback) => {
        afterNextRender(callback, { injector: this.injector })
        this.injector.get(ChangeDetectorRef).detectChanges()
        this.injector.get(ApplicationRef).tick()
      },
    )
  }

  /** Native insertion never lands in a segment; screen-keyboard digits are separate. */
  protected onBeforeInput(event: Event): void {
    event.preventDefault()
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
