import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalSettingsTable,
  createHilosLegalSettingsActions,
  createHilosLegalSettingEdit,
  HilosPages,
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
  HILOS_LEGAL_SETTING_PREVIEWS,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  type RowEditState,
  type HilosLegalContext,
  type HilosLegalSettingRow,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { ConflictActions } from '../../ConflictActions.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

let inputSequence = 0

/** Two legal settings edited through a modal-owned row merge and tracked writes. */
@Component({
  selector: 'hilos-legal-settings-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosViewportTable,
    HilosTableCell,
    HilosModal,
    HilosActionError,
    HilosEditNotice,
    ConflictActions,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <p class="small text-body-secondary">
        Two settings control how consent is given and what happens after a
        deadline. Document texts and deadlines stay in code.
      </p>
      <hilos-viewport-table [controller]="table().controller">
        <ng-template [hilosTableCell]="keys.rowKey" let-setting
          ><strong>{{ copy[setting.rowKey]?.label ?? setting.rowKey }}</strong>
          <div class="small text-body-secondary">
            {{ copy[setting.rowKey]?.hint }}
          </div></ng-template
        >
        <ng-template [hilosTableCell]="keys.value" let-setting
          ><span [attr.data-id]="'legal-setting-value-' + setting.rowKey">{{
            valueCopy[setting.value] ?? setting.value
          }}</span>
          <div class="small text-body-secondary">
            Default:
            {{ valueCopy[setting.defaultValue] ?? setting.defaultValue }}
          </div></ng-template
        >
        <ng-template [hilosTableCell]="actionsKey" let-setting
          ><button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            [attr.data-id]="'legal-setting-edit-' + setting.rowKey"
            (click)="open(setting.rowKey)"
          >
            Edit
          </button></ng-template
        >
      </hilos-viewport-table>
      <section class="mt-4">
        <h2 class="h5">Previews</h2>
        <div class="row g-3">
          @for (preview of previews; track preview.key) {
            <div class="col-md-6">
              <div
                class="border rounded p-3 h-100"
                [attr.data-id]="'legal-setting-preview-' + preview.key"
              >
                <h3 class="h6">{{ preview.title }}</h3>
                @if (preview.key === 'checkbox') {
                  <label class="small d-flex align-items-start gap-2 mb-2"
                    ><input
                      type="checkbox"
                      class="form-check-input flex-shrink-0"
                      disabled
                    />{{ preview.text }}</label
                  >
                }
                @if (preview.key === 'checkbox' || preview.key === 'line') {
                  <button
                    type="button"
                    class="btn btn-sm btn-primary w-100 mb-2"
                    disabled
                  >
                    Create account
                  </button>
                }
                @if (preview.key !== 'checkbox') {
                  <blockquote
                    [class]="
                      preview.key === 'freeze'
                        ? 'small alert alert-info'
                        : preview.key === 'remind'
                          ? 'small alert alert-warning'
                          : 'small'
                    "
                  >
                    {{ preview.text }}
                  </blockquote>
                }
                <p class="small text-body-secondary mb-0">{{ preview.hint }}</p>
              </div>
            </div>
          }
        </div>
      </section>
      <section class="mt-4 small text-body-secondary">
        <h2 class="h6">What is not a setting</h2>
        <p class="mb-0">
          The acceptance window ends on the revision's effective date. There is
          no global window length, switch to bypass consent, or editor for
          document text.
        </p>
      </section>
      <hilos-modal
        [open]="row() !== null"
        (openChange)="$event ? undefined : editor().close()"
        [title]="title()"
        (cancel)="editor().close()"
      >
        <hilos-action-error
          [action]="action"
          detailsTitle="Couldn't save the legal setting"
        />
        @if (row(); as setting) {
          <form (submit)="save($event)">
            <label class="form-label" [for]="inputId">{{
              copy[setting.rowKey]?.label ?? setting.rowKey
            }}</label>
            <select
              [id]="inputId"
              class="form-select"
              data-id="legal-setting-input"
              data-autofocus
              [value]="value()"
              (change)="setValue($event)"
              [disabled]="action.busy()"
            >
              @for (
                option of copy[setting.rowKey]?.values ?? [];
                track option
              ) {
                <option [value]="option" [selected]="option === value()">
                  {{ valueCopy[option] ?? option }}
                </option>
              }
            </select>
            <hilos-edit-notice
              [kind]="state().notice?.kind ?? null"
              [text]="noticeText()"
              dataId="legal-setting-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="action.busy()"
            data-id="legal-setting-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <div
            hilosConflictActions
            [conflict]="state().conflict"
            [disableSave]="!state().dirty || action.busy() || state().gone"
            [mergeable]="false"
            [saveLabel]="state().gone ? 'Deleted' : 'Save'"
            (save)="save()"
            (acceptMine)="editor().keepMine()"
            (acceptTheirs)="editor().takeTheirs()"
          >
            <ng-template #saveButton let-disabled="disabled" let-onSave="onSave"
              ><button
                hilosLoadingButton
                class="btn-primary"
                [loading]="action.loading()"
                [disabled]="disabled"
                data-id="legal-setting-save"
                (click)="onSave()"
              >
                {{ state().gone ? 'Deleted' : 'Save' }}
              </button></ng-template
            >
          </div>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosLegalSettingsPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_SETTINGS
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly copy = HILOS_LEGAL_SETTING_COPY
  protected readonly valueCopy = HILOS_LEGAL_VALUE_COPY
  protected readonly previews = HILOS_LEGAL_SETTING_PREVIEWS
  protected readonly inputId = `legal-setting-input-${++inputSequence}`
  protected readonly table = computed(() =>
    createHilosLegalSettingsTable(this.context()),
  )
  protected readonly editor = computed(() =>
    createHilosLegalSettingEdit(this.table().controller),
  )
  private readonly actions = computed(() =>
    createHilosLegalSettingsActions(this.context()),
  )
  protected readonly action = createHilosTrackedAction({ toast: false })
  protected readonly row = signal<HilosLegalSettingRow | null>(null)
  protected readonly value = signal('')
  protected readonly state = signal<RowEditState<{ value: string }>>(
    resolveRowEdit(undefined, openRowEdit({ value: '' }), { value: '' }),
  )
  protected readonly noticeText = signal('')
  protected readonly title = computed(() => {
    const row = this.row()
    return row ? (this.copy[row.rowKey]?.label ?? row.rowKey) : 'Edit setting'
  })

  constructor() {
    effect((onCleanup) => {
      const table = this.table(),
        editor = this.editor()
      const update = () => {
        this.row.set(editor.row.get())
        this.value.set(editor.value.get())
        this.state.set(editor.state.get())
        this.noticeText.set(editor.noticeText.get())
      }
      update()
      const off = [
        subscribeSignal(editor.row, update),
        subscribeSignal(editor.value, update),
        subscribeSignal(editor.state, update),
        subscribeSignal(editor.noticeText, update),
      ]
      table.start()
      editor.start()
      onCleanup(() => {
        for (const stop of off) stop()
        editor.dispose()
        table.dispose()
      })
    })
  }
  protected open(key: string): void {
    if (this.action.busy()) return
    this.action.clearError()
    this.editor().open(key)
  }
  protected setValue(event: Event): void {
    this.editor().setValue((event.target as HTMLSelectElement).value)
  }
  protected async save(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.row(),
      state = this.state()
    if (row === null || this.action.busy() || state.gone || state.conflict)
      return
    if (!state.dirty) {
      this.editor().close()
      return
    }
    if (
      await this.action.run(
        this.actions().sendSettingSet(row.rowKey, this.value()),
      )
    )
      this.editor().close()
  }
}
