// HilosCommunicationsChannelPage — the framework Hilos channel-config page
// (HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
// the admin shell. The route {channelId} names the channel; the fields table is
// global (one row per field of every channel), so the core headless presets the
// channel in the table's filter map and the server narrows the window to it — no
// filter is applied on the client (createHilosChannelFields). The table is the
// shared server-windowed one, drawn from what it declares about its frame (its
// columns and empty words); this view owns only the cells of a row. Each editable
// field shows its effective value and source and can be overridden (edit, in a
// modal) or reset to its env/default; a secret is shown as set/not-set and never
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
  untracked,
} from '@angular/core'
import {
  HilosPages,
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  findLiveRow,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  subscribeSignal,
  takeTheirsRowEdit,
} from '@hilos/core'
import type {
  ChannelValueSource,
  HilosChannelFieldRow,
  HilosCommunicationsContext,
  RowEditBaseline,
  RowEditNoticeKind,
  RowEditStep,
  TableViewportRow,
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
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal } from '../../hilosSignal.js'
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

/** The one field the dialog edits: the field's typed value. */
interface ChannelEditFields {
  value: boolean | number | string | null
}

/** Human-readable effective value of a non-secret field. */
function displayValue(row: HilosChannelFieldRow): string {
  if (typeof row.value === 'boolean') {
    return row.value ? 'On' : 'Off'
  }

  return row.value === null || row.value === '' ? '—' : String(row.value)
}

/**
 * The text the dialog's input shows for a typed value: a switch reads '1' /
 * '0', an empty value reads as nothing, anything else as itself.
 */
function formText(
  type: string,
  value: boolean | number | string | null,
): string {
  if (type === 'boolean') {
    return value === true ? '1' : '0'
  }

  return value === null ? '' : String(value)
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosChannelFieldRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow ? `Changed elsewhere to "${displayValue(liveRow)}".` : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
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
            <span>{{ displayValue(row) }}</span>
          }
        </ng-template>
        <ng-template hilosTableCell="valueSource" let-row>
          <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
            {{ sourceLabel(row) }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (row.editable) {
            <div class="d-flex gap-1 justify-content-end">
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
                [disabled]="row.valueSource !== 'settings' || reset.busy()"
                [attr.data-id]="'hilos-channel-field-reset-' + row.field"
                (click)="resetField(row)"
              >
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
              </button>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="editOpen()"
        (openChange)="editOpen.set($event)"
        [confirmOnClose]="live().dirty"
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
            @if (editInputType() === 'checkbox') {
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
            [disableSave]="!live().dirty || edit.busy() || live().gone"
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
                data-id="hilos-channel-edit-save"
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
  protected readonly reset = createHilosTrackedAction()
  protected readonly edit = createHilosTrackedAction()

  // Edit dialog: one field's override value.
  protected readonly editOpen = signal(false)
  protected readonly editRow = signal<HilosChannelFieldRow | null>(null)
  protected readonly editBaseline = signal<RowEditBaseline<ChannelEditFields>>(
    openRowEdit<ChannelEditFields>({ value: null }),
  )
  protected readonly editValue = signal('')
  protected readonly viewportRows = signal<
    readonly TableViewportRow<HilosChannelFieldRow>[]
  >([])
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
  // The live row the dialog edits, projected onto its one field; gone once the
  // table no longer has it.
  private readonly liveRow = computed(() =>
    findLiveRow(this.viewportRows(), this.editRow()?.key ?? ''),
  )
  protected readonly live = computed(() => {
    const row = this.liveRow()
    const editRow = this.editRow()

    return resolveRowEdit(
      row ? { value: row.value } : undefined,
      this.editBaseline(),
      { value: editRow ? this.editedValue(editRow) : null },
    )
  })
  protected readonly editNotice = computed(
    () => this.live().notice?.kind ?? null,
  )
  protected readonly editNoticeText = computed(() =>
    noticeText(this.editNotice(), this.liveRow()),
  )
  protected readonly editSaveLabel = computed(() =>
    this.live().gone ? 'Deleted' : 'Save',
  )

  constructor() {
    // Bind the fields table to the connection and request its window once the
    // context input is bound; unbind on destroy / swap. Mirror the live rows the
    // same way the viewport table does: the controller arrives through a
    // computed, so hilosSignal cannot take it at field init.
    effect((onCleanup) => {
      const fields = this.fields()
      fields.start()
      this.viewportRows.set(fields.controller.rows.get())
      const unsubscribe = subscribeSignal(fields.controller.rows, (rows) =>
        this.viewportRows.set(rows),
      )
      onCleanup(() => {
        unsubscribe()
        fields.dispose()
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

  protected sendTest(): void {
    void this.test.run(this.actions().sendChannelTest(this.channel()))
  }

  protected resetField(row: HilosChannelFieldRow): void {
    void this.reset.run(this.actions().sendChannelReset(row.channel, row.field))
  }

  protected openEdit(row: HilosChannelFieldRow): void {
    // Flush pending so the dialog edits the latest committed row; a row removed
    // by someone else (now a placeholder) declines to open.
    const fresh = this.fields().controller.applyAndResolve(row.key)
    if (!fresh) {
      return
    }
    this.edit.clearError()
    this.editRow.set(fresh)
    this.editValue.set(formText(fresh.type, fresh.value))
    this.editBaseline.set(
      openRowEdit<ChannelEditFields>({ value: fresh.value }),
    )
    this.editOpen.set(true)
  }

  // Put a step of the helper into the dialog: the snapshot moves, and a value
  // the step takes lands in the input the way the dialog opened with it.
  private applyStep(step: RowEditStep<ChannelEditFields>): void {
    const row = this.editRow()
    if (!row) {
      return
    }
    this.editBaseline.set(step.baseline)
    const taken = step.take.value
    if (taken !== undefined) {
      this.editValue.set(formText(row.type, taken))
    }
  }

  protected acceptMine(): void {
    this.editBaseline.set(keepMineRowEdit(this.live(), this.editBaseline()))
  }

  protected acceptTheirs(): void {
    this.applyStep(takeTheirsRowEdit(this.live(), this.editBaseline()))
  }

  protected async submitEdit(event?: Event): Promise<void> {
    event?.preventDefault()
    const row = this.editRow()
    if (!row || this.edit.busy() || this.live().gone) {
      return
    }
    if (!this.live().dirty) {
      this.editOpen.set(false)

      return
    }
    if (
      await this.edit.run(
        this.actions().sendChannelSet(
          row.channel,
          row.field,
          this.editedValue(row),
        ),
      )
    ) {
      this.editOpen.set(false)
    }
  }

  protected onValueCheckbox(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).checked ? '1' : '0')
  }

  protected onValueInput(event: Event): void {
    this.editValue.set((event.target as HTMLInputElement).value)
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

  /** Human-readable effective value of a non-secret field. */
  protected displayValue(row: HilosChannelFieldRow): string {
    return displayValue(row)
  }

  /** Coerce the edited string to the field's typed value for the set action. */
  private editedValue(row: HilosChannelFieldRow): boolean | number | string {
    if (row.type === 'boolean') {
      return this.editValue() === '1'
    }
    if (row.type === 'integer' || row.type === 'float') {
      return Number(this.editValue())
    }

    return this.editValue()
  }
}
