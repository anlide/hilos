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
  resolveSettingEdit,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosSettingRow,
  HilosSettingsContext,
  TableViewportRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
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

/** The framework settings admin page: the cataloged table with edit / delete dialogs. */
@Component({
  selector: 'hilos-settings-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosViewportTable,
    HilosModal,
    HilosActionError,
    LoadingButton,
    HilosSettingValueCell,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="settings().controller">
        <ng-template #row let-row>
          <td>
            <code>{{ row.key }}</code>
          </td>
          <td style="max-width: 18rem">
            <hilos-setting-value-cell
              [value]="row.value"
              [type]="row.type"
              [valueSource]="row.valueSource"
              [defaultReferenceKey]="row.defaultReferenceKey"
            />
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
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
            </div>
          </td>
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="editOpen.set($event)"
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
            @if (live().conflict) {
              <div
                class="alert alert-warning mt-2 mb-0"
                data-id="hilos-settings-edit-conflict"
              >
                {{ editConflictNote() }}
              </div>
            }
            @if (live().gone) {
              <div
                class="alert alert-warning mt-2 mb-0"
                data-id="hilos-settings-edit-gone"
              >
                This setting was deleted elsewhere. Your text stays here to copy
                - it can no longer be saved.
              </div>
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
        (openChange)="deleteOpen.set($event)"
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
            <code>{{ row.key }}</code>
          </p>
        }
        @if (deleteLive().gone) {
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
            [disabled]="del.busy() || deleteLive().gone"
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
  protected readonly editBaseline = signal<string | null>(null)
  protected readonly editValue = signal('')
  protected readonly editUseCustom = signal(false)
  protected readonly edit = createHilosTrackedAction()
  protected readonly viewportRows = signal<
    readonly TableViewportRow<HilosSettingRow>[]
  >([])

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
  protected readonly live = computed(() =>
    resolveSettingEdit(
      this.viewportRows(),
      this.editRow()?.key ?? '',
      this.editBaseline(),
      this.editOverride(),
    ),
  )
  protected readonly deleteLive = computed(() =>
    resolveSettingEdit(
      this.viewportRows(),
      this.deleteRow()?.key ?? '',
      null,
      null,
    ),
  )
  protected readonly editDirty = computed(() => this.live().dirty)
  protected readonly editSaveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )
  protected readonly editConflictNote = computed(() => {
    if (this.live().incoming === null) {
      return (
        'The custom value was removed elsewhere and the key is back on ' +
        'its catalog default. Choose how to resolve.'
      )
    }

    return (
      `The value changed elsewhere to "${this.live().incoming}". ` +
      'Choose how to resolve.'
    )
  })
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
    // Mirror the live rows the same way the viewport table does: the controller
    // arrives through a computed, so hilosSignal cannot take it at field init.
    effect((onCleanup) => {
      const settings = this.settings()
      settings.start()
      this.viewportRows.set(settings.controller.rows.get())
      const unsubscribe = subscribeSignal(settings.controller.rows, (rows) =>
        this.viewportRows.set(rows),
      )
      onCleanup(() => {
        unsubscribe()
        settings.dispose()
      })
    })
    effect(() => {
      const status = this.live().status
      const open = this.editOpen()
      untracked(() => {
        if (open && (status === 'incoming' || status === 'converged')) {
          this.rechargeFromIncoming()
        }
      })
    })
  }

  protected openEdit(row: HilosSettingRow): void {
    // Flush pending so the dialog edits the latest committed row; a row removed
    // by someone else (now a placeholder) declines to open.
    const fresh = this.settings().controller.applyAndResolve(row.key)
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
    this.editBaseline.set(fresh.overrideValue)
    this.editOpen.set(true)
  }

  private rechargeFromIncoming(): void {
    const row = this.editRow()
    if (!row) {
      return
    }
    const incoming = this.live().incoming
    this.editUseCustom.set(incoming !== null || isOrphanSetting(row))
    this.editValue.set(incoming ?? row.value ?? '')
    this.editBaseline.set(incoming)
  }

  protected acceptMine(): void {
    this.editBaseline.set(this.live().incoming)
  }

  protected acceptTheirs(): void {
    this.rechargeFromIncoming()
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure toasts and stays open so the entered value survives.
  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy() || this.live().gone) {
      return
    }
    const next = this.editOverride()
    if (next === this.live().incoming) {
      this.editOpen.set(false)

      return
    }
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
      this.editOpen.set(false)
    }
  }

  protected openDelete(row: HilosSettingRow): void {
    // Flush pending; a row already removed by someone else does not open a delete.
    const fresh = this.settings().controller.applyAndResolve(row.key)
    if (!fresh) {
      return
    }
    this.del.clearError()
    this.deleteRow.set(fresh)
    this.deleteOpen.set(true)
  }

  protected async submitDelete(): Promise<void> {
    const row = this.deleteRow()
    if (!row || this.del.busy() || this.deleteLive().gone) {
      return
    }
    if (await this.del.run(this.actions().sendSettingDelete(row.key))) {
      this.deleteOpen.set(false)
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
