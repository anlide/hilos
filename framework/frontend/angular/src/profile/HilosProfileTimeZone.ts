import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  model,
  signal,
  untracked,
} from '@angular/core'
import {
  computedSignal,
  createSignal,
  hilosImpersonation,
  hilosSignedIn,
  type HilosTimeZonePicker,
  type HilosTimeZonePickerRow,
} from '@hilos/core/base'
import { HilosActionError } from '../HilosActionError.js'
import { HilosModal } from '../HilosModal.js'
import { hilosSignal, mirrorHilosSignal } from '../hilosSignal.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

/** Immediate account-zone choice over the core catalog, search and clock. */
@Component({
  selector: 'hilos-profile-time-zone',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosActionError, HilosModal],
  template: `
    <hilos-modal
      [open]="shown()"
      title="Time zone"
      [busy]="action.busy()"
      (openChange)="open.set($event)"
    >
      <div data-id="profile-time-zone-modal">
        <hilos-action-error
          [action]="action"
          detailsTitle="Could not change time zone"
        />
        <input
          type="search"
          class="form-control"
          placeholder="Search time zones"
          aria-label="Search time zones"
          data-id="profile-time-zone-search"
          data-autofocus
          [value]="query()"
          [disabled]="action.busy()"
          (input)="picker().setQuery($any($event.target).value)"
        />
        @for (group of groups(); track $index) {
          <div class="mt-3">
            @if (group.heading) {
              <h6 class="small fw-semibold text-body-secondary mb-1">
                {{ group.heading }}
              </h6>
            }
            <div class="list-group">
              @for (row of group.rows; track row.timeZone) {
                <button
                  type="button"
                  class="list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative"
                  [class.active]="row.selected"
                  [attr.aria-current]="row.selected ? 'true' : null"
                  [disabled]="action.busy() || !row.available"
                  [attr.aria-busy]="
                    action.busy() && submitted() === row.timeZone ? true : null
                  "
                  [attr.data-id]="
                    row.timeZone === null
                      ? 'profile-time-zone-automatic'
                      : 'profile-time-zone-option-' + row.timeZone
                  "
                  (click)="choose(row)"
                >
                  <span class="flex-grow-1 text-break">
                    {{ row.timeZone === null ? 'Automatic' : row.label }}
                    @if (row.timeZone === null || !row.available) {
                      <span
                        class="d-block small"
                        [class.text-body-secondary]="!row.selected"
                        >{{
                          row.timeZone === null
                            ? row.label + ' — from this browser'
                            : 'Unavailable in this browser'
                        }}</span
                      >
                    }
                  </span>
                  <span class="small text-nowrap">{{ row.time ?? '—' }}</span>
                  <i
                    class="bi bi-check2"
                    [class.invisible]="
                      !row.selected ||
                      (action.loading() && submitted() === row.timeZone)
                    "
                    aria-hidden="true"
                  ></i>
                  @if (action.loading() && submitted() === row.timeZone) {
                    <span
                      class="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
                      role="status"
                      data-id="profile-time-zone-loading"
                      ><span class="visually-hidden">Loading…</span></span
                    >
                  }
                </button>
              }
            </div>
          </div>
        }
        @if (rows().length === 0) {
          <p
            class="small text-body-secondary mt-3 mb-0"
            data-id="profile-time-zone-empty"
          >
            No time zones match
          </p>
        }
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-outline-secondary"
          [disabled]="action.busy()"
          data-id="profile-time-zone-close"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfileTimeZone {
  /** The profile's picker, disposed by its owner on context replacement. */
  readonly picker = input.required<HilosTimeZonePicker>()
  /** Whether Change asked for the window, two-way through `[(open)]`. */
  readonly open = model(false)
  private readonly signedIn = hilosSignal(hilosSignedIn)
  private readonly impersonation = hilosSignal(hilosImpersonation)
  private readonly allowed = computed(
    () => this.signedIn() && this.impersonation() === null,
  )
  protected readonly shown = computed(() => this.open() && this.allowed())
  protected readonly query = signal('')
  protected readonly rows = signal<readonly HilosTimeZonePickerRow[]>([])
  private readonly automatic = signal<HilosTimeZonePickerRow | null>(null)
  protected readonly groups = computed(() => {
    const automatic = this.automatic()
    return [
      ...(automatic ? [{ heading: null, rows: [automatic] }] : []),
      {
        heading: automatic ? 'All time zones' : 'Found',
        rows: this.rows(),
      },
    ]
  })
  protected readonly action = createHilosTrackedAction()
  protected readonly submitted = signal<string | null>(null)
  private opening = 0

  constructor() {
    effect((onCleanup) => {
      const picker = this.picker()
      const asked = this.open()
      const allowed = this.allowed()
      untracked(() => {
        this.opening++
        // Only this browser opening reads previews. Shared worker/node state
        // has no reader here (framework-first.md, runtime/rt-context.md).
        const previewOpen = createSignal(false)
        const off = [
          mirrorHilosSignal(picker.query, this.query),
          mirrorHilosSignal(
            computedSignal(() => (previewOpen.get() ? picker.rows.get() : [])),
            this.rows,
          ),
          mirrorHilosSignal(
            computedSignal(() =>
              previewOpen.get() ? picker.automatic.get() : null,
            ),
            this.automatic,
          ),
        ]
        if (asked && allowed) {
          this.action.clearError()
          picker.start()
          previewOpen.set(true)
        } else if (asked) {
          this.open.set(false)
        }
        onCleanup(() => {
          this.opening++
          previewOpen.set(false)
          picker.stop()
          off.forEach((stop) => stop())
        })
      })
    })
  }

  protected async choose(row: HilosTimeZonePickerRow): Promise<void> {
    if (
      !this.open() ||
      this.action.busy() ||
      !row.available ||
      !hilosSignedIn.get() ||
      hilosImpersonation.get() !== null
    ) {
      return
    }
    const sentFrom = this.opening
    this.submitted.set(row.timeZone)
    const success = await this.action.run(this.picker().pick(row.timeZone))
    if (success && this.opening === sentFrom && this.shown()) {
      this.open.set(false)
    }
  }
}
