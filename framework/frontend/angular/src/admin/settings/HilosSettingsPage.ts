// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The ↺ beside the pencil resets
// through a confirm dialog built like the orphan delete — never in one click. The table, the frame it declares (its
// columns, search, and empty words), the row view-model, and the add/update/delete
// round-trips are the core headless's (createHilosSettingsTable /
// createHilosSettingsActions); this view owns only the markup, so a project mounts
// it by passing its HilosSettingsContext and declares the catalog on its backend.
// The edit dialog merges against the live row through the shared row-edit helper
// (rowEdit.ts, conflict-resolution.md) and says what happened elsewhere on one
// line of room held in advance (HilosEditNotice).
// Authoritative-backend: a submit dispatches a tracked action and the dialog
// closes on its `::success` reply (createHilosTrackedAction, step 7.4); a failure
// surfaces as a toast and leaves the dialog open with the entered value
// (toasts.md). Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HilosPages,
  createHilosSettingsActions,
  createHilosSettingEdit,
  hilosRowEditIdle,
  createHilosSettingsTable,
  hasCustomValue,
  isOrphanSetting,
} from '@hilos/core'
import type {
  HilosSettingEditFields,
  HilosSettingEditForm,
  HilosSettingRow,
  HilosSettingsContext,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'
import { HilosSettingValueCell } from './HilosSettingValueCell.js'

/** Map a setting type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

function inputStep(type: string | undefined): 'any' | undefined {
  return type === 'float' ? 'any' : undefined
}

/** The framework settings admin page: the cataloged table with edit / reset / delete dialogs. */
@Component({
  selector: 'hilos-settings-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosTableCell,
    HilosViewportTable,
    HilosModal,
    HilosActionError,
    HilosEditNotice,
    LoadingButton,
    HilosSettingValueCell,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="settings().controller">
        <ng-template hilosTableCell="key" let-row>
          <code class="text-break">{{ row.key }}</code>
        </ng-template>
        <ng-template hilosTableCell="value" let-row>
          <div style="max-width: 18rem">
            <hilos-setting-value-cell
              [value]="row.value"
              [type]="row.type"
              [valueSource]="row.valueSource"
              [defaultReferenceKey]="row.defaultReferenceKey"
            />
          </div>
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            [title]="
              hasCustom(row) || isOrphan(row) ? 'Edit' : 'Set custom value'
            "
            [attr.aria-label]="
              hasCustom(row) || isOrphan(row) ? 'Edit' : 'Set custom value'
            "
            [attr.data-id]="'hilos-settings-edit-' + row.key"
            (click)="openEdit(row)"
          >
            <i
              [class]="
                hasCustom(row) || isOrphan(row)
                  ? 'bi bi-pencil'
                  : 'bi bi-plus-lg'
              "
              aria-hidden="true"
            ></i>
          </button>
          @if (!isOrphan(row)) {
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              title="Reset to default"
              aria-label="Reset to default"
              [disabled]="!hasCustom(row)"
              [attr.data-id]="'hilos-settings-reset-' + row.key"
              (click)="openReset(row)"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          }
          @if (isOrphan(row)) {
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              title="Delete orphan setting"
              aria-label="Delete orphan setting"
              [attr.data-id]="'hilos-settings-delete-' + row.key"
              (click)="openDelete(row)"
            >
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          }
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        [confirmOnClose]="editDirty()"
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
              <div class="mb-3">
                <span class="form-label d-block">{{ row.key }}</span>
                <hilos-setting-value-cell
                  [value]="row.value"
                  [type]="row.type"
                  [valueSource]="row.valueSource"
                  [defaultReferenceKey]="row.defaultReferenceKey"
                />
              </div>
            } @else {
              @if (!isOrphan(row)) {
                <div class="mb-3">
                  <span class="form-label d-block">Catalog default</span>
                  <hilos-setting-value-cell
                    [value]="row.defaultValue"
                    [type]="row.type"
                    [valueSource]="row.valueSource"
                    [defaultReferenceKey]="row.defaultReferenceKey"
                  />
                </div>
                <div class="form-check form-switch mb-3">
                  <input
                    id="hilos-settings-edit-custom"
                    type="checkbox"
                    class="form-check-input"
                    data-id="hilos-settings-edit-custom"
                    [checked]="editUseCustom()"
                    (change)="onUseCustom($event)"
                  />
                  <label
                    class="form-check-label"
                    for="hilos-settings-edit-custom"
                  >
                    Custom value
                  </label>
                </div>
              }
              @if (editUseCustom()) {
                <div class="mb-0">
                  @if (editInputType() === 'checkbox') {
                    <div class="form-check">
                      <input
                        id="hilos-settings-edit-value"
                        type="checkbox"
                        class="form-check-input"
                        data-id="hilos-settings-edit-value"
                        data-autofocus
                        [checked]="editValue() === '1'"
                        (change)="onValueCheckbox($event)"
                      />
                      <label
                        class="form-check-label"
                        for="hilos-settings-edit-value"
                      >
                        Enabled
                      </label>
                    </div>
                  } @else {
                    <label class="form-label" for="hilos-settings-edit-value">{{
                      row.key
                    }}</label>
                    <input
                      id="hilos-settings-edit-value"
                      [type]="editInputType()"
                      [attr.step]="editStep()"
                      class="form-control"
                      data-id="hilos-settings-edit-value"
                      data-autofocus
                      [value]="editValue()"
                      (input)="onValueInput($event)"
                    />
                  }
                </div>
              }
            }
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-settings-edit-notice"
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
                data-id="hilos-settings-edit-cancel"
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
                data-id="hilos-settings-edit-save"
                (click)="onSave()"
              >
                {{ editSaveLabel() }}
              </button>
            </ng-template>
          </div>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="deleteOpen()"
        (openChange)="$event ? deleteOpen.set(true) : closeDelete()"
        [title]="deleteTitle()"
        [closeOnBackdrop]="!del.busy()"
        [closeOnEsc]="!del.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="del"
          detailsTitle="Couldn't delete the setting"
        />
        <p class="mb-0 text-body-secondary">
          This removes the orphan row from the database. Orphan keys are not in
          the catalog.
        </p>
        @if (deleteRow(); as row) {
          <p class="mb-0 mt-2">
            <code class="text-break">{{ row.key }}</code>
          </p>
        }
        @if (deleteGone()) {
          <p
            class="mb-0 mt-2 text-body-secondary"
            data-id="hilos-settings-delete-gone"
          >
            This setting was already deleted elsewhere.
          </p>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="del.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="del.loading()"
            [disabled]="del.busy() || deleteGone()"
            data-id="hilos-settings-delete-confirm"
            (click)="submitDelete()"
          >
            Delete
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="resetOpen()"
        (openChange)="$event ? resetOpen.set(true) : closeReset()"
        [title]="resetTitle()"
        [closeOnBackdrop]="!reset.busy()"
        [closeOnEsc]="!reset.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error
          [action]="reset"
          detailsTitle="Couldn't reset the setting"
        />
        @if (resetShown(); as row) {
          <dl class="row mb-0">
            <dt class="col-4">Now</dt>
            <dd class="col-8" data-id="hilos-settings-reset-now">
              <hilos-setting-value-cell
                [value]="row.value"
                [type]="row.type"
                [valueSource]="row.valueSource"
                [defaultReferenceKey]="row.defaultReferenceKey"
              />
            </dd>
            <dt class="col-4">Back to</dt>
            <dd class="col-8" data-id="hilos-settings-reset-default">
              <hilos-setting-value-cell
                [value]="row.defaultValue"
                [type]="row.type"
                [valueSource]="
                  row.defaultReferenceKey !== null ? 'reference' : 'default'
                "
                [defaultReferenceKey]="row.defaultReferenceKey"
              />
            </dd>
          </dl>
        }
        @if (resetGone()) {
          <p
            class="mb-0 mt-2 text-body-secondary"
            data-id="hilos-settings-reset-gone"
          >
            Already reset elsewhere.
          </p>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="reset.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="reset.loading()"
            [disabled]="reset.busy() || resetGone()"
            data-id="hilos-settings-reset-confirm"
            (click)="submitReset()"
          >
            Reset
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSettingsPage {
  /** The project context: scope stores and the action lifecycle. */
  readonly context = input.required<HilosSettingsContext>()

  protected readonly page = HilosPages.SETTINGS
  protected readonly hasCustom = hasCustomValue
  protected readonly isOrphan = isOrphanSetting

  protected readonly settings = computed(() =>
    createHilosSettingsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSettingsActions(this.context()),
  )

  // Edit dialog: one row's custom value (or a reset back to the catalog
  // default), as the core window has it; this view binds the switch and the text
  // through mirrors of the session's signals.
  protected readonly editor = computed(() =>
    createHilosSettingEdit(this.settings().controller, this.actions()),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosSettingRow | null>(null)
  protected readonly editForm = signal<HilosSettingEditForm>({
    hidden: false,
    useCustom: false,
    text: '',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosSettingEditFields>({ overrideValue: null }),
  )
  protected readonly editNoticeText = signal('')
  protected readonly editSaveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly edit = createHilosTrackedAction()
  // The live row the open delete or reset dialog is about: the row the table
  // holds in focus, which the server follows wherever it goes; undefined once the
  // row is gone. Mirrored the way the viewport table mirrors its rows.
  protected readonly liveRow = signal<HilosSettingRow | undefined>(undefined)

  // Delete dialog: orphan keys only (not in the catalog).
  protected readonly deleteOpen = signal(false)
  protected readonly deleteRow = signal<HilosSettingRow | null>(null)
  protected readonly del = createHilosTrackedAction()

  // Reset dialog: back to the catalog default, only on confirm. It reads the live
  // row it holds in focus, so what it shows follows the other tabs.
  protected readonly resetOpen = signal(false)
  protected readonly resetRow = signal<HilosSettingRow | null>(null)
  protected readonly reset = createHilosTrackedAction()

  protected readonly editInputType = computed(() =>
    inputType(this.editRow()?.type),
  )
  protected readonly editStep = computed(() => inputStep(this.editRow()?.type))
  protected readonly editHidden = computed(() => this.editForm().hidden)
  protected readonly editUseCustom = computed(() => this.editForm().useCustom)
  protected readonly editValue = computed(() => this.editForm().text)
  protected readonly deleteGone = computed(() => this.liveRow() === undefined)
  protected readonly resetShown = computed(
    () => this.liveRow() ?? this.resetRow(),
  )
  protected readonly resetGone = computed(() => {
    const row = this.liveRow()

    return row === undefined || !hasCustomValue(row)
  })
  protected readonly editDirty = computed(() => this.live().dirty)
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? `Edit · ${row.key}` : 'Edit setting'
  })
  protected readonly deleteTitle = computed(() => {
    const row = this.deleteRow()

    return row ? `Delete · ${row.key}` : 'Delete setting'
  })
  protected readonly resetTitle = computed(() => {
    const row = this.resetRow()

    return row ? `Reset · ${row.key}` : 'Reset setting'
  })

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    // Mirror the row in focus the same way the viewport table mirrors its rows:
    // the controller arrives through a computed, so hilosSignal cannot take it at
    // field init.
    effect((onCleanup) => {
      const settings = this.settings()
      const editor = this.editor()
      settings.start()
      editor.start()
      const off = [
        mirrorHilosSignal(settings.controller.focusedRow, this.liveRow),
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

  protected openEdit(row: HilosSettingRow): void {
    // The window takes the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row that is gone declines to open.
    this.edit.clearError()
    this.editor().open(row.key)
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

  protected openDelete(row: HilosSettingRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a delete.
    const fresh = this.settings().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.del.clearError()
    this.deleteRow.set(fresh)
    this.deleteOpen.set(true)
  }

  protected closeDelete(): void {
    this.deleteOpen.set(false)
    this.settings().controller.releaseFocus()
  }

  protected async submitDelete(): Promise<void> {
    const row = this.deleteRow()
    if (!row || this.del.busy() || this.deleteGone()) {
      return
    }
    if (await this.del.run(this.actions().sendSettingDelete(row.key))) {
      this.closeDelete()
    }
  }

  protected openReset(row: HilosSettingRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a reset.
    const fresh = this.settings().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.reset.clearError()
    this.resetRow.set(fresh)
    this.resetOpen.set(true)
  }

  protected closeReset(): void {
    this.resetOpen.set(false)
    this.settings().controller.releaseFocus()
  }

  protected async submitReset(): Promise<void> {
    const row = this.resetRow()
    if (!row || this.reset.busy() || this.resetGone()) {
      return
    }
    if (await this.reset.run(this.actions().sendSettingReset(row.key))) {
      this.closeReset()
    }
  }

  protected onUseCustom(event: Event): void {
    this.editor().patchForm({
      useCustom: (event.target as HTMLInputElement).checked,
    })
  }

  protected onValueCheckbox(event: Event): void {
    this.editor().patchForm({
      text: (event.target as HTMLInputElement).checked ? '1' : '0',
    })
  }

  protected onValueInput(event: Event): void {
    this.editor().patchForm({ text: (event.target as HTMLInputElement).value })
  }
}
