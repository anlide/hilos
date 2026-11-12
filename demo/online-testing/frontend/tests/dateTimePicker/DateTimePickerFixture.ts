import { DOCUMENT } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  type WritableSignal,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core'
import {
  HilosDateTimePicker,
  HilosModal,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import {
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  createDateTimePickerController,
  createSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerFactors,
  type DateTimePickerKind,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'

// Explicit examples of presentation factors; the browser locale supplies none.
const FACTORS = {
  'en-GB': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
  },
  'en-US': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [6, 0],
    dateOrder: ['month', 'day', 'year'],
    hourCycle: 'h12',
  },
  'pl-PL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
    dateSeparator: '.',
    placeholders: {
      ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
      year: 'rrrr',
    },
    monthNames: [
      { short: 'sty', full: 'styczeń' },
      { short: 'lut', full: 'luty' },
      { short: 'mar', full: 'marzec' },
      { short: 'kwi', full: 'kwiecień' },
      { short: 'maj', full: 'maj' },
      { short: 'cze', full: 'czerwiec' },
      { short: 'lip', full: 'lipiec' },
      { short: 'sie', full: 'sierpień' },
      { short: 'wrz', full: 'wrzesień' },
      { short: 'paź', full: 'październik' },
      { short: 'lis', full: 'listopad' },
      { short: 'gru', full: 'grudzień' },
    ],
    weekdayNames: [
      { short: 'niedz', full: 'niedziela' },
      { short: 'pon', full: 'poniedziałek' },
      { short: 'wt', full: 'wtorek' },
      { short: 'śr', full: 'środa' },
      { short: 'czw', full: 'czwartek' },
      { short: 'pt', full: 'piątek' },
      { short: 'sob', full: 'sobota' },
    ],
  },
  'en-IL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [5, 6],
  },
} satisfies Record<string, DateTimePickerFactors>

type FactorKey = keyof typeof FACTORS

type FixturePicker = DateTimePickerController<
  DateTimePickerKind,
  DateTimePickerCount
>

type FixtureSnapshot = DateTimePickerSnapshot<
  DateTimePickerKind,
  DateTimePickerCount
>

function isFactorKey(value: string): value is FactorKey {
  return Object.hasOwn(FACTORS, value)
}

