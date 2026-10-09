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
} from '@angular/core'
import {
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HilosPages,
  HilosSecondFactorSettingKey,
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  describeHilosSecondFactorSetting,
  createHilosTwoFactorSettingEdit,
  hilosRowEditIdle,
} from '@hilos/core'
import type {
  HilosTwoFactorSettingEditFields,
  HilosTwoFactorSettingEditForm,
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework two-step verification page: six settings, each edited in a modal. */
@Component({
  selector: 'hilos-security2fa-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    HilosModal,
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
          <span [attr.data-id]="'hilos-2fa-value-' + row.rowKey"
            ><hilos-hideable [value]="row.value"
              ><ng-template let-value>{{
                describe(row.rowKey, value)
              }}</ng-template></hilos-hideable
            ></span
          >
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

      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        [confirmOnClose]="live().dirty"
        [aria-label]="editTitle()"
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
            @if (editHidden()) {
              <div class="form-label">{{ labelOf(row) }}</div>
              <hilos-hidden-mark />
            } @else {
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
            }
            <p class="form-text mb-0">
              {{ hintOf(row.rowKey) }} Default:
              <hilos-hideable [value]="row.defaultValue"
                ><ng-template let-value>{{
                  describe(row.rowKey, value)
                }}</ng-template></hilos-hideable
              >.
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
            [disableSave]="!canSave()"
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

  protected readonly settings = computed(() =>
    createHilosSecurityTwoFactorTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecurityTwoFactorActions(this.context()),
  )

  // The edit modal: one setting at a time, as the core window has it; this view
  // binds the list or the number input through mirrors of the session's signals.
  protected readonly editor = computed(() =>
    createHilosTwoFactorSettingEdit(this.settings().controller, this.actions()),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosTwoFactorSettingRow | null>(null)
  protected readonly editForm = signal<HilosTwoFactorSettingEditForm>({
    hidden: false,
    text: '',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosTwoFactorSettingEditFields>({ value: '' }),
  )
  protected readonly editNoticeText = signal('')
  protected readonly editSaveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly edit = createHilosTrackedAction()
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? this.labelOf(row) : 'Edit setting'
  })
  protected readonly editHidden = computed(() => this.editForm().hidden)
  protected readonly editValue = computed(() => this.editForm().text)
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )

  constructor() {
    // Bind the table to the connection once the context input is bound, and
    // mirror the row in focus; unbind on destroy / swap.
    effect((onCleanup) => {
      const settings = this.settings()
      const editor = this.editor()
      settings.start()
      editor.start()
      const off = [
        mirrorHilosSignal(editor.opened, this.editOpen),
        mirrorHilosSignal(editor.row, this.editRow),
        mirrorHilosSignal(editor.form, this.editForm),
        mirrorHilosSignal(editor.state, this.live),
        mirrorHilosSignal(editor.noticeText, this.editNoticeText),
        mirrorHilosSignal(editor.saveLabel, this.editSaveLabel),
        mirrorHilosSignal(editor.canSave, this.canSave),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        editor.dispose()
        settings.dispose()
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
   * A setting's value in words (a hidden one is drawn as the mark instead).
   *
   * @param settingKey The setting the value belongs to.
   * @param value The value as text.
   */
  protected describe(settingKey: string, value: string): string {
    return describeHilosSecondFactorSetting(settingKey, value)
  }

  protected openEdit(row: HilosTwoFactorSettingRow): void {
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    this.edit.clearError()
    this.editor().open(row.rowKey)
  }

  protected closeEdit(): void {
    this.editor().close()
  }

  protected acceptMine(): void {
    this.editor().keepMine()
  }

  protected acceptTheirs(): void {
    this.editor().takeTheirs()
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its
  // `::success` reply; a failure stays open with the entered value.
  protected submitEdit(event?: Event): void {
    event?.preventDefault()
    void this.editor().save(this.edit.run)
  }

  protected onValueInput(event: Event): void {
    this.editor().patchForm({ text: (event.target as HTMLInputElement).value })
  }
}
