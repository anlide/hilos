// The subscriber table and summary share the same server-owned page answer.
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
  createHilosStatusSubscribersState,
  createHilosStatusSubscribersTable,
  createHilosStatusSubscriberController,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  STATUS_SUBSCRIBER_EMAIL_FIELD,
  STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD,
  STATUS_SUBSCRIBER_CONFIRMED_FIELD,
  type HilosStatusContext,
  type HilosStatusSubscribersData,
} from '@hilos/core/admin/status'
import {
  HilosAdminPage,
  HilosHideable,
  HilosViewportTable,
  HilosTableCell,
  LoadingButton,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

import { HilosStatusRemoveSubscriberModal } from './HilosStatusRemoveSubscriberModal.js'

/** Subscriber addresses and confirmation state for the Status section. */
@Component({
  selector: 'hilos-status-subscribers-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosViewportTable,
    HilosTableCell,
    HilosHideable,
    LoadingButton,
    HilosStatusRemoveSubscriberModal,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Status pages">
      <dl
        class="d-flex flex-wrap gap-3 mb-3"
        data-id="hilos-status-subscriber-counts"
      >
        <div>
          <dt>{{ copy.confirmed }}</dt>
          <dd class="mb-0" data-id="hilos-status-subscribers-confirmed">
            {{ counts()?.confirmedCount ?? '—' }}
          </dd>
        </div>
        <div>
          <dt>{{ copy.awaitingConfirmation }}</dt>
          <dd class="mb-0" data-id="hilos-status-subscribers-pending">
            {{ counts()?.pendingCount ?? '—' }}
          </dd>
        </div>
      </dl>
      <div class="hilos-stack mb-3">
        <p class="invisible mb-0" aria-hidden="true">
          {{ copy.unavailable }}
        </p>
        @if (counts() === null) {
          <p
            class="text-body-secondary mb-0"
            data-id="hilos-status-subscribers-unavailable"
          >
            {{ copy.unavailable }}
          </p>
        }
      </div>
      <hilos-viewport-table
        [controller]="table().controller"
        data-id="hilos-status-subscribers-table"
      >
        <ng-template [hilosTableCell]="emailField" let-row>
          <span
            class="text-break"
            [attr.data-id]="'hilos-status-subscriber-email-' + row.id"
            ><hilos-hideable [value]="row.email"
          /></span>
        </ng-template>
        <ng-template [hilosTableCell]="subscribedAtField" let-row>
          {{ moment(row.subscribedAt) }}
        </ng-template>
        <ng-template [hilosTableCell]="confirmedField" let-row>
          <span
            [class]="row.confirmed ? 'text-success' : 'text-body-secondary'"
            [attr.data-id]="'hilos-status-subscriber-confirmed-' + row.id"
          >
            {{ row.confirmed ? copy.yes : copy.awaitingConfirmation }}
          </span>
        </ng-template>
        <ng-template
          [hilosTableCell]="actionsField"
          let-row
          let-rowKey="rowKey"
        >
          <button
            hilosLoadingButton
            type="button"
            class="btn-outline-danger btn-sm"
            [disabled]="personalControlsReason() !== null"
            [aria-label]="copy.remove"
            [attr.data-id]="'hilos-status-subscriber-remove-' + row.id"
            (click)="controller().open(rowKey)"
          >
            <i class="bi bi-trash" aria-hidden="true"></i>
          </button>
        </ng-template>
      </hilos-viewport-table>
      <hilos-status-remove-subscriber-modal [controller]="controller()" />
    </hilos-admin-page>
  `,
})
export class HilosStatusSubscribersPage {
  /** The project's existing scope, connection and action lifecycle. */
  readonly context = input.required<HilosStatusContext>()

  protected readonly page = HilosPages.STATUS_SUBSCRIBERS
  protected readonly copy = HILOS_STATUS_SUBSCRIBERS_COPY
  protected readonly emailField = STATUS_SUBSCRIBER_EMAIL_FIELD
  protected readonly subscribedAtField = STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD
  protected readonly confirmedField = STATUS_SUBSCRIBER_CONFIRMED_FIELD
  protected readonly actionsField = 'actions'
  protected readonly counts = signal<HilosStatusSubscribersData>(null)
  protected readonly personalControlsReason = signal<string | null>(null)

  private readonly timeZone = hilosSignal(hilosTimeZone)
  private readonly state = computed(() =>
    createHilosStatusSubscribersState(this.context()),
  )
  protected readonly table = computed(() =>
    createHilosStatusSubscribersTable(this.context()),
  )
  protected readonly controller = computed(() =>
    createHilosStatusSubscriberController(this.context(), this.table()),
  )

  constructor() {
    effect((onCleanup) => {
      const state = this.state()
      const table = this.table()
      const controller = this.controller()
      const stopCounts = mirrorHilosSignal(state.counts, this.counts)
      const stopPersonalControlsReason = mirrorHilosSignal(
        table.personalControlsReason,
        this.personalControlsReason,
      )
      table.start()
      state.start()
      controller.start()
      onCleanup(() => {
        stopPersonalControlsReason()
        stopCounts()
        controller.dispose()
        state.dispose()
        table.dispose()
      })
    })
  }

  /** Format the subscription instant in the reader's current zone. */
  protected moment(at: number): string {
    this.timeZone()
    return formatMoment(at) ?? '—'
  }
}
