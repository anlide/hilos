// HilosSettingsPage — the framework Hilos settings page (HilosPages.SETTINGS):
// the cataloged settings table inside the admin shell. Every row is a catalog key
// merged with its persisted override, so the key set is fixed — there is no free
// "add a setting" (data-model.md, "Cataloged tables"). A row's own actions are the
// only mutations: set a custom value on an on-default key (add-by-key), edit or
// reset an override, or delete an orphan. The table, the row view-model, and the
// add/update/delete round-trips are the core headless's (createHilosSettingsTable /
// createHilosSettingsActions); this view owns only the markup, so a project mounts
// it by passing its HilosSettingsContext and declares the catalog on its backend.
// Authoritative-backend: a submit dispatches a tracked action and the dialog
// closes on its `::success` reply (createHilosTrackedAction, step 7.4); a failure
// surfaces in the dialog. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
  signal,
} from '@angular/core'
import {
  HilosPages,
  createHilosSettingsActions,
  createHilosSettingsTable,
  isOrphanSetting,
  isPersistedSetting,
} from '@hilos/core'
import type {
  HilosSettingRow,
  HilosSettingsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTable } from '../../HilosTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'
import { HilosSettingValueCell } from './HilosSettingValueCell.js'

const COLUMNS: HilosTableColumn[] = [
  { key: 'key', label: 'Key', sortable: true },
  { key: 'value', label: 'Value', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

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
    HilosTable,
    HilosModal,
    LoadingButton,
    HilosSettingValueCell,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-table
        [controller]="settingsTable()"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search settings…"
        emptyText="No settings yet."
      >
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
                [title]="isPersisted(row) ? 'Edit' : 'Set custom value'"
                [attr.aria-label]="
                  isPersisted(row) ? 'Edit' : 'Set custom value'
                "
                [attr.data-id]="'hilos-settings-edit-' + row.key"
                (click)="openEdit(row)"
              >
                <i
                  [class]="isPersisted(row) ? 'bi bi-pencil' : 'bi bi-plus-lg'"
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
      </hilos-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="editOpen.set($event)"
        [title]="editTitle()"
        [confirmOnClose]="editDirty()"
      >
        @if (edit.error()) {
          <div
            class="alert alert-danger"
            role="alert"
            data-id="hilos-settings-error"
          >
            {{ edit.error() }}
          </div>
        }
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
                    [value]="editValue()"
                    (input)="onValueInput($event)"
                  />
                }
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
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="edit.loading()"
            [disabled]="!editDirty() || edit.busy()"
            data-id="hilos-settings-edit-save"
            (click)="submitEdit()"
          >
            Save
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="deleteOpen()"
        (openChange)="deleteOpen.set($event)"
        [title]="deleteTitle()"
        [closeOnBackdrop]="!del.busy()"
        [closeOnEsc]="!del.busy()"
      >
        @if (del.error()) {
          <div
            class="alert alert-danger"
            role="alert"
            data-id="hilos-settings-delete-error"
          >
            {{ del.error() }}
          </div>
        }
        <p class="mb-0 text-body-secondary">
          This removes the orphan row from the database. Orphan keys are not in
          the catalog.
        </p>
        @if (deleteRow(); as row) {
          <p class="mb-0 mt-2">
            <code>{{ row.key }}</code>
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
  protected readonly columns = COLUMNS
  protected readonly isPersisted = isPersistedSetting
  protected readonly isOrphan = isOrphanSetting

  protected readonly settingsTable = computed(() =>
    createHilosSettingsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSettingsActions(this.context()),
  )

  // Edit dialog: one row's custom value (or a reset back to the catalog default).
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosSettingRow | null>(null)
  protected readonly editValue = signal('')
  protected readonly editUseCustom = signal(false)
  protected readonly edit = createHilosTrackedAction()

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
  protected readonly editDirty = computed(() => {
    const row = this.editRow()

    return !!row && this.editOverride() !== row.overrideValue
  })
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? `Edit · ${row.key}` : 'Edit setting'
  })
  protected readonly deleteTitle = computed(() => {
    const row = this.deleteRow()

    return row ? `Delete · ${row.key}` : 'Delete setting'
  })

  protected openEdit(row: HilosSettingRow): void {
    this.edit.clearError()
    this.editRow.set(row)
    this.editUseCustom.set(isPersistedSetting(row))
    this.editValue.set(row.overrideValue ?? row.value ?? '')
    this.editOpen.set(true)
  }

  // Authoritative-backend: dispatch the tracked action, close on its `::success`
  // reply; a failure stays open with the reason shown.
  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy()) {
      return
    }
    const next = this.editOverride()
    if (next === row.overrideValue) {
      this.editOpen.set(false)

      return
    }
    // A persisted row updates in place; an on-default catalog key adds a custom value.
    const handle = isPersistedSetting(row)
      ? this.actions().sendSettingUpdate(row.key, next)
      : this.actions().sendSettingAdd(row.key, next ?? '')
    if (await this.edit.run(handle)) {
      this.editOpen.set(false)
    }
  }

  protected openDelete(row: HilosSettingRow): void {
    this.del.clearError()
    this.deleteRow.set(row)
    this.deleteOpen.set(true)
  }

  protected async submitDelete(): Promise<void> {
    const row = this.deleteRow()
    if (!row || this.del.busy()) {
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
