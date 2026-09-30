// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the six second-factor settings, one row
// each — who must use it, the days a device is trusted, the size of a set of
// backup codes, and the removal wait with its bounds. Each row shows its value in
// words and a pencil; the pencil opens a modal (the modal-only editing rule), and
// a value a setting's rule refuses stays in the modal with the refusal above it.
// The table, the row view-model, the edit round-trip and the words are the core
// headless's; this view owns only the markup, so a project mounts it by passing
// its HilosTwoFactorContext. The modal holds its row in focus and merges against
// it through the shared row-edit helper (rowEdit.ts, conflict-resolution.md),
// saying what happened elsewhere on one line of room held in advance
// (HilosEditNotice). The screen is built from text: the mockup's node is a debt
// (D-115). Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
  untracked,
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
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
  HilosStepUpOperationRow,
  RowEditBaseline,
  RowEditNoticeKind,
  RowEditStep,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The one field the edit modal edits. */
interface SettingEditFields {
  value: string
}

/**
 * The one line the modal says about the other side, for what the helper found;
 * a value in words, the way its cell says it.
 */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosTwoFactorSettingRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow
        ? `Changed elsewhere to "${describeHilosSecondFactorSetting(liveRow.rowKey, liveRow.value)}".`
        : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/** The framework two-step verification page: six settings, each edited in a modal. */
@Component({
  selector: 'hilos-security2fa-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosModal,
    HilosSwitch,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
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
        (openChange)="$event ? editOpen.set(true) : closeEdit()"
        [confirmOnClose]="live().dirty"
        [ariaLabel]="editTitle()"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="editTitle()"
          [conflict]="live().conflict"
        ></h5>
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
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-2fa-edit-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!live().dirty || edit.busy() || live().gone"
            [saveLabel]="editSaveLabel()"
            (save)="submitEdit()"
            (acceptMine)="acceptMine()"
            (acceptTheirs)="acceptTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="edit.busy()"
                (click)="requestClose()"
              >
                Cancel
              </button>
            </ng-template>
            <ng-template
              #saveButton
              let-disabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="edit.loading()"
                [disabled]="disabled"
                data-id="hilos-2fa-save"
                (click)="onSave()"
              >
                {{ editSaveLabel() }}
              </button>
            </ng-template>
          </div>
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
  protected readonly editBaseline = signal<RowEditBaseline<SettingEditFields>>(
    openRowEdit<SettingEditFields>({ value: '' }),
  )
  // The live row the open modal is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone. Mirrored
  // from the controller: the handle arrives through a computed, so hilosSignal
  // cannot take it at field init.
  protected readonly liveRow = signal<HilosTwoFactorSettingRow | undefined>(
    undefined,
  )
  protected readonly live = computed(() => {
    const row = this.liveRow()

    return resolveRowEdit(
      row ? { value: row.value } : undefined,
      this.editBaseline(),
      { value: this.editValue().trim() },
    )
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() =>
    noticeText(this.editNotice(), this.liveRow()),
  )
  protected readonly editSaveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )

  constructor() {
    // Bind the table to the connection once the context input is bound, and
    // mirror the row in focus; unbind on destroy / swap.
    effect((onCleanup) => {
      const settings = this.settings()
      const operations = this.operations()
      settings.start()
      operations.start()
      this.liveRow.set(settings.controller.focusedRow.get())
      const unsubscribe = subscribeSignal(
        settings.controller.focusedRow,
        (row) => this.liveRow.set(row),
      )
      onCleanup(() => {
        unsubscribe()
        settings.dispose()
        operations.dispose()
      })
    })
    // The helper hands a step whenever the other side moved the value while the
    // person left it alone, or both arrived at the same one; the modal applies
    // it at once.
    effect(() => {
      const settle = this.live().settle
      const open = this.editOpen()
      untracked(() => {
        if (open && settle) {
          this.applyStep(settle)
        }
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
    // Flush pending and take the row into focus, so the modal edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    const fresh = this.settings().controller.focusRow(row.rowKey)
    if (!fresh) {
      return
    }
    this.edit.clearError()
    this.editRow.set(fresh)
    this.editValue.set(fresh.value)
    this.editBaseline.set(
      openRowEdit<SettingEditFields>({ value: fresh.value }),
    )
    this.editOpen.set(true)
  }

  protected closeEdit(): void {
    this.editOpen.set(false)
    this.settings().controller.releaseFocus()
  }

  // Put a step of the helper into the modal: the snapshot moves, and a value the
  // step takes lands in the input.
  private applyStep(step: RowEditStep<SettingEditFields>): void {
    this.editBaseline.set(step.baseline)
    if (step.take.value !== undefined) {
      this.editValue.set(step.take.value)
    }
  }

  protected acceptMine(): void {
    this.editBaseline.set(keepMineRowEdit(this.live(), this.editBaseline()))
  }

  protected acceptTheirs(): void {
    this.applyStep(takeTheirsRowEdit(this.live(), this.editBaseline()))
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy() || this.live().gone || this.live().conflict) {
      return
    }
    if (!this.live().dirty) {
      this.closeEdit()

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
