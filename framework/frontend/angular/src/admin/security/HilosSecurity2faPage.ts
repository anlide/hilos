// HilosSecurity2faPage — the framework two-step verification admin page
// (HilosPages.SECURITY_2FA, HIL-494): the eight second-factor settings, one row
// each — whether the second factor is on at all and whether turning it off
// forgets the trusted browsers (HIL-1303), then who must use it, the days a
// device is trusted, the size of a set of backup codes, and the removal wait
// with its bounds. The two first rows carry a switch, as on the Impersonation
// tab; each other row shows its value in words and a pencil that opens a modal
// (the modal-only editing rule), and a value a setting's rule refuses stays in
// the modal with the refusal above it.
// A switch is a tracked action: a spinner on its own row while it flies, the
// other switch disabled, no toast on success, and the switch moves only when the
// table's row does; a refusal is the action's toast. The first switch, while on,
// does not move at all: it opens the window that turns the factor off — the
// operation's "Confirm it's you" first, then what turning it off does and "Turn
// off" (createHilosTwoFactorDisable, in the order of the account card's
// windows). While the factor is off every row below the first is dimmed, its
// pencil or its switch locked (the table's locked).
// The table, the row view-model, the edit round-trip, the window and the words
// are the core headless's; this view owns only the markup, so a project mounts
// it by passing its HilosTwoFactorContext. The modal holds its row in focus and
// merges against it through the shared row-edit helper (rowEdit.ts,
// conflict-resolution.md), saying what happened elsewhere on one line of room
// held in advance (HilosEditNotice). The screen is built from text: the
// mockup's node is a debt (D-115, and for the switches, the dimmed rows and the
// window D-234). Bootstrap classes only (styling-rules.md).
import {
  afterRenderEffect,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_TWO_FACTOR_DISABLE_COPY,
  HilosSecondFactorSettingKey,
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosTwoFactorDisable,
  describeHilosSecondFactorSetting,
  createHilosTwoFactorSettingEdit,
  isHilosSecondFactorSwitch,
} from '@hilos/core/admin/security'
import {
  HILOS_STEP_UP_COPY,
  HilosPages,
  focusInitial,
  hilosRowEditIdle,
} from '@hilos/core/base'
import type {
  HilosTwoFactorDisableStep,
  HilosTwoFactorSettingEditFields,
  HilosTwoFactorSettingEditForm,
  HilosTwoFactorContext,
  HilosTwoFactorSettingRow,
} from '@hilos/core/admin/security'

import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosAdminPage,
  HilosEditNotice,
  HilosFormError,
  HilosHiddenMark,
  HilosHideable,
  HilosModal,
  HilosSwitch,
  HilosTableCell,
  HilosViewportTable,
  LoadingButton,
  HilosStepUpStep,
  mirrorHilosSignal,
  createHilosTrackedAction,
} from '@hilos/angular/base'

/**
 * The framework two-step verification page: two switches, the window that
 * turns the factor off, and six settings, each edited in a modal.
 */
