// HilosCommunicationsChannelPage — the framework Hilos channel-config page
// (HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
// the admin shell. The route {channelId} names the channel; the fields table is
// global (one row per field of every channel), so the core headless presets the
// channel in the table's filter map and the server narrows the window to it — no
// filter is applied on the client (createHilosChannelFields). The table is the
// shared server-windowed one, drawn from what it declares about its frame (its
// columns and empty words); this view owns only the cells of a row. Each editable
// field shows its effective value and source and can be overridden (edit, in a
// modal) or reset to its env/default — the ↺ asks first, in a confirm dialog
// built like the settings orphan delete; a secret is shown as set/not-set and never
// editable. A "Send test notification" button above the table exercises the real
// delivery path (HIL-201). Writes are tracked actions (createHilosCommunicationsActions):
// the value redraws from the reactive table's snapshot signal after the backend
// echo, never optimistically, and a validation failure surfaces as a toast with the
// backend's domain phrase. Editing happens in a modal — inline forms are forbidden
// (rules-and-violations.md section E) — and the modal merges against the live row
// through the shared row-edit helper (rowEdit.ts, conflict-resolution.md), saying
// what happened elsewhere on one line of room held in advance (HilosEditNotice).
// Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HilosPages,
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  createHilosChannelFieldEdit,
  hilosChannelDisplayValue,
  hilosRowEditIdle,
} from '@hilos/core'
import type {
  HilosChannelFieldEditFields,
  HilosChannelFieldEditForm,
  ChannelValueSource,
  HilosChannelFieldRow,
  HilosCommunicationsContext,
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
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** Map a field type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<ChannelValueSource, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

/** The framework channel-config page: one channel's config-fields table. */
@Component({
  selector: 'hilos-communications-channel-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosModal,
    HilosActionError,
    HilosEditNotice,
    HilosHiddenMark,
    HilosHideable,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
    ConflictActions,
    ConflictHeader,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="mb-0 text-body-secondary">
          Channel <code>{{ channel() }}</code>
        </p>
        <button
          hilosLoadingButton
          class="btn-outline-primary btn-sm"
          [loading]="test.loading()"
          [disabled]="test.busy()"
          data-id="hilos-channel-test"
          (click)="sendTest()"
        >
          Send test notification
        </button>
      </div>

      <hilos-viewport-table [controller]="fields().controller">
        <ng-template hilosTableCell="field" let-row>
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.field }}</code>
        </ng-template>
        <ng-template hilosTableCell="value" let-row>
          @if (row.secret) {
            <span class="text-body-secondary fst-italic">
              {{ row.valueSource === 'env' ? 'Set in env' : 'Not set' }}
            </span>
          } @else {
            <hilos-hideable [value]="row.value">
              <ng-template let-value>
                <span>{{ displayValue(value) }}</span>
              </ng-template>
            </hilos-hideable>
          }
        </ng-template>
        <ng-template hilosTableCell="valueSource" let-row>
          <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
            {{ sourceLabel(row) }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (row.editable) {
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label="Edit"
              [attr.data-id]="'hilos-channel-field-edit-' + row.field"
              (click)="openEdit(row)"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              title="Reset to env/default"
              aria-label="Reset to env/default"
              [disabled]="row.valueSource !== 'settings'"
              [attr.data-id]="'hilos-channel-field-reset-' + row.field"
              (click)="openReset(row)"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          }
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="$event ? undefined : closeEdit()"
        [confirmOnClose]="live().dirty"
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
              <div class="form-label">{{ row.label }}</div>
              <hilos-hidden-mark />
            } @else if (editInputType() === 'checkbox') {
              <div class="form-check form-switch">
                <input
                  id="hilos-channel-edit-value"
                  type="checkbox"
                  class="form-check-input"
                  role="switch"
                  data-id="hilos-channel-edit-value"
                  data-autofocus
                  [checked]="editValue() === '1'"
                  (change)="onValueCheckbox($event)"
                />
                <label class="form-check-label" for="hilos-channel-edit-value">
                  {{ row.label }}
                </label>
              </div>
            } @else {
              <label class="form-label" for="hilos-channel-edit-value">{{
                row.label
              }}</label>
              <input
                id="hilos-channel-edit-value"
                [type]="editInputType()"
                [attr.step]="editStep()"
                class="form-control"
                data-id="hilos-channel-edit-value"
                data-autofocus
                [value]="editValue()"
                (input)="onValueInput($event)"
              />
            }
            <hilos-edit-notice
              [kind]="editNotice()"
              [text]="editNoticeText()"
              dataId="hilos-channel-edit-notice"
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
                data-id="hilos-channel-edit-save"
                (click)="onSave()"
              >
                {{ editSaveLabel() }}
              </button>
            </ng-template>
          </div>
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
          detailsTitle="Couldn't reset the field"
        />
        @if (resetShown(); as row) {
          <dl class="row mb-0">
            <dt class="col-4">Now</dt>
            <dd class="col-8" data-id="hilos-channel-reset-now">
              <hilos-hideable [value]="row.value">
                <ng-template let-value>{{ displayValue(value) }}</ng-template>
              </hilos-hideable>
            </dd>
            <dt class="col-4">Back to</dt>
            <dd class="col-8" data-id="hilos-channel-reset-default">
              the env value, or the default when env has none
            </dd>
          </dl>
        }
        @if (resetGone()) {
          <p
            class="mb-0 mt-2 text-body-secondary"
            data-id="hilos-channel-reset-gone"
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
            data-id="hilos-channel-reset-confirm"
            (click)="submitReset()"
          >
            Reset
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsChannelPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosCommunicationsContext>()

  protected readonly page = HilosPages.COMMUNICATIONS_CHANNEL

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The route channel, as a core signal the fields table follows: navigating to
  // another channel sets the table's channel filter again and asks for its window.
  private readonly channelSignal = computedSignal(() => {
    if (!this.router) {
      throw new Error(
        'HilosCommunicationsChannelPage requires a provided router: { provide: HILOS_ROUTER, useValue: router }.',
      )
    }

    return (
      (this.router.currentRoute.get().params['channelId'] as
        | string
        | undefined) ?? ''
    )
  })
  protected readonly channel = hilosSignal(this.channelSignal)

  protected readonly fields = computed(() =>
    createHilosChannelFields(this.context(), this.channelSignal),
  )
  private readonly actions = computed(() =>
    createHilosCommunicationsActions(this.context()),
  )

  // Showing the backend's own phrase on a rejected write is the driver's default
  // since HIL-779; this page used to be the one screen that asked for it.
  protected readonly test = createHilosTrackedAction()
  protected readonly edit = createHilosTrackedAction()

  // Edit dialog: one field's override value, as the core window has it; this
  // view binds the input through mirrors of the session's signals.
  protected readonly editor = computed(() =>
    createHilosChannelFieldEdit(this.fields().controller, this.actions()),
  )
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosChannelFieldRow | null>(null)
  protected readonly editForm = signal<HilosChannelFieldEditForm>({
    hidden: false,
    text: '',
  })
  protected readonly live = signal(
    hilosRowEditIdle<HilosChannelFieldEditFields>({ value: null }),
  )
  protected readonly editNoticeText = signal('')
  protected readonly editSaveLabel = signal('Save')
  protected readonly canSave = signal(false)
  protected readonly editHidden = computed(() => this.editForm().hidden)
  protected readonly editValue = computed(() => this.editForm().text)
  // The live row the open reset dialog is about: the row the table holds in
  // focus, which the server follows wherever it goes; undefined once the row is
  // gone. Mirrored the way the viewport table mirrors its rows.
  protected readonly liveRow = signal<HilosChannelFieldRow | undefined>(
    undefined,
  )
  protected readonly editInputType = computed(() =>
    inputType(this.editRow()?.type),
  )
  protected readonly editStep = computed(() =>
    this.editRow()?.type === 'float' ? 'any' : undefined,
  )
  protected readonly editTitle = computed(() => {
    const row = this.editRow()

    return row ? `Edit · ${row.label}` : 'Edit field'
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )

  // Reset dialog: back to env/default, only on confirm. It reads the same live
  // row the edit dialog does — one dialog is open at a time, and the focus is one.
  protected readonly resetOpen = signal(false)
  protected readonly resetRow = signal<HilosChannelFieldRow | null>(null)
  protected readonly reset = createHilosTrackedAction()
  protected readonly resetShown = computed(
    () => this.liveRow() ?? this.resetRow(),
  )
  protected readonly resetGone = computed(() => {
    const row = this.liveRow()

    return row === undefined || row.valueSource !== 'settings'
  })
  protected readonly resetTitle = computed(() => {
    const row = this.resetRow()

    return row ? `Reset · ${row.label}` : 'Reset field'
  })

  constructor() {
    // Bind the fields table to the connection and request its window once the
    // context input is bound; unbind on destroy / swap. Mirror the row in focus
    // the same way the viewport table mirrors its rows: the controller arrives
    // through a computed, so hilosSignal cannot take it at field init.
    effect((onCleanup) => {
      const fields = this.fields()
      const editor = this.editor()
      fields.start()
      editor.start()
      const off = [
        mirrorHilosSignal(fields.controller.focusedRow, this.liveRow),
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
        fields.dispose()
      })
    })
  }

  protected sendTest(): void {
    void this.test.run(this.actions().sendChannelTest(this.channel()))
  }

  protected openEdit(row: HilosChannelFieldRow): void {
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

  protected openReset(row: HilosChannelFieldRow): void {
    // Flush pending and take the row into focus; a row already removed by someone
    // else does not open a reset.
    const fresh = this.fields().controller.focusRow(row.key)
    if (!fresh) {
      return
    }
    this.reset.clearError()
    this.resetRow.set(fresh)
    this.resetOpen.set(true)
  }

  protected closeReset(): void {
    this.resetOpen.set(false)
    this.fields().controller.releaseFocus()
  }

  protected async submitReset(): Promise<void> {
    const row = this.resetRow()
    if (!row || this.reset.busy() || this.resetGone()) {
      return
    }
    if (
      await this.reset.run(
        this.actions().sendChannelReset(row.channel, row.field),
      )
    ) {
      this.closeReset()
    }
  }

  protected onValueCheckbox(event: Event): void {
    this.editor().patchForm({
      text: (event.target as HTMLInputElement).checked ? '1' : '0',
    })
  }

  protected onValueInput(event: Event): void {
    this.editor().patchForm({ text: (event.target as HTMLInputElement).value })
  }

  /**
   * The words for where a field's value comes from, read through a typed method
   * because the row a projected template hands over carries no type of its own.
   *
   * @param row The field row.
   * @returns The source label.
   */
  protected sourceLabel(row: HilosChannelFieldRow): string {
    return SOURCE_LABEL[row.valueSource]
  }

  /**
   * Human-readable effective value of a non-secret field that is not hidden.
   *
   * @param value The field's effective value.
   * @returns The text the screen shows for it.
   */
  protected displayValue(value: boolean | number | string | null): string {
    return hilosChannelDisplayValue(value)
  }
}
