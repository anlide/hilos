// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The table, the frame it declares (its
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
  untracked,
} from '@angular/core'
import {
  HilosPages,
  createHilosSettingsActions,
  createHilosSettingsTable,
  hasCustomValue,
  isOrphanSetting,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  HilosSettingRow,
  HilosSettingsContext,
  RowEditBaseline,
  RowEditState,
  RowEditStep,
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

/** The one field the dialog edits: the row's own value, null for the catalog default. */
interface SettingEditFields {
  overrideValue: string | null
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(live: RowEditState<SettingEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return live.fields.overrideValue.incoming === null
        ? 'Reset elsewhere to the catalog default.'
        : `Changed elsewhere to "${live.fields.overrideValue.incoming}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

/** The framework settings admin page: the cataloged table with edit / delete dialogs. */
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
        (openChange)="$event ? editOpen.set(true) : closeEdit()"
        [confirmOnClose]="editDirty()"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="editTitle()"
          [conflict]="live().conflict"
        ></h5>
        <hilos-action-error [action]="edit" />
        @if (editRow(); as row) {
          <form (submit)="submitEdit($event)">
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
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-settings-edit-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="hilos-settings-edit-cancel"
            [disabled]="edit.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <div
            hilosConflictActions
            [conflict]="live().conflict"
            [disableSave]="!editDirty() || edit.busy() || live().gone"
            [mergeable]="false"
            [saveLabel]="editSaveLabel()"
            (save)="submitEdit()"
            (acceptMine)="acceptMine()"
            (acceptTheirs)="acceptTheirs()"
          >
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
        <hilos-action-error [action]="del" />
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

  // Edit dialog: one row's custom value (or a reset back to the catalog default).
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosSettingRow | null>(null)
  protected readonly editBaseline = signal<RowEditBaseline<SettingEditFields>>(
    openRowEdit<SettingEditFields>({ overrideValue: null }),
  )
  protected readonly editValue = signal('')
  protected readonly editUseCustom = signal(false)
  protected readonly edit = createHilosTrackedAction()
  // The live row the open dialog is about: the row the table holds in focus, which
  // the server follows wherever it goes; undefined once the row is gone. Mirrored
  // the way the viewport table mirrors its rows.
  protected readonly liveRow = signal<HilosSettingRow | undefined>(undefined)

  // Delete dialog: orphan keys only (not in the catalog).
  protected readonly deleteOpen = signal(false)
  protected readonly deleteRow = signal<HilosSettingRow | null>(null)
  protected readonly del = createHilosTrackedAction()

  protected readonly editInputType = computed(() =>
    inputType(this.editRow()?.type),
  )
  protected readonly editStep = computed(() => inputStep(this.editRow()?.type))
  // The custom value the dialog would persist, normalized to a string: a number
  // input yields a number, while the row override and the wire are strings, so an
  // un-normalized value would never match the echoed row. Null leaves the default.
  protected readonly editOverride = computed<string | null>(() =>
    this.editUseCustom() ? String(this.editValue()) : null,
  )
  protected readonly live = computed(() => {
    const row = this.liveRow()

    return resolveRowEdit(
      row ? { overrideValue: row.overrideValue } : undefined,
      this.editBaseline(),
      { overrideValue: this.editOverride() },
    )
  })
  protected readonly deleteGone = computed(() => this.liveRow() === undefined)
  protected readonly editDirty = computed(() => this.live().dirty)
  protected readonly editSaveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() => noticeText(this.live()))
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? `Edit · ${row.key}` : 'Edit setting'
  })
  protected readonly deleteTitle = computed(() => {
    const row = this.deleteRow()

    return row ? `Delete · ${row.key}` : 'Delete setting'
  })

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    // Mirror the row in focus the same way the viewport table mirrors its rows:
    // the controller arrives through a computed, so hilosSignal cannot take it at
    // field init.
    effect((onCleanup) => {
      const settings = this.settings()
      settings.start()
      this.liveRow.set(settings.controller.focusedRow.get())
      const unsubscribe = subscribeSignal(
        settings.controller.focusedRow,
        (row) => this.liveRow.set(row),
      )
      onCleanup(() => {
        unsubscribe()
        settings.dispose()
      })
    })
    // The helper hands a step whenever the other side moved a field the person
    // left alone, or both arrived at the same value; the dialog applies it at
    // once.
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

  protected openEdit(row: HilosSettingRow): void {
    // Flush pending and take the row into focus, so the dialog edits the latest
    // committed row and follows it from here; a row removed by someone else (now
    // a placeholder) declines to open.
    const fresh = this.settings().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.edit.clearError()
    this.editRow.set(fresh)
    // An orphan has no catalog default behind it and no switch in the dialog, so its
    // value is always its own; a cataloged key opens with the switch on only when it
    // carries a value of its own.
    this.editUseCustom.set(isOrphanSetting(fresh) || hasCustomValue(fresh))
    this.editValue.set(fresh.overrideValue ?? fresh.value ?? '')
    this.editBaseline.set(openRowEdit({ overrideValue: fresh.overrideValue }))
    this.editOpen.set(true)
  }

  // Put a step of the helper into the dialog: the snapshot moves, and a value
  // the step takes lands in the switch and the text the way the dialog opened
  // with it. A reset taken from the other side leaves the text on the value now
  // in effect — the live row's, not the one the dialog opened on, which is the
  // override the other side just removed.
  private applyStep(step: RowEditStep<SettingEditFields>): void {
    const row = this.editRow()
    if (!row) {
      return
    }
    this.editBaseline.set(step.baseline)
    const taken = step.take.overrideValue
    if (taken !== undefined) {
      this.editUseCustom.set(taken !== null || isOrphanSetting(row))
      this.editValue.set(taken ?? this.liveRow()?.value ?? row.value ?? '')
    }
  }

  protected acceptMine(): void {
    this.editBaseline.set(keepMineRowEdit(this.live(), this.editBaseline()))
  }

  protected acceptTheirs(): void {
    this.applyStep(takeTheirsRowEdit(this.live(), this.editBaseline()))
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure toasts and stays open so the entered value survives.
  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy() || this.live().gone) {
      return
    }
    if (!this.live().dirty) {
      this.closeEdit()

      return
    }
    const next = this.editOverride()
    // The switch turned off means "back to the catalog default", which resets the key
    // by dropping its row. With a value, an orphan updates in place and a cataloged
    // key adds by key (the add is idempotent, so the row need not exist yet).
    let handle
    if (next === null) {
      handle = this.actions().sendSettingReset(row.key)
    } else {
      handle = isOrphanSetting(row)
        ? this.actions().sendSettingUpdate(row.key, next)
        : this.actions().sendSettingAdd(row.key, next)
    }
    if (await this.edit.run(handle)) {
      this.closeEdit()
    }
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

  protected closeEdit(): void {
    this.editOpen.set(false)
    this.settings().controller.releaseFocus()
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

  protected onUseCustom(event: Event): void {
    this.editUseCustom.set((event.target as HTMLInputElement).checked)
  }

  protected onValueCheckbox(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).checked ? '1' : '0')
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
  }
}
