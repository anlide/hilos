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
  HILOS_LEGAL_SETTING_COPY,
  HILOS_LEGAL_VALUE_COPY,
  type HilosLegalSettingEditFields,
  type HilosLegalSettingRow,
} from '@hilos/core/admin/legal'
import {
  HilosPages,
  hilosRowEditIdle,
  isHiddenValue,
  type HilosLegalContext,
  type HilosTableBody,
  type TableViewportRow,
} from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosHiddenMark,
  HilosHideable,
  HilosTableEmptyState,
  HilosModal,
  HilosActionError,
  HilosEditNotice,
  ConflictActions,
  LoadingButton,
  mirrorHilosSignal,
  createHilosTrackedAction,
} from '@hilos/angular/base'

let inputSequence = 0

/** Two legal settings edited through a modal-owned row merge and tracked writes. */
@Component({
  selector: 'hilos-legal-settings-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHiddenMark,
    HilosHideable,
    HilosTableEmptyState,
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
      <ng-template #noFallback></ng-template>
      @if (settingsBody() === 'unavailable') {
        <hilos-table-empty-state
          [controller]="table().controller"
          kind="unavailable"
          [fallback]="noFallback"
        />
      }
      @for (setting of settings(); track setting.rowKey) {
        <section
          class="mt-4"
          [attr.aria-labelledby]="inputId + '-' + setting.rowKey"
        >
          <h2
            [id]="inputId + '-' + setting.rowKey"
            class="h6 text-uppercase text-body-secondary mb-2"
          >
            {{ copy[setting.rowKey]?.label ?? setting.rowKey }}
          </h2>
          <div
            class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
          >
            <div class="flex-grow-1">
              <div class="fw-semibold small">
                {{ copy[setting.rowKey]?.label ?? setting.rowKey }}
              </div>
              <div class="small text-body-secondary">
                {{ copy[setting.rowKey]?.hint }}
              </div>
            </div>
            <div class="d-flex align-items-center gap-3">
              <div class="text-end small">
                <div
                  class="fw-semibold"
                  [attr.data-id]="'legal-setting-value-' + setting.rowKey"
                >
                  <hilos-hideable [value]="setting.value"
                    ><ng-template let-shown>{{
                      valueLabel(shown)
                    }}</ng-template></hilos-hideable
                  >
                </div>
                <dl
                  class="d-flex flex-wrap justify-content-end gap-1 text-body-secondary mb-0"
                >
                  <dt class="fw-normal">Default:</dt>
                  <dd class="mb-0">
                    <hilos-hideable [value]="setting.defaultValue"
                      ><ng-template let-shown>{{
                        valueLabel(shown)
                      }}</ng-template></hilos-hideable
                    >
                  </dd>
                </dl>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                [attr.data-id]="'legal-setting-edit-' + setting.rowKey"
                [attr.aria-label]="
                  'Edit ' + (copy[setting.rowKey]?.label ?? setting.rowKey)
                "
                (click)="open(setting.rowKey)"
              >
                Edit
              </button>
            </div>
          </div>
        </section>
      }
      <hilos-modal
        [open]="opened()"
        (openChange)="$event ? undefined : editor().close()"
        [title]="title()"
        [busy]="saving()"
        (cancel)="editor().close()"
      >
        <hilos-action-error
          [action]="action"
          detailsTitle="Couldn't save the legal setting"
        />
        @if (row(); as setting) {
          <form (submit)="save($event)">
            @if (valueHidden()) {
              <div class="form-label">
                {{ copy[setting.rowKey]?.label ?? setting.rowKey }}
              </div>
              <hilos-hidden-mark />
            } @else {
              <label class="form-label" [for]="inputId">{{
                copy[setting.rowKey]?.label ?? setting.rowKey
              }}</label>
              <select
                [id]="inputId"
                class="form-select"
                data-id="legal-setting-input"
                data-autofocus
                (change)="setValue($event)"
                [disabled]="saving()"
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
            }
            <hilos-edit-notice
              [kind]="state().notice?.kind ?? null"
              [text]="noticeText()"
              dataId="legal-setting-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="state().conflict"
            [disableSave]="!saveAvailable()"
            [saveLabel]="saveLabel()"
            (save)="save()"
            (acceptMine)="editor().keepMine()"
            (acceptTheirs)="editor().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="saving()"
                data-id="legal-setting-cancel"
                (click)="requestClose()"
              >
                Cancel
              </button>
            </ng-template>
            <ng-template #saveButton let-disabled="disabled" let-onSave="onSave"
              ><button
                hilosLoadingButton
                class="btn-primary"
                [loading]="action.busy()"
                [disabled]="disabled"
                data-id="legal-setting-save"
                (click)="onSave()"
              >
                {{ saveLabel() }}
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
  protected readonly copy = HILOS_LEGAL_SETTING_COPY
  protected readonly valueCopy = HILOS_LEGAL_VALUE_COPY
  protected readonly inputId = `legal-setting-input-${++inputSequence}`
  protected readonly table = computed(() =>
    createHilosLegalSettingsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosLegalSettingsActions(this.context()),
  )
  protected readonly editor = computed(() =>
    createHilosLegalSettingEdit(this.table().controller, this.actions()),
  )
  protected readonly action = createHilosTrackedAction({ toast: false })
  protected readonly settingsBody = signal<HilosTableBody>('loading')
  private readonly settingsRows = signal<
    readonly TableViewportRow<HilosLegalSettingRow>[]
  >([])
  /** The settings rows the carrier's window holds, in its order. */
  protected readonly settings = computed(() =>
    this.settingsRows().flatMap(({ row }) => (row ? [row] : [])),
  )
  protected readonly opened = signal(false)
  protected readonly row = signal<HilosLegalSettingRow | null>(null)
  // A value hidden from a viewer of the admin view mode stays the one hidden
  // value: the draft is never dirty, and the modal shows the mark in place of
  // the list.
  protected readonly form = signal<HilosLegalSettingEditFields>({ value: '' })
  protected readonly value = computed(() => this.form().value)
  protected readonly valueHidden = computed(() => isHiddenValue(this.value()))
  protected readonly state = signal(
    hilosRowEditIdle<HilosLegalSettingEditFields>({ value: '' }),
  )
  protected readonly noticeText = signal('')
  protected readonly saveAvailable = signal(false)
  protected readonly saveLabel = signal('Save')
  protected readonly saving = signal(false)
  protected readonly title = computed(() => {
    const row = this.row()
    return row ? (this.copy[row.rowKey]?.label ?? row.rowKey) : 'Edit setting'
  })

  constructor() {
    effect((onCleanup) => {
      const table = this.table(),
        editor = this.editor()
      const off = [
        mirrorHilosSignal(table.controller.frame.body, this.settingsBody),
        mirrorHilosSignal(table.controller.rows, this.settingsRows),
        mirrorHilosSignal(editor.opened, this.opened),
        mirrorHilosSignal(editor.row, this.row),
        mirrorHilosSignal(editor.form, this.form),
        mirrorHilosSignal(editor.state, this.state),
        mirrorHilosSignal(editor.noticeText, this.noticeText),
        mirrorHilosSignal(editor.saveAvailable, this.saveAvailable),
        mirrorHilosSignal(editor.saveLabel, this.saveLabel),
        mirrorHilosSignal(editor.saving, this.saving),
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
  /**
   * A setting value's label, or the value itself when it has none.
   *
   * @param value The value, already narrowed clear of the hidden mark.
   */
  protected valueLabel(value: string): string {
    return this.valueCopy[value] ?? value
  }

  protected setValue(event: Event): void {
    this.editor().setForm({ value: (event.target as HTMLSelectElement).value })
  }
  // Save and Enter go through the session's one door: it refuses, closes an
  // unchanged choice, or sends through the tracked action and closes on success.
  protected save(event?: Event): void {
    event?.preventDefault()
    void this.editor().save(this.action.run)
  }
}
