// HilosCommunicationsChannelPage — the framework Hilos channel-config page
// (HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
// the admin shell. The route {channelId} names the channel; the fields table is
// global (one row per field of every channel), so the core headless filters it to
// this channel client-side (createHilosChannelFields). Each editable field shows its
// effective value and source and can be overridden (edit, in a modal) or reset to
// its env/default; a secret is shown as set/not-set and never editable. A "Send test
// notification" button exercises the real delivery path (HIL-201). Writes are tracked
// actions (createHilosCommunicationsActions): the value redraws from the reactive
// table's snapshot signal after the backend echo, never optimistically, and a
// validation failure surfaces as a toast with the backend's domain phrase. Editing
// happens in a modal — inline forms are forbidden (rules-and-violations.md section E).
// Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  ActionError,
  HilosPages,
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosChannelFieldRow,
  HilosCommunicationsContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** Map a field type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

/** The framework channel-config page: one channel's config-fields table. */
@Component({
  selector: 'hilos-communications-channel-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, HilosModal, LoadingButton],
  template: `
    <hilos-admin-page [page]="page">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="mb-0 text-body-secondary">
          Channel <code>{{ channel() }}</code>
        </p>
        <button
          hilosLoadingButton
          class="btn-outline-primary btn-sm"
          [loading]="test.loading()"
          [disabled]="test.busy()"
          data-id="hilos-channel-test"
          (click)="sendTest()"
        >
          Send test notification
        </button>
      </div>

      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <caption class="visually-hidden">
            Channel configuration fields
          </caption>
          <thead>
            <tr>
              <th scope="col">Field</th>
              <th scope="col">Value</th>
              <th scope="col">Source</th>
              <th scope="col" class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            @for (row of rows(); track row.key) {
              <tr [attr.data-id]="'hilos-channel-field-' + row.field">
                <td>
                  <div class="fw-semibold">{{ row.label }}</div>
                  <code class="small text-body-secondary">{{ row.field }}</code>
                </td>
                <td>
                  @if (row.secret) {
                    <span class="text-body-secondary fst-italic">
                      {{ row.valueSource === 'env' ? 'Set in env' : 'Not set' }}
                    </span>
                  } @else {
                    <span>{{ displayValue(row) }}</span>
                  }
                </td>
                <td>
                  <span
                    class="badge text-bg-secondary-subtle text-secondary-emphasis"
                  >
                    {{ SOURCE_LABEL[row.valueSource] ?? row.valueSource }}
                  </span>
                </td>
                <td class="text-end">
                  @if (row.editable) {
                    <div class="d-flex gap-1 justify-content-end">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        title="Edit"
                        aria-label="Edit"
                        [attr.data-id]="'hilos-channel-field-edit-' + row.field"
                        (click)="openEdit(row)"
                      >
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        title="Reset to env/default"
                        aria-label="Reset to env/default"
                        [disabled]="
                          row.valueSource !== 'settings' || reset.busy()
                        "
                        [attr.data-id]="
                          'hilos-channel-field-reset-' + row.field
                        "
                        (click)="resetField(row)"
                      >
                        <i
                          class="bi bi-arrow-counterclockwise"
                          aria-hidden="true"
                        ></i>
                      </button>
                    </div>
                  }
                </td>
              </tr>
            }
            @if (rows().length === 0) {
              <tr>
                <td colspan="4" class="text-center text-muted py-4">
                  No configurable fields for this channel.
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="editOpen.set($event)"
        [title]="editTitle()"
      >
        @if (editRow(); as row) {
          <form (submit)="submitEdit($event)">
            @if (editInputType() === 'checkbox') {
              <div class="form-check form-switch">
                <input
                  id="hilos-channel-edit-value"
                  type="checkbox"
                  class="form-check-input"
                  role="switch"
                  data-id="hilos-channel-edit-value"
                  [checked]="editValue() === '1'"
                  (change)="onValueCheckbox($event)"
                />
                <label class="form-check-label" for="hilos-channel-edit-value">
                  {{ row.label }}
                </label>
              </div>
            } @else {
              <label class="form-label" for="hilos-channel-edit-value">{{
                row.label
              }}</label>
              <input
                id="hilos-channel-edit-value"
                [type]="editInputType()"
                [attr.step]="editStep()"
                class="form-control"
                data-id="hilos-channel-edit-value"
                [value]="editValue()"
                (input)="onValueInput($event)"
              />
            }
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="edit.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="edit.loading()"
            [disabled]="edit.busy()"
            data-id="hilos-channel-edit-save"
            (click)="submitEdit()"
          >
            Save
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsChannelPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosCommunicationsContext>()

  protected readonly page = HilosPages.COMMUNICATIONS_CHANNEL
  protected readonly SOURCE_LABEL = SOURCE_LABEL

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The route channel, as a core signal so the filtered fields re-derive on
  // navigation without a re-fetch of the (shared) global fields table.
  private readonly channelSignal = computedSignal(() => {
    if (!this.router) {
      throw new Error(
        'HilosCommunicationsChannelPage requires a provided router: { provide: HILOS_ROUTER, useValue: router }.',
      )
    }

    return (
      (this.router.currentRoute.get().params['channelId'] as
        | string
        | undefined) ?? ''
    )
  })
  protected readonly channel = hilosSignal(this.channelSignal)

  private readonly fields = computed(() =>
    createHilosChannelFields(this.context(), this.channelSignal),
  )
  private readonly actions = computed(() =>
    createHilosCommunicationsActions(this.context()),
  )

  // The channel's field rows, mirrored from the (per-context) core fields signal
  // into an Angular signal so the template re-renders on every snapshot delta.
  protected readonly rows = signal<readonly HilosChannelFieldRow[]>([])

  protected readonly test = createHilosTrackedAction({
    describeError: this.describeError,
  })
  protected readonly reset = createHilosTrackedAction({
    describeError: this.describeError,
  })
  protected readonly edit = createHilosTrackedAction({
    describeError: this.describeError,
  })

  // Edit dialog: one field's override value.
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosChannelFieldRow | null>(null)
  protected readonly editValue = signal('')
  protected readonly editInputType = computed(() =>
    inputType(this.editRow()?.type),
  )
  protected readonly editStep = computed(() =>
    this.editRow()?.type === 'float' ? 'any' : undefined,
  )
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? `Edit · ${row.label}` : 'Edit field'
  })

  constructor() {
    // Bind the (global) fields table to the connection and request its window once
    // the context input is bound; mirror its rows and unbind on destroy / swap.
    effect((onCleanup) => {
      const fields = this.fields()
      fields.start()
      this.rows.set(fields.rows.get())
      const unsubscribe = subscribeSignal(fields.rows, (next) => {
        this.rows.set(next)
      })
      onCleanup(() => {
        unsubscribe()
        fields.dispose()
      })
    })
  }

  protected sendTest(): void {
    void this.test.run(this.actions().sendChannelTest(this.channel()))
  }

  protected resetField(row: HilosChannelFieldRow): void {
    void this.reset.run(this.actions().sendChannelReset(row.channel, row.field))
  }

  protected openEdit(row: HilosChannelFieldRow): void {
    this.edit.clearError()
    this.editRow.set(row)
    if (row.type === 'boolean') {
      this.editValue.set(row.value === true ? '1' : '0')
    } else {
      this.editValue.set(
        row.value === null || row.value === undefined ? '' : String(row.value),
      )
    }
    this.editOpen.set(true)
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy()) {
      return
    }
    if (
      await this.edit.run(
        this.actions().sendChannelSet(
          row.channel,
          row.field,
          this.editedValue(row),
        ),
      )
    ) {
      this.editOpen.set(false)
    }
  }

  protected onValueCheckbox(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).checked ? '1' : '0')
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
  }

  /** Human-readable effective value of a non-secret field. */
  protected displayValue(row: HilosChannelFieldRow): string {
    if (typeof row.value === 'boolean') {
      return row.value ? 'On' : 'Off'
    }

    return row.value === null || row.value === '' ? '—' : String(row.value)
  }

  /** Coerce the edited string to the field's typed value for the set action. */
  private editedValue(row: HilosChannelFieldRow): boolean | number | string {
    if (row.type === 'boolean') {
      return this.editValue() === '1'
    }
    if (row.type === 'integer' || row.type === 'float') {
      return Number(this.editValue())
    }

    return this.editValue()
  }

  // Surface the backend's domain phrase on a rejected write (invalid port, no
  // address for the channel, …); keep generic phrasing for timeout / disconnect.
  private describeError(error: unknown): string {
    return error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : 'The action could not be completed. Please try again.'
  }
}