/** Standalone test fixture for the segmented date-time field. */
@Component({
  selector: 'date-time-picker-fixture',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDateTimePicker, HilosModal],
  template: `
    <main class="container py-4" data-id="date-time-picker-fixture">
      <h1 class="h4 mb-4">DateTimePicker fixture</h1>
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-kind">Kind</label>
          <select
            id="fixture-kind"
            class="form-select"
            data-id="fixture-kind"
            (change)="onKind($event)"
          >
            <option [value]="'date'" [selected]="kind() === 'date'">
              date
            </option>
            <option [value]="'time'" [selected]="kind() === 'time'">
              time
            </option>
            <option [value]="'datetime'" [selected]="kind() === 'datetime'">
              datetime
            </option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-count">Count</label>
          <select
            id="fixture-count"
            class="form-select"
            data-id="fixture-count"
            (change)="onCount($event)"
          >
            <option [value]="'single'" [selected]="count() === 'single'">
              single
            </option>
            <option [value]="'range'" [selected]="count() === 'range'">
              range
            </option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-factors"
            >Display factors</label
          >
          <select
            id="fixture-factors"
            class="form-select"
            data-id="fixture-factors"
            (change)="onFactors($event)"
          >
            <option
              [value]="'en-GB'"
              lang="en-GB"
              [selected]="factors() === 'en-GB'"
            >
              English (United Kingdom)
            </option>
            <option
              [value]="'en-US'"
              lang="en-US"
              [selected]="factors() === 'en-US'"
            >
              English (United States)
            </option>
            <option
              [value]="'pl-PL'"
              lang="pl-PL"
              [selected]="factors() === 'pl-PL'"
            >
              Polski (Polska)
            </option>
            <option
              [value]="'en-IL'"
              lang="en-IL"
              [selected]="factors() === 'en-IL'"
            >
              English (Israel)
            </option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-zone">Time zone</label>
          <select
            id="fixture-zone"
            class="form-select"
            data-id="fixture-zone"
            (change)="onZone($event)"
          >
            <option
              [value]="'Europe/Warsaw'"
              [selected]="zone() === 'Europe/Warsaw'"
            >
              Europe/Warsaw
            </option>
            <option [value]="'UTC'" [selected]="zone() === 'UTC'">UTC</option>
            <option
              [value]="'Pacific/Apia'"
              [selected]="zone() === 'Pacific/Apia'"
            >
              Pacific/Apia
            </option>
            <option
              [value]="'Nowhere/Zone'"
              [selected]="zone() === 'Nowhere/Zone'"
            >
              Nowhere/Zone
            </option>
          </select>
        </div>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-today">Today</label>
          <input
            id="fixture-today"
            class="form-control"
            type="text"
            data-id="fixture-today"
            [value]="today()"
            (input)="onToday($event)"
          />
        </div>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label" for="fixture-min">Minimum day</label>
          <select
            id="fixture-min"
            class="form-select"
            data-id="fixture-min"
            (change)="onMinDate($event)"
          >
            <option [value]="''" [selected]="minDate() === ''">none</option>
            <option
              [value]="'2026-10-13'"
              [selected]="minDate() === '2026-10-13'"
            >
              2026-10-13
            </option>
          </select>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-3 mb-4">
        <div class="form-check">
          <input
            id="fixture-seconds"
            type="checkbox"
            class="form-check-input"
            data-id="fixture-seconds"
            [checked]="seconds()"
            (change)="onSeconds($event)"
          />
          <label class="form-check-label" for="fixture-seconds">Seconds</label>
        </div>
        <div class="form-check">
          <input
            id="fixture-busy"
            type="checkbox"
            class="form-check-input"
            data-id="fixture-busy"
            [checked]="busy()"
            (change)="onBusy($event)"
          />
          <label class="form-check-label" for="fixture-busy">Busy</label>
        </div>
        <div class="form-check">
          <input
            id="fixture-disabled"
            type="checkbox"
            class="form-check-input"
            data-id="fixture-disabled"
            [checked]="disabled()"
            (change)="onDisabled($event)"
          />
          <label class="form-check-label" for="fixture-disabled"
            >Disabled</label
          >
        </div>
        <div>
          <input
            id="fixture-dark"
            type="checkbox"
            class="btn-check"
            data-id="fixture-dark"
            [checked]="dark()"
            (change)="onDark($event)"
          />
          <label class="btn btn-lg btn-outline-secondary" for="fixture-dark"
            >Dark theme</label
          >
        </div>
      </div>
      <div class="mb-4">
        @if (!inModal()) {
          @for (mount of mounts(); track mount) {
            <hilos-date-time-picker
              id="fixture-picker"
              [picker]="picker()"
              [label]="label()"
              dataId="fixture-picker"
            />
          }
        }
      </div>
      <button
        type="button"
        class="btn btn-lg btn-outline-primary"
        data-id="fixture-open-modal"
        (click)="inModal.set(true)"
      >
        Open in modal
      </button>
      <button
        type="button"
        class="btn btn-outline-primary"
        data-id="fixture-mount-open-time"
        [disabled]="!canMountOpenTime()"
        (click)="mountOpenTime()"
      >
        Mount open time
      </button>
      <button
        type="button"
        class="btn btn-outline-primary"
        data-id="fixture-load"
        [disabled]="!canLoad()"
        aria-describedby="fixture-load-description"
        (click)="loadValue()"
      >
        Load 1792209600000
      </button>
      <p id="fixture-load-description" class="form-text">
        Load the start of a datetime field in a usable time zone.
      </p>
      <div class="mb-2">
        <label class="form-label me-2" for="fixture-result">Result</label>
        <output
          id="fixture-result"
          class="font-monospace text-break"
          data-id="fixture-result"
          >{{ resultText() }}</output
        >
      </div>
      <div>
        <label class="form-label me-2" for="fixture-changes">Changes</label>
        <output id="fixture-changes" data-id="fixture-changes">{{
          changes()
        }}</output>
      </div>
      <hilos-modal [(open)]="inModal" title="Schedule maintenance">
        @if (inModal()) {
          <div class="mb-3">
            <label class="form-label" for="fixture-modal-title">Title</label>
            <input
              id="fixture-modal-title"
              type="text"
              class="form-control form-control-lg"
              data-id="fixture-modal-title"
              value="Database upgrade"
              data-autofocus
            />
          </div>
          <hilos-date-time-picker
            id="fixture-modal-picker"
            [picker]="picker()"
            [label]="label()"
            dataId="fixture-modal-picker"
          />
          <p class="form-text mt-2 mb-0">Choose when maintenance starts.</p>
          <div class="mt-3">
            <label class="form-label" for="fixture-modal-description"
              >What visitors read</label
            >
            <textarea
              id="fixture-modal-description"
              class="form-control form-control-lg"
              data-id="fixture-modal-description"
              rows="3"
            ></textarea>
          </div>
          <p class="small text-body-secondary mt-2 mb-0">
            This is an advance announcement. It does not close the service; the
            freeze in Maintenance starts the actual work.
          </p>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-lg btn-secondary"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-lg btn-primary"
            (click)="requestClose()"
          >
            Schedule
          </button>
        </ng-template>
      </hilos-modal>
    </main>
  `,
})
export class DateTimePickerFixture {
  private readonly destroyRef = inject(DestroyRef)
  private readonly busySignal = createSignal(false)
  private readonly disabledSignal = createSignal(false)
  protected readonly kind = signal<DateTimePickerKind>('datetime')
  protected readonly count = signal<DateTimePickerCount>('single')
  protected readonly factors = signal<FactorKey>('en-GB')
  protected readonly zone = signal('Europe/Warsaw')
  protected readonly today = signal('2026-10-13')
  protected readonly minDate = signal('')
  protected readonly seconds = signal(false)
  protected readonly busy = hilosSignal(this.busySignal)
  protected readonly disabled = hilosSignal(this.disabledSignal)
  protected readonly changes = signal(0)
  private readonly mountId = signal(0)
  protected readonly mounts = computed(() => [this.mountId()])
  protected readonly inModal = signal(false)
  protected readonly dark = signal(false)
  private readonly document = inject(DOCUMENT)
  protected readonly picker: WritableSignal<FixturePicker>
  private readonly snapshot: WritableSignal<FixtureSnapshot>
  protected readonly label = computed(() =>
    this.kind() === 'datetime' ? `Starts, ${this.zone()}` : 'Starts',
  )
  protected readonly canLoad = computed(
    () => this.kind() === 'datetime' && this.zone() !== 'Nowhere/Zone',
  )
  protected readonly canMountOpenTime = computed(
    () => this.kind() === 'time' && this.count() === 'single',
  )

