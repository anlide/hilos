// The core viewport owns the held window, filters, order and focused work.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusWorksState,
  createHilosStatusWorksTable,
  createHilosStatusWorkCreate,
  createHilosStatusWorkEdit,
  createHilosStatusWorkCancel,
  HILOS_STATUS_WORK_ACTION_COPY,
  STATUS_WORK_STARTS_AT_FIELD,
  STATUS_WORK_ENDS_AT_FIELD,
  STATUS_WORK_TITLE_FIELD,
  STATUS_WORK_COMPONENT_KEYS_FIELD,
  STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD,
  type HilosStatusContext,
  type HilosStatusWorksData,
} from '@hilos/core/admin/status'
import {
  HilosAdminPage,
  HilosViewportTable,
  HilosTableCell,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

import { HilosStatusWorkModal } from './HilosStatusWorkModal.js'
import { HilosStatusCancelWorkModal } from './HilosStatusCancelWorkModal.js'

/** Scheduled announcements over the standard Status viewport. */
@Component({
  selector: 'hilos-status-works-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosViewportTable,
    HilosTableCell,
    HilosStatusWorkModal,
    HilosStatusCancelWorkModal,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Status pages">
      <div class="hilos-stack mb-3">
        <p class="invisible mb-0" aria-hidden="true">{{ copy.unavailable }}</p>
        @if (data() === null) {
          <p
            class="text-body-secondary mb-0"
            data-id="hilos-status-works-unavailable"
          >
            {{ copy.unavailable }}
          </p>
        }
      </div>
      @if (data() !== null) {
        <hilos-viewport-table
          [controller]="model().table.controller"
          data-id="hilos-status-works-table"
        >
          <ng-template [hilosTableCell]="startsAtField" let-row>
            {{ moment(row.startsAt) }}
          </ng-template>
          <ng-template [hilosTableCell]="endsAtField" let-row>
            {{ moment(row.endsAt) }}
          </ng-template>
          <ng-template [hilosTableCell]="titleField" let-row>
            <span
              class="text-break"
              [attr.data-id]="'hilos-status-work-title-' + row.id"
              >{{ row.title }}</span
            >
            @if (row.description) {
              <p class="small text-body-secondary mb-0 text-break">
                {{ row.description }}
              </p>
            }
          </ng-template>
          <ng-template [hilosTableCell]="componentKeysField" let-row>
            {{ componentNames(row.componentKeys) }}
          </ng-template>
          <ng-template [hilosTableCell]="lastMailQueuedAtField" let-row>
            <span [attr.data-id]="'hilos-status-work-mail-' + row.id">{{
              moment(row.lastMailQueuedAt)
            }}</span>
          </ng-template>
          <ng-template
            [hilosTableCell]="actionsField"
            let-row
            let-rowKey="rowKey"
          >
            <div class="d-flex gap-2">
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                [attr.aria-label]="
                  'Change scheduled maintenance · ' + row.title
                "
                [attr.data-id]="'hilos-status-work-edit-' + row.id"
                (click)="openEdit(rowKey)"
              >
                <i class="bi bi-pencil" aria-hidden="true"></i>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                [attr.aria-label]="
                  'Cancel scheduled maintenance · ' + row.title
                "
                [attr.data-id]="'hilos-status-work-cancel-' + row.id"
                (click)="openCancel(rowKey)"
              >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
              </button>
            </div>
          </ng-template>
        </hilos-viewport-table>
      }
      <hilos-status-work-modal
        [controller]="model().create"
        [state]="model().state"
      />
      <hilos-status-work-modal
        [controller]="model().edit"
        [state]="model().state"
      />
      <hilos-status-cancel-work-modal [controller]="model().cancel" />
    </hilos-admin-page>
  `,
})
export class HilosStatusWorksPage {
  /** The project's existing scope, connection and action lifecycle. */
  readonly context = input.required<HilosStatusContext>()

  protected readonly page = HilosPages.STATUS_WORKS
  protected readonly copy = HILOS_STATUS_WORK_ACTION_COPY
  protected readonly startsAtField = STATUS_WORK_STARTS_AT_FIELD
  protected readonly endsAtField = STATUS_WORK_ENDS_AT_FIELD
  protected readonly titleField = STATUS_WORK_TITLE_FIELD
  protected readonly componentKeysField = STATUS_WORK_COMPONENT_KEYS_FIELD
  protected readonly lastMailQueuedAtField =
    STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD
  protected readonly actionsField = 'actions'
  protected readonly data = signal<HilosStatusWorksData>(null)
  private readonly timeZone = hilosSignal(hilosTimeZone)

  protected readonly model = computed(() => {
    const context = this.context()
    const state = createHilosStatusWorksState(context)
    const table = createHilosStatusWorksTable(context, {
      openSchedule: () => {
        if (!opened()) create.open()
      },
    })
    const create = createHilosStatusWorkCreate(context, state)
    const edit = createHilosStatusWorkEdit(context, table, state)
    const cancel = createHilosStatusWorkCancel(context, table)
    const opened = () =>
      create.opened.get() || edit.opened.get() || cancel.opened.get()
    return { state, table, create, edit, cancel, opened }
  })

  constructor() {
    effect((onCleanup) => {
      const { state, table, create, edit, cancel } = this.model()
      const stopData = mirrorHilosSignal(state.data, this.data)
      state.start()
      table.start()
      create.start()
      edit.start()
      cancel.start()
      onCleanup(() => {
        stopData()
        cancel.dispose()
        edit.dispose()
        create.dispose()
        table.dispose()
        state.dispose()
      })
    })
  }

  protected openEdit(rowKey: string): void {
    const model = this.model()
    if (!model.opened()) model.edit.open(rowKey)
  }

  protected openCancel(rowKey: string): void {
    const model = this.model()
    if (!model.opened()) model.cancel.open(rowKey)
  }

  protected componentNames(keys: readonly string[]): string {
    return keys
      .map(
        (key) =>
          this.data()?.components.find((item) => item.componentKey === key)
            ?.name ?? key,
      )
      .join(', ')
  }

  /** Display seconds in the live reader zone without changing the viewport. */
  protected moment(at: number | null): string {
    this.timeZone()
    if (at === null) return '—'
    return (
      formatMoment(at, {
        format: {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          timeZoneName: 'short',
        },
      }) ?? '—'
    )
  }
}