@Component({
  selector: 'hilos-security2fa-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosFormError,
    HilosHiddenMark,
    HilosHideable,
    HilosModal,
    HilosStepUpStep,
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
          <div [class.opacity-50]="isLocked(row)">
            <div class="fw-semibold">{{ labelOf(row) }}</div>
            <div class="small text-body-secondary">
              {{ hintOf(row.rowKey) }}
            </div>
          </div>
        </ng-template>
        <ng-template hilosTableCell="value" let-row>
          @if (isSwitch(row.rowKey)) {
            <hilos-hideable [value]="row.enabled">
              <ng-template let-enabled>
                <hilos-switch
                  class="mb-0"
                  [checked]="enabled"
                  [busy]="
                    pendingSwitchKey() === row.rowKey ||
                    (row.rowKey === enabledKey && disableStep() === 'opening')
                  "
                  [disabled]="toggle.busy() || isLocked(row)"
                  [aria-label]="labelOf(row)"
                  [dataId]="'hilos-2fa-switch-' + row.rowKey"
                  (toggle)="toggleSwitch(row, $event)"
                />
              </ng-template>
            </hilos-hideable>
          } @else {
            <span
              [class.opacity-50]="isLocked(row)"
              [attr.data-id]="'hilos-2fa-value-' + row.rowKey"
              ><hilos-hideable [value]="row.value"
                ><ng-template let-value>{{
                  describe(row.rowKey, value)
                }}</ng-template></hilos-hideable
              ></span
            >
          }
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (!isSwitch(row.rowKey)) {
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              [attr.title]="'Edit ' + labelOf(row)"
              [attr.aria-label]="'Edit ' + labelOf(row)"
              [disabled]="isLocked(row)"
              [attr.data-id]="'hilos-2fa-edit-' + row.rowKey"
              (click)="openEdit(row)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
          }
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

      <hilos-modal
        [open]="disableOpen()"
        (openChange)="$event ? undefined : disable().close()"
        [title]="
          disableStep() === 'step-up' ? stepUpCopy.title : disableCopy.title
        "
        initialFocus="inner"
        [closeOnBackdrop]="!disableBusy()"
        [closeOnEsc]="!disableBusy()"
      >
        <div #disableBody data-id="hilos-2fa-disable-window">
          <div class="visually-hidden" role="alert" aria-live="assertive">
            {{
              disableStep() === 'step-up' ? stepUpRefusal() : disableRefusal()
            }}
          </div>
          @if (disableStep() === 'step-up') {
            <form
              id="hilos-2fa-disable-proof"
              data-id="hilos-2fa-disable-step-up"
              (submit)="$event.preventDefault(); disable().confirmStepUp()"
            >
              <hilos-step-up-step [controller]="disable().stepUp" />
            </form>
          } @else {
            @if (disableStep() === 'consequences') {
              <p>{{ disableCopy.intro }}</p>
              <ul data-id="hilos-2fa-disable-consequences">
                @for (line of consequences(); track line) {
                  <li>{{ line }}</li>
                }
              </ul>
            }
            <hilos-form-error
              [message]="disableRefusal()"
              dataId="hilos-2fa-disable-refusal"
            />
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="disableBusy()"
            data-id="hilos-2fa-disable-cancel"
            (click)="requestClose()"
          >
            {{ disableCopy.cancel }}
          </button>
          @if (disableStep() === 'step-up') {
            <button
              hilosLoadingButton
              class="btn-primary"
              type="submit"
              form="hilos-2fa-disable-proof"
              [loading]="stepUpBusy()"
              data-id="hilos-2fa-disable-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          } @else if (disableStep() === 'consequences') {
            <button
              hilosLoadingButton
              class="btn-danger"
              [loading]="disableBusy()"
              [disabled]="disableBusy()"
              data-id="hilos-2fa-disable-confirm"
              (click)="disable().confirm()"
            >
              {{ disableCopy.confirm }}
            </button>
          }
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosSecurity2faPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosTwoFactorContext>()

  protected readonly page = HilosPages.SECURITY_2FA
  protected readonly enabledKey = HilosSecondFactorSettingKey.enabled
  protected readonly requiredKey = HilosSecondFactorSettingKey.required
  protected readonly requiredValues = HILOS_SECOND_FACTOR_REQUIRED_VALUES
  protected readonly requiredCopy = HILOS_SECOND_FACTOR_REQUIRED_COPY

  protected readonly settings = computed(() =>
    createHilosSecurityTwoFactorTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecurityTwoFactorActions(this.context()),
  )
  protected readonly isSwitch = isHilosSecondFactorSwitch
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly disableCopy = HILOS_TWO_FACTOR_DISABLE_COPY
  protected readonly locked = signal<readonly string[]>([])

  // One tracked runner for both switches: a single in-flight guard is enough,
  // and the busy flag disables the other switch while one write is settling.
  protected readonly toggle = createHilosTrackedAction()
  protected readonly pendingSwitchKey = signal<string | null>(null)

  // The window that turns the factor off, over the table; this view draws it
  // through mirrors of its signals.
  protected readonly disable = computed(() =>
    createHilosTwoFactorDisable(this.context(), this.settings().controller),
  )
  protected readonly disableStep = signal<HilosTwoFactorDisableStep>('closed')
  protected readonly disableBusy = signal(false)
  protected readonly disableRefusal = signal<string | null>(null)
  protected readonly stepUpBusy = signal(false)
  protected readonly stepUpRefusal = signal<string | null>(null)
  protected readonly consequences = signal<readonly string[]>([])
  protected readonly disableOpen = computed(() => {
    const step = this.disableStep()

    return step === 'step-up' || step === 'consequences' || step === 'refused'
  })
  private readonly disableBody =
    viewChild<ElementRef<HTMLElement>>('disableBody')

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
    effect((onCleanup) => {
      const settings = this.settings()
      const disable = this.disable()
      disable.start()
      const off = [
        mirrorHilosSignal(settings.locked, this.locked),
        mirrorHilosSignal(disable.step, this.disableStep),
        mirrorHilosSignal(disable.busy, this.disableBusy),
        mirrorHilosSignal(disable.refusal, this.disableRefusal),
        mirrorHilosSignal(disable.stepUp.busy, this.stepUpBusy),
        mirrorHilosSignal(disable.stepUp.refusal, this.stepUpRefusal),
        mirrorHilosSignal(disable.consequences, this.consequences),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        disable.dispose()
      })
    })
    // The step the window moves to takes the focus once it is drawn: the field
    // that held it is gone, and the modal places focus only when it opens.
    afterRenderEffect(() => {
      this.disableStep()
      const dialog =
        this.disableBody()?.nativeElement.closest<HTMLElement>(
          '[role="dialog"]',
        )
      if (dialog) {
        focusInitial(dialog)
      }
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

  /**
   * Whether a row is locked: below the first while the factor is off.
   *
   * @param row The row of the setting.
   */
  protected isLocked(row: HilosTwoFactorSettingRow): boolean {
    return this.locked().includes(row.rowKey)
  }

  // Turning the factor off opens its window and leaves the switch where it is;
  // anything else is the switch's own tracked write. Nothing is set
  // optimistically: the switch follows the row, which moves when the setting is
  // written.
  protected async toggleSwitch(
    row: HilosTwoFactorSettingRow,
    next: boolean,
  ): Promise<void> {
    if (row.rowKey === HilosSecondFactorSettingKey.enabled && !next) {
      void this.disable().open()

      return
    }
    this.pendingSwitchKey.set(row.rowKey)
    try {
      await this.toggle.run(this.actions().switchSet(row.rowKey, next))
    } finally {
      this.pendingSwitchKey.set(null)
    }
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
