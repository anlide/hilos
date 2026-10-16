// HilosAppearancePage — the framework Appearance page (HilosPages.APPEARANCE):
// the installation's two theme settings, Theme switching and Default theme, in
// the standard viewport table straight under the admin shell's header. The page
// writes no paragraph of its own: the header's caption already says what the
// section is (mockups/admin/appearance). The table, the frame it declares, the
// row view-model and the two writes are the core headless's
// (createHilosAppearanceTable / createHilosAppearanceActions); each row's pencil
// opens its own window over the core row-edit session
// (createHilosAppearanceSwitchingEdit / createHilosAppearanceDefaultThemeEdit,
// conflict-resolution.md), which holds the row in focus, merges against the live
// row and says what happened elsewhere on one line of room held in advance
// (HilosEditNotice). This view mirrors the sessions and binds the switch, the
// radios and the text.
// Authoritative-backend: Save dispatches a tracked action and the window closes
// on its `::success` reply (createHilosTrackedAction); a refusal leaves it open
// with the draft (toasts.md). A viewer of the admin view mode opens both windows
// and moves their fields, while Save — and Enter in the form — stands locked
// inside the admin shell (injectLookOnly) and the server refuses the write
// anyway. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  Directive,
  computed,
  effect,
  input,
  output,
  signal,
} from '@angular/core'
import {
  APPEARANCE_DEFAULT_VALUE_FIELD,
  APPEARANCE_ROW_KEY_FIELD,
  APPEARANCE_VALUE_FIELD,
  HILOS_TABLE_ACTIONS_KEY,
  HIDDEN_VALUE,
  HilosAppearanceSettingKey,
  HilosPages,
  THEME_POSITIONS,
  createHilosAppearanceActions,
  createHilosAppearanceDefaultThemeEdit,
  createHilosAppearanceSwitchingEdit,
  createHilosAppearanceTable,
  hilosRowEditIdle,
  isHiddenValue,
} from '@hilos/core'
import type {
  HilosAppearanceContext,
  HilosAppearanceDefaultThemeEditFields,
  HilosAppearanceSettingRow,
  HilosAppearanceSwitchingEditFields,
  HilosAppearanceSwitchingEditForm,
  ThemePosition,
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
import { injectLookOnly } from '../../hilosLookOnly.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/**
 * The form of one window: Enter saves the way the Save button does, and stays
 * still wherever that button is locked (injectLookOnly). It stands inside the
 * admin shell, which is what tells a viewer of the admin view mode apart — the
 * page class above the shell cannot read it. Exported only because the page's
 * compiled template imports it (NG3004); the package index leaves it out.
 */
@Directive({
  selector: 'form[hilosAppearanceForm]',
  host: { '(submit)': 'submit($event)' },
})
export class AppearanceForm {
  /** The window's save, asked for by Enter in the form. */
  readonly send = output<void>()

  private readonly lookOnly = injectLookOnly()

  protected submit(event: Event): void {
    event.preventDefault()
    if (!this.lookOnly.locked()) this.send.emit()
  }
}

/**
 * The framework Appearance admin page: the two theme settings with a window
 * each to edit them.
 */
@Component({
  selector: 'hilos-appearance-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    AppearanceForm,
    ConflictActions,
    ConflictHeader,
    HilosActionError,
    HilosAdminPage,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    HilosModal,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="table().controller">
        <ng-template [hilosTableCell]="keys.rowKey" let-setting>
          <strong>{{ settingLabel(setting.rowKey) }}</strong>
        </ng-template>
        <ng-template [hilosTableCell]="keys.value" let-setting>
          <span [attr.data-id]="'appearance-setting-value-' + setting.rowKey"
            ><hilos-hideable [value]="setting.value"
              ><ng-template let-shown>{{
                valueLabel(shown)
              }}</ng-template></hilos-hideable
            ></span
          >
        </ng-template>
        <ng-template [hilosTableCell]="keys.defaultValue" let-setting>
          <span [attr.data-id]="'appearance-setting-default-' + setting.rowKey"
            ><hilos-hideable [value]="setting.defaultValue"
              ><ng-template let-shown>{{
                valueLabel(shown)
              }}</ng-template></hilos-hideable
            ></span
          >
        </ng-template>
        <ng-template [hilosTableCell]="keys.actions" let-setting>
          @if (setting.rowKey === settingKey.switchingEnabled) {
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit Theme switching"
              aria-label="Edit Theme switching"
              data-id="appearance-setting-edit-theme.switching_enabled"
              (click)="openSwitching(setting)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
              <span class="d-md-none ms-1">Edit</span>
            </button>
          }
          @if (setting.rowKey === settingKey.defaultTheme) {
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit Default theme"
              aria-label="Edit Default theme"
              data-id="appearance-setting-edit-theme.default"
              (click)="openDefaultTheme(setting)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
              <span class="d-md-none ms-1">Edit</span>
            </button>
          }
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="switchingOpen()"
        (openChange)="$event || switchingEditor().close()"
        [aria-label]="'Edit · Theme switching'"
        [confirmOnClose]="switchingState().dirty"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="'Edit · Theme switching'"
          [conflict]="switchingState().conflict"
        ></h5>
        <hilos-action-error
          [action]="switchingAction"
          detailsTitle="Couldn't save"
        />
        @if (switchingRow(); as row) {
          <form hilosAppearanceForm (send)="submitSwitching()">
            @if (switchingForm().hidden) {
              <hilos-hidden-mark />
            } @else {
              <div class="form-check form-switch mb-2">
                <input
                  id="appearance-switching-field"
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  aria-describedby="appearance-switching-hint"
                  data-id="appearance-switching-input"
                  data-autofocus
                  [checked]="switchingForm().enabled"
                  (change)="changeSwitching($event)"
                />
                <label
                  class="form-check-label"
                  for="appearance-switching-field"
                >
                  Let people choose a theme
                </label>
                <span
                  class="badge rounded-pill bg-body-tertiary text-body-emphasis border ms-1"
                  ><hilos-hideable [value]="row.defaultValue"
                    ><ng-template let-shown
                      >default: {{ valueLabel(shown) }}</ng-template
                    ></hilos-hideable
                  ></span
                >
              </div>
              <p
                id="appearance-switching-hint"
                class="small text-body-secondary mb-0"
              >
                When off, the theme icon in the header and the Theme row in the
                profile disappear for everyone, and everyone sees the default
                theme. People's choices are kept and come back when it is on
                again.
              </p>
            }
            <hilos-edit-notice
              [kind]="switchingState().notice?.kind ?? null"
              [text]="switchingNoticeText()"
              dataId="appearance-switching-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="switchingState().conflict"
            [disableSave]="!switchingCanSave()"
            [saveLabel]="switchingSaveLabel()"
            (save)="submitSwitching()"
            (acceptMine)="switchingEditor().keepMine()"
            (acceptTheirs)="switchingEditor().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="switchingAction.busy()"
                data-id="appearance-switching-cancel"
                (click)="requestClose()"
              >
                Cancel
              </button>
            </ng-template>
            <ng-template
              #saveButton
              let-saveDisabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="switchingAction.loading()"
                [disabled]="saveDisabled"
                data-id="appearance-switching-save"
                (click)="onSave()"
              >
                {{ switchingSaveLabel() }}
              </button>
            </ng-template>
          </div>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="defaultThemeOpen()"
        (openChange)="$event || defaultThemeEditor().close()"
        [aria-label]="'Edit · Default theme'"
        [confirmOnClose]="defaultThemeState().dirty"
      >
        <h5
          hilosConflictHeader
          modalHeader
          [title]="'Edit · Default theme'"
          [conflict]="defaultThemeState().conflict"
        ></h5>
        <hilos-action-error
          [action]="defaultThemeAction"
          detailsTitle="Couldn't save"
        />
        @if (defaultThemeRow(); as row) {
          <form hilosAppearanceForm (send)="submitDefaultTheme()">
            @if (defaultThemeHidden()) {
              <hilos-hidden-mark />
            } @else {
              <fieldset
                class="mb-2"
                aria-describedby="appearance-default-theme-hint"
              >
                <legend class="form-label fs-6 mb-2">Default theme</legend>
                @for (position of positions; track position.value) {
                  <div class="form-check mb-1">
                    <input
                      [id]="
                        'appearance-default-theme-' + position.value + '-field'
                      "
                      class="form-check-input"
                      type="radio"
                      name="appearance-default-theme"
                      [value]="position.value"
                      [attr.data-id]="
                        'appearance-default-theme-' + position.value
                      "
                      data-autofocus
                      [checked]="
                        defaultThemeForm().defaultTheme === position.value
                      "
                      (change)="changeDefaultTheme(position.value)"
                    />
                    <label
                      class="form-check-label d-inline-flex align-items-center gap-2"
                      [for]="
                        'appearance-default-theme-' + position.value + '-field'
                      "
                    >
                      <i [class]="'bi ' + position.icon" aria-hidden="true"></i>
                      {{ position.label }}
                      @if (position.value === row.defaultValue) {
                        <span
                          class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
                          >default</span
                        >
                      }
                    </label>
                  </div>
                }
              </fieldset>
              <p
                id="appearance-default-theme-hint"
                class="small text-body-secondary mb-0"
              >
                Everyone who has not chosen a theme switches at once, in every
                open tab. People who chose one keep it.
              </p>
            }
            <hilos-edit-notice
              [kind]="defaultThemeState().notice?.kind ?? null"
              [text]="defaultThemeNoticeText()"
              dataId="appearance-default-theme-notice"
            />
          </form>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <div
            hilosConflictActions
            [conflict]="defaultThemeState().conflict"
            [disableSave]="!defaultThemeCanSave()"
            [saveLabel]="defaultThemeSaveLabel()"
            (save)="submitDefaultTheme()"
            (acceptMine)="defaultThemeEditor().keepMine()"
            (acceptTheirs)="defaultThemeEditor().takeTheirs()"
          >
            <ng-template #cancelButton>
              <button
                type="button"
                class="btn btn-secondary"
                [disabled]="defaultThemeAction.busy()"
                data-id="appearance-default-theme-cancel"
                (click)="requestClose()"
              >
                Cancel
              </button>
            </ng-template>
            <ng-template
              #saveButton
              let-saveDisabled="disabled"
              let-onSave="onSave"
            >
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="defaultThemeAction.loading()"
                [disabled]="saveDisabled"
                data-id="appearance-default-theme-save"
                (click)="onSave()"
              >
                {{ defaultThemeSaveLabel() }}
              </button>
            </ng-template>
          </div>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosAppearancePage {
  /** The project context: connection, scope stores and the action lifecycle. */
  readonly context = input.required<HilosAppearanceContext>()

  protected readonly page = HilosPages.APPEARANCE
  protected readonly settingKey = HilosAppearanceSettingKey
  protected readonly positions = THEME_POSITIONS
  protected readonly keys = {
    rowKey: APPEARANCE_ROW_KEY_FIELD,
    value: APPEARANCE_VALUE_FIELD,
    defaultValue: APPEARANCE_DEFAULT_VALUE_FIELD,
    actions: HILOS_TABLE_ACTIONS_KEY,
  } as const

  protected readonly table = computed(() =>
    createHilosAppearanceTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosAppearanceActions(this.context()),
  )

  // Theme switching: the boolean, as the core window has it; this view binds
  // the switch and the text through mirrors of the session's signals.
  protected readonly switchingEditor = computed(() =>
    createHilosAppearanceSwitchingEdit(this.table().controller, this.actions()),
  )
  protected readonly switchingOpen = signal(false)
  protected readonly switchingRow = signal<HilosAppearanceSettingRow | null>(
    null,
  )
  protected readonly switchingForm = signal<HilosAppearanceSwitchingEditForm>({
    hidden: false,
    enabled: false,
  })
  protected readonly switchingState = signal(
    hilosRowEditIdle<HilosAppearanceSwitchingEditFields>({ enabled: false }),
  )
  protected readonly switchingNoticeText = signal('')
  protected readonly switchingSaveLabel = signal('Save')
  protected readonly switchingCanSave = signal(false)
  protected readonly switchingAction = createHilosTrackedAction()

  // Default theme: one of the three positions, never a fallback for a value
  // the row does not name.
  protected readonly defaultThemeEditor = computed(() =>
    createHilosAppearanceDefaultThemeEdit(
      this.table().controller,
      this.actions(),
    ),
  )
  protected readonly defaultThemeOpen = signal(false)
  protected readonly defaultThemeRow = signal<HilosAppearanceSettingRow | null>(
    null,
  )
  protected readonly defaultThemeForm =
    signal<HilosAppearanceDefaultThemeEditFields>({
      defaultTheme: HIDDEN_VALUE,
    })
  protected readonly defaultThemeState = signal(
    hilosRowEditIdle<HilosAppearanceDefaultThemeEditFields>({
      defaultTheme: HIDDEN_VALUE,
    }),
  )
  protected readonly defaultThemeNoticeText = signal('')
  protected readonly defaultThemeSaveLabel = signal('Save')
  protected readonly defaultThemeCanSave = signal(false)
  protected readonly defaultThemeAction = createHilosTrackedAction()
  protected readonly defaultThemeHidden = computed(() =>
    isHiddenValue(this.defaultThemeForm().defaultTheme),
  )

  constructor() {
    // Bind the table to the connection once the context input is bound and
    // unbind on destroy or a context swap; both windows listen for the merge's
    // steps over the same span.
    effect((onCleanup) => {
      const table = this.table()
      const switching = this.switchingEditor()
      const defaultTheme = this.defaultThemeEditor()
      table.start()
      switching.start()
      defaultTheme.start()
      const off = [
        mirrorHilosSignal(switching.opened, this.switchingOpen),
        mirrorHilosSignal(switching.row, this.switchingRow),
        mirrorHilosSignal(switching.form, this.switchingForm),
        mirrorHilosSignal(switching.state, this.switchingState),
        mirrorHilosSignal(switching.noticeText, this.switchingNoticeText),
        mirrorHilosSignal(switching.saveLabel, this.switchingSaveLabel),
        mirrorHilosSignal(switching.canSave, this.switchingCanSave),
        mirrorHilosSignal(defaultTheme.opened, this.defaultThemeOpen),
        mirrorHilosSignal(defaultTheme.row, this.defaultThemeRow),
        mirrorHilosSignal(defaultTheme.form, this.defaultThemeForm),
        mirrorHilosSignal(defaultTheme.state, this.defaultThemeState),
        mirrorHilosSignal(defaultTheme.noticeText, this.defaultThemeNoticeText),
        mirrorHilosSignal(defaultTheme.saveLabel, this.defaultThemeSaveLabel),
        mirrorHilosSignal(defaultTheme.canSave, this.defaultThemeCanSave),
      ]
      onCleanup(() => {
        for (const stop of off) stop()
        defaultTheme.dispose()
        switching.dispose()
        table.dispose()
      })
    })
  }

  /**
   * The words a setting key is named by on the screen.
   *
   * @param key The setting's stable key.
   */
  protected settingLabel(key: string): string {
    return key === HilosAppearanceSettingKey.switchingEnabled
      ? 'Theme switching'
      : key === HilosAppearanceSettingKey.defaultTheme
        ? 'Default theme'
        : key
  }

  /**
   * The words a setting value is shown by: On/Off for the switch, the
   * position's name for the default theme.
   *
   * @param value The value, already narrowed clear of the hidden mark.
   */
  protected valueLabel(value: boolean | string): string {
    if (typeof value === 'boolean') {
      return value ? 'On' : 'Off'
    }

    return (
      THEME_POSITIONS.find((position) => position.value === value)?.label ??
      value
    )
  }

  protected openSwitching(row: HilosAppearanceSettingRow): void {
    this.switchingAction.clearError()
    this.switchingEditor().open(row.rowKey)
  }

  protected changeSwitching(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
      this.switchingEditor().patchForm({ enabled: event.target.checked })
    }
  }

  // Save and Enter go through the window's one door: it refuses, closes an
  // unchanged draft, or dispatches the tracked action and closes on its reply.
  protected submitSwitching(): void {
    void this.switchingEditor().save(this.switchingAction.run)
  }

  protected openDefaultTheme(row: HilosAppearanceSettingRow): void {
    this.defaultThemeAction.clearError()
    this.defaultThemeEditor().open(row.rowKey)
  }

  protected changeDefaultTheme(position: ThemePosition): void {
    this.defaultThemeEditor().patchForm({ defaultTheme: position })
  }

  protected submitDefaultTheme(): void {
    void this.defaultThemeEditor().save(this.defaultThemeAction.run)
  }
}