  constructor() {
    const picker = signal(this.createPicker())
    this.picker = picker
    this.snapshot = signal(picker().state.get())
    effect((onCleanup) => {
      onCleanup(mirrorHilosSignal(this.picker().state, this.snapshot))
    })
    this.destroyRef.onDestroy(() => this.picker().dispose())
  }

  protected resultText(): string {
    return JSON.stringify(this.snapshot().result)
  }

  protected onKind(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    if (value !== 'date' && value !== 'time' && value !== 'datetime') return
    this.kind.set(value)
    this.replacePicker()
  }

  protected onCount(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    if (value !== 'single' && value !== 'range') return
    this.count.set(value)
    this.replacePicker()
  }

  protected onFactors(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    if (!isFactorKey(value)) return
    this.factors.set(value)
    this.picker().setFactors(FACTORS[value])
  }

  protected onZone(event: Event): void {
    this.zone.set((event.target as HTMLSelectElement).value)
    this.replacePicker()
  }

  protected onToday(event: Event): void {
    this.today.set((event.target as HTMLInputElement).value)
    this.replacePicker()
  }

  protected onMinDate(event: Event): void {
    this.minDate.set((event.target as HTMLSelectElement).value)
    this.replacePicker()
  }

  protected onSeconds(event: Event): void {
    const show = (event.target as HTMLInputElement).checked
    this.seconds.set(show)
    this.picker().setShowSeconds(show)
  }

  protected onBusy(event: Event): void {
    this.busySignal.set((event.target as HTMLInputElement).checked)
  }

  protected onDisabled(event: Event): void {
    this.disabledSignal.set((event.target as HTMLInputElement).checked)
  }

  protected onDark(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked
    this.dark.set(checked)
    this.document.documentElement.setAttribute(
      'data-bs-theme',
      checked ? 'dark' : 'light',
    )
  }

  protected loadValue(): void {
    if (!this.canLoad()) return
    this.picker().setEndpointValue('start', 1792209600000)
  }

  /** Prepare initial-open time controls with their lock set before mounting. */
  protected mountOpenTime(): void {
    if (!this.canMountOpenTime()) return
    const previous = this.picker().state.get().result
    const wasBusy = this.busySignal.get()
    const wasDisabled = this.disabledSignal.get()
    this.picker().dispose()
    this.busySignal.set(false)
    this.disabledSignal.set(false)
    const current = this.createPicker()
    if (previous.status === 'valid') current.setValue(previous.value)
    current.calendar.open()
    this.busySignal.set(wasBusy)
    this.disabledSignal.set(wasDisabled)
    this.picker.set(current)
    this.mountId.update((id) => id + 1)
  }

  private replacePicker(): void {
    this.picker().dispose()
    this.picker.set(this.createPicker())
  }

  private createPicker(): FixturePicker {
    const currentToday = this.today()
    const currentMin = this.minDate()
    const options = {
      count: this.count(),
      value: null,
      factors: FACTORS[this.factors()],
      showSeconds: this.seconds(),
      busy: () => this.busySignal.get(),
      disabled: () => this.disabledSignal.get(),
      onChange: () => this.changes.update((count) => count + 1),
    }
    switch (this.kind()) {
      case 'date':
        return createDateTimePickerController({
          ...options,
          kind: 'date',
          today: () => currentToday,
          ...(currentMin ? { minDate: currentMin } : {}),
        })
      case 'time':
        return createDateTimePickerController({
          ...options,
          kind: 'time',
        })
      case 'datetime':
        return createDateTimePickerController({
          ...options,
          kind: 'datetime',
          timeZone: this.zone(),
          today: () => currentToday,
          ...(currentMin ? { minDate: currentMin } : {}),
        })
    }
  }
}
