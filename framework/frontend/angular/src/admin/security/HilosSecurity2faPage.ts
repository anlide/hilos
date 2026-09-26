// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
// each — who must use it, the days a device is trusted, the size of a set of
// backup codes, and the removal wait with its bounds. Each row shows its value in
// words and a pencil; the pencil opens a modal (the modal-only editing rule), and
// a value a setting's rule refuses stays in the modal with the refusal above it.
// The table, the row view-model, the edit round-trip and the words are the core
// headless's; this view owns only the markup, so a project mounts it by passing
// its HilosTwoFactorContext. The screen is built from text: the mockup's node is
// a debt (D-115). Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
} from '@hilos/core'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
  HilosStepUpOperationRow,
} from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework two-step verification page: six settings, each edited in a modal. */
@Component({
  selector: 'hilos-security2fa-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosModal,
    HilosSwitch,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="settings().controller">
        <ng-template hilosTableCell="rowKey" let-row>
          <div class="fw-semibold">{{ labelOf(row) }}</div>
          <div class="small text-body-secondary">{{ hintOf(row.rowKey) }}</div>
        </ng-template>
        <ng-template hilosTableCell="value" let-row>
          <span [attr.data-id]="'hilos-2fa-value-' + row.rowKey">{{
            describe(row.rowKey, row.value)
          }}</span>
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            title="Edit"
            [attr.aria-label]="'Edit ' + labelOf(row)"
            [attr.data-id]="'hilos-2fa-edit-' + row.rowKey"
            (click)="openEdit(row)"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
        </ng-template>
      </hilos-viewport-table>

      <hilos-viewport-table
        class="mt-4"
        [controller]="operations().controller"
        [dataId]="'hilos-step-up-table'"
      >
        <ng-template hilosTableCell="operationKey" let-row>
          <span
            class="fw-semibold"
            [attr.data-id]="'hilos-step-up-row-' + row.operationKey"
            >{{ row.label }}</span
          >
        </ng-template>
        <ng-template hilosTableCell="owner" let-row>
          <span class="badge text-bg-light border">
            {{
              row.owner === 'framework'
                ? stepUpCopy.framework
                : stepUpCopy.project
            }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="enabled" let-row>
          <hilos-switch
            class="mb-0"
            [checked]="row.enabled"
            [busy]="pendingOperationKey() === row.operationKey"
            [disabled]="operationToggle.busy()"
            [aria-label]="'Require confirmation for ' + row.label"
            [dataId]="'hilos-step-up-switch-' + row.operationKey"
            (toggle)="toggleOperation(row, $event)"
          />
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="editOpen.set($event)"
        [title]="editTitle()"
        (cancel)="closeEdit()"
      >
        <hilos-action-error [action]="edit" detailsTitle="Couldn't save" />
        @if (editRow(); as row) {
          <form (submit)="submitEdit($event)">
            <label class="form-label" for="hilos-2fa-input">
              {{ labelOf(row) }}
            </label>
            @if (row.rowKey === requiredKey) {
              <select
                id="hilos-2fa-input"
                class="form-select"
                data-id="hilos-2fa-input"
                data-autofocus
                [value]="editValue()"
                (change)="onValueInput($event)"
              >
                @for (value of requiredValues; track value) {
                  <option [value]="value" [selected]="value === editValue()">
                    {{ requiredCopy[value] }}
                  </option>
                }
              </select>
            } @else {
              <input
                id="hilos-2fa-input"
                type="number"
                inputmode="numeric"
                class="form-control"
                data-id="hilos-2fa-input"
                data-autofocus
                [value]="editValue()"
                (input)="onValueInput($event)"
              />
            }
            <p class="form-text mb-0">
              {{ hintOf(row.rowKey) }} Default:
              {{ describe(row.rowKey, row.defaultValue) }}.
            </p>
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
            data-id="hilos-2fa-save"
            (click)="submitEdit()"
          >
            Save
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSecurity2faPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosTwoFactorContext>()

  protected readonly page = HilosPages.SECURITY_2FA
  protected readonly requiredKey = HilosSecondFactorSettingKey.required
  protected readonly requiredValues = HILOS_SECOND_FACTOR_REQUIRED_VALUES
  protected readonly requiredCopy = HILOS_SECOND_FACTOR_REQUIRED_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_ADMIN_COPY

  protected readonly settings = computed(() =>
    createHilosSecurityTwoFactorTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecurityTwoFactorActions(this.context()),
  )
  protected readonly operations = computed(() =>
    createHilosSecurityStepUpTable(this.context()),
  )
  private readonly operationActions = computed(() =>
    createHilosSecurityStepUpActions(this.context()),
  )
  protected readonly operationToggle = createHilosTrackedAction()
  protected readonly pendingOperationKey = signal<string | null>(null)

  // The edit modal: one setting at a time, its value as typed until Save.
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosTwoFactorSettingRow | null>(null)
  protected readonly editValue = signal('')
  protected readonly edit = createHilosTrackedAction()
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? this.labelOf(row) : 'Edit setting'
  })

  constructor() {
    // Bind the table to the connection once the context input is bound; unbind
    // on destroy / swap.
    effect((onCleanup) => {
      const settings = this.settings()
      const operations = this.operations()
      settings.start()
      operations.start()
      onCleanup(() => {
        settings.dispose()
        operations.dispose()
      })
    })
  }

  /**
   * The name of a setting on the screen.
   *
   * @param row The row of the setting.
   */
  protected labelOf(row: HilosTwoFactorSettingRow): string {
    return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
  }

  /**
   * What a setting's value means and where it may go.
   *
   * @param settingKey The setting.
   */
  protected hintOf(settingKey: string): string {
    return HILOS_SECOND_FACTOR_SETTING_COPY[settingKey]?.hint ?? ''
  }

  /**
   * A setting's value in words.
   *
   * @param settingKey The setting the value belongs to.
   * @param value The value as text.
   */
  protected describe(settingKey: string, value: string): string {
    return describeHilosSecondFactorSetting(settingKey, value)
  }

  protected openEdit(row: HilosTwoFactorSettingRow): void {
    this.edit.clearError()
    this.editRow.set(row)
    this.editValue.set(row.value)
    this.editOpen.set(true)
  }

  protected closeEdit(): void {
    this.editOpen.set(false)
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy()) {
      return
    }
    if (
      await this.edit.run(
        this.actions().sendSettingSet(row.rowKey, this.editValue().trim()),
      )
    ) {
      this.closeEdit()
    }
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
  }

  protected async toggleOperation(
    row: HilosStepUpOperationRow,
    enabled: boolean,
  ): Promise<void> {
    this.pendingOperationKey.set(row.operationKey)
    try {
      await this.operationToggle.run(
        this.operationActions().sendOperationSet(row.operationKey, enabled),
      )
    } finally {
      this.pendingOperationKey.set(null)
    }
  }
}
