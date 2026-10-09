// HilosSecurityImpersonationPage — the framework impersonation settings page
// (HilosPages.SECURITY_IMPERSONATION, HIL-1170): the seven settings of
// impersonation, one row each — whether it exists in the product, what may be
// done inside someone else's account, whether its sign-in may be touched,
// whether the administrator carries their own rights in, and whom it may take
// over. The six yes-or-no rows carry a switch; the scope shows its value in
// words and a pencil that opens a modal (the modal-only editing rule).
// The table, the row view-model, the words and the two writes are the core
// headless's (createHilosSecurityImpersonationTable /
// createHilosSecurityImpersonationActions); this view owns only the markup, so a
// project mounts it by passing its HilosImpersonationContext.
// A switch is a tracked action, as on the sign-in methods page: a spinner on its
// own row while it flies, the other switches disabled, no toast on success, and
// the switch moves only when the table's row does; a refusal is the action's
// toast. The scope's modal is the two-factor page's: it holds its row in focus
// and merges against it through the shared row-edit helper (rowEdit.ts,
// conflict-resolution.md), saying what happened elsewhere on one line of room
// held in advance (HilosEditNotice); Save closes it on the server's answer,
// whose sentence is the toast, and a refusal stays in it. A viewer of the
// admin view mode finds the switches and Save disabled by the SDK's own
// controls (HIL-1261).
// The screen is built from text: the mockup still draws these rows on the
// two-factor page (D-143). Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_IMPERSONATION_SCOPE_HINT,
  HILOS_IMPERSONATION_SCOPE_VALUES,
  HILOS_IMPERSONATION_SETTING_COPY,
  HilosPages,
  createHilosSecurityImpersonationActions,
  createHilosSecurityImpersonationTable,
  hilosImpersonationScopeOf,
  isHilosImpersonationSwitch,
  createHilosImpersonationScopeEdit,
  hilosRowEditIdle,
} from '@hilos/core'
import type {
  HilosImpersonationScopeEditFields,
  HilosImpersonationScopeEditForm,
  HilosImpersonationContext,
  HilosImpersonationScope,
  HilosImpersonationSettingRow,
} from '@hilos/core'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework impersonation settings page: six switches and the scope in a modal. */
@Component({
  selector: 'hilos-security-impersonation-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosActionError,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
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
      <hilos-viewport-table
        [controller]="settings().controller"
        [dataId]="'hilos-impersonation-table'"
      >
        <ng-template hilosTableCell="rowKey" let-row>
          <div class="fw-semibold">{{ labelOf(row) }}</div>
          <div class="small text-body-secondary">{{ hintOf(row.rowKey) }}</div>
        </ng-template>
        <ng-template hilosTableCell="value" let-row>
          @if (isSwitch(row.rowKey)) {
            <hilos-hideable [value]="row.enabled">
              <ng-template let-enabled>
                <hilos-switch
                  class="mb-0"
                  [checked]="enabled"
                  [busy]="pendingSwitchKey() === row.rowKey"
                  [disabled]="toggle.busy()"
                  [aria-label]="labelOf(row)"
                  [dataId]="'hilos-impersonation-switch-' + row.rowKey"
                  (toggle)="toggleSwitch(row, $event)"
                />
              </ng-template>
            </hilos-hideable>
          } @else {
            <span data-id="hilos-impersonation-scope-value"
              ><hilos-hideable [value]="row.value"
                ><ng-template>{{
                  scopeCopy[scopeOf(row)]
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
              title="Edit"
              [attr.aria-label]="'Edit ' + labelOf(row)"
              data-id="hilos-impersonation-scope-edit"
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
              <div class="form-label fs-6">{{ labelOf(row) }}</div>
              <hilos-hidden-mark />
            } @else {
              <fieldset aria-describedby="hilos-impersonation-scope-hint">
                <legend class="form-label fs-6">{{ labelOf(row) }}</legend>
                @for (value of scopeValues; track value) {
                  <div class="form-check">
                    <input
                      [id]="'hilos-impersonation-scope-' + value + '-field'"
                      class="form-check-input"
                      type="radio"
                      name="hilos-impersonation-scope"
                      [value]="value"
                      [checked]="editScope() === value"
                      [attr.data-id]="'hilos-impersonation-scope-' + value"
                      data-autofocus
                      (change)="setScope(value)"
                    />
                    <label
                      class="form-check-label"
                      [for]="'hilos-impersonation-scope-' + value + '-field'"
                      >{{ scopeCopy[value] }}</label
                    >
                  </div>
                }
              </fieldset>
              <p id="hilos-impersonation-scope-hint" class="form-text mb-0">
                {{ scopeHint }}
              </p>
            }
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-impersonation-scope-notice"
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
                data-id="hilos-impersonation-scope-save"
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
export class HilosSecurityImpersonationPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosImpersonationContext>()

  protected readonly page = HilosPages.SECURITY_IMPERSONATION
  protected readonly scopeValues = HILOS_IMPERSONATION_SCOPE_VALUES
  protected readonly scopeCopy = HILOS_IMPERSONATION_SCOPE_COPY
  protected readonly scopeHint = HILOS_IMPERSONATION_SCOPE_HINT
  protected readonly isSwitch = isHilosImpersonationSwitch
  protected readonly scopeOf = hilosImpersonationScopeOf

  protected readonly settings = computed(() =>
    createHilosSecurityImpersonationTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecurityImpersonationActions(this.context()),
  )

  // One tracked runner for every switch: a single in-flight guard across rows is
  // enough, and the busy flag disables every switch while one write is settling.
  protected readonly toggle = createHilosTrackedAction()
  protected readonly pendingSwitchKey = signal<string | null>(null)

  // The scope's modal: the choice as made until Save, as the core window has it;
  // this view binds the radio group through mirrors of the session's signals.
  protected readonly editor = computed(() =>
    createHilosImpersonationScopeEdit(
      this.settings().controller,
      this.actions(),
    ),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosImpersonationSettingRow | null>(null)
  protected readonly editForm = signal<HilosImpersonationScopeEditForm>({
    hidden: false,
    scope: 'act',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosImpersonationScopeEditFields>({ scope: 'act' }),
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
  protected readonly editScope = computed(() => this.editForm().scope)
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
  protected labelOf(row: HilosImpersonationSettingRow): string {
    return HILOS_IMPERSONATION_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
  }

  /**
   * What a setting decides.
   *
   * @param settingKey The setting.
   */
  protected hintOf(settingKey: string): string {
    return HILOS_IMPERSONATION_SETTING_COPY[settingKey]?.hint ?? ''
  }

  // Dispatch the switch as a tracked action. Nothing is set optimistically: the
  // switch follows the row, which moves when the setting is written.
  protected async toggleSwitch(
    row: HilosImpersonationSettingRow,
    next: boolean,
  ): Promise<void> {
    this.pendingSwitchKey.set(row.rowKey)
    try {
      await this.toggle.run(this.actions().sendSwitchSet(row.rowKey, next))
    } finally {
      this.pendingSwitchKey.set(null)
    }
  }

  protected openEdit(row: HilosImpersonationSettingRow): void {
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

  protected setScope(scope: HilosImpersonationScope): void {
    this.editor().patchForm({ scope })
  }
}
