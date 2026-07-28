// HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
// (HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
// inside the admin shell. The journal is served straight from SQL (an unbounded
// table), so it has no live per-row deltas — a status / period filter or a retry
// re-requests the window. The per-channel route ({channelId}) opens the otherwise
// cross-cutting journal with a channel preset; the status picker, the period range,
// and the type/recipient search ride the open viewport filter map (server-side, no
// local filtering). The single row action is retry, shown only on a failed delivery:
// it re-queues the delivery as a tracked action (createHilosDeliveriesActions) and
// refreshes the window. All table logic and the row view-model are the core
// headless's (hilosDeliveries); this view owns only the markup, so a project mounts
// it by passing its HilosDeliveriesContext. Bootstrap classes only (styling-rules.md).
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
  DELIVERY_FILTER_FROM,
  DELIVERY_FILTER_STATUS,
  DELIVERY_FILTER_TO,
  HILOS_DELIVERY_STATUSES,
  HilosPages,
  computedSignal,
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  isDeliveryRetryable,
} from '@hilos/core'
import type {
  HilosDeliveriesContext,
  HilosDeliveryRow,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

const COLUMNS: HilosTableColumn[] = [
  { key: 'createdAt', label: 'Date', sortable: true },
  { key: 'channel', label: 'Channel', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  {
    key: 'attempts',
    label: 'Attempts',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'deliveredAt', label: 'Delivered', sortable: true },
  { key: 'recipient', label: 'Recipient' },
  { key: 'notification', label: 'Notification' },
  { key: 'lastError', label: 'Error' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

// The status contextual badge: failed is danger, sent is success, the rest neutral.
const STATUS_CLASS: Record<string, string> = {
  failed: 'text-bg-danger',
  sent: 'text-bg-success',
  pending: 'text-bg-secondary',
}

/** The framework delivery-logs admin page: the filterable journal with per-row retry. */
@Component({
  selector: 'hilos-communications-deliveries-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, LoadingButton],
  template: `
    <hilos-admin-page [page]="page">
      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        @if (channel()) {
          <div>
            <span class="form-label d-block">Channel</span>
            <span
              class="badge text-bg-secondary-subtle text-secondary-emphasis fs-6"
            >
              <code>{{ channel() }}</code>
            </span>
          </div>
        }
        <div>
          <label class="form-label" for="hilos-delivery-status">Status</label>
          <select
            id="hilos-delivery-status"
            class="form-select"
            data-id="hilos-delivery-status"
            [value]="statusFilter()"
            (change)="onStatus($event)"
          >
            @for (option of statuses; track option.value) {
              <option [value]="option.value">{{ option.label }}</option>
            }
          </select>
        </div>
        <div>
          <label class="form-label" for="hilos-delivery-from">From</label>
          <input
            id="hilos-delivery-from"
            type="date"
            class="form-control"
            data-id="hilos-delivery-from"
            [value]="fromFilter()"
            (change)="onFrom($event)"
          />
        </div>
        <div>
          <label class="form-label" for="hilos-delivery-to">To</label>
          <input
            id="hilos-delivery-to"
            type="date"
            class="form-control"
            data-id="hilos-delivery-to"
            [value]="toFilter()"
            (change)="onTo($event)"
          />
        </div>
      </div>

      <hilos-viewport-table
        label="Deliveries"
        [controller]="deliveries().controller"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search type or recipient…"
        emptyText="No deliveries match."
      >
        <ng-template #row let-row>
          <td class="text-nowrap">{{ row.createdAt || '—' }}</td>
          <td>
            <code>{{ row.channel || '—' }}</code>
          </td>
          <td>
            <span class="badge" [class]="statusClass(row.status)">{{
              row.status || '—'
            }}</span>
          </td>
          <td class="text-end">{{ row.attempts }}</td>
          <td class="text-nowrap">{{ row.deliveredAt || '—' }}</td>
          <td>{{ recipientLabel(row) }}</td>
          <td>
            <div class="fw-semibold">{{ row.notificationTitle || '—' }}</div>
            <code class="small text-body-secondary">{{
              row.notificationType
            }}</code>
          </td>
          <td class="text-body-secondary">{{ row.lastError || '—' }}</td>
          <td class="text-end">
            @if (isRetryable(row)) {
              <button
                hilosLoadingButton
                class="btn-outline-primary btn-sm"
                [loading]="retry.busy() && retryPendingId() === row.rowKey"
                [disabled]="retry.busy()"
                [attr.data-id]="'hilos-delivery-retry-' + row.rowKey"
                (click)="doRetry(row)"
              >
                Retry
              </button>
            }
          </td>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsDeliveriesPage {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  readonly context = input.required<HilosDeliveriesContext>()

  protected readonly page = HilosPages.COMMUNICATIONS_DELIVERIES
  protected readonly columns = COLUMNS
  protected readonly statuses = HILOS_DELIVERY_STATUSES
  protected readonly isRetryable = isDeliveryRetryable

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The per-channel route ({channelId}) opens the journal with a channel preset; a
  // router-less mount (none in practice) shows the cross-cutting journal instead.
  protected readonly channel = hilosSignal(
    computedSignal(
      () =>
        (this.router?.currentRoute.get().params.channelId as
          | string
          | undefined) ?? '',
    ),
  )

  protected readonly deliveries = computed(() =>
    createHilosDeliveriesTable(
      this.context(),
      this.channel() ? { channel: this.channel() } : undefined,
    ),
  )
  private readonly actions = computed(() =>
    createHilosDeliveriesActions(this.context()),
  )

  // Domain filters: status and the created_at period ride the open filter map so
  // the backend narrows the window (no local filtering). Empty clears the filter.
  protected readonly statusFilter = signal('')
  protected readonly fromFilter = signal('')
  protected readonly toFilter = signal('')

  // Retry: a per-row tracked action on a failed delivery.
  protected readonly retry = createHilosTrackedAction()
  protected readonly retryPendingId = signal<string | null>(null)

  constructor() {
    // Bind the server-windowed table to the connection and request the first window
    // once the context input is bound; unbind on destroy, context swap, or channel change.
    effect((onCleanup) => {
      const deliveries = this.deliveries()
      deliveries.start()
      onCleanup(() => deliveries.dispose())
    })
  }

  protected onStatus(event: Event): void {
    const value = (event.target as HTMLSelectElement).value
    this.statusFilter.set(value)
    this.deliveries().controller.setFilter(DELIVERY_FILTER_STATUS, value)
  }

  protected onFrom(event: Event): void {
    const value = (event.target as HTMLInputElement).value
    this.fromFilter.set(value)
    this.deliveries().controller.setFilter(DELIVERY_FILTER_FROM, value)
  }

  protected onTo(event: Event): void {
    const value = (event.target as HTMLInputElement).value
    this.toFilter.set(value)
    this.deliveries().controller.setFilter(DELIVERY_FILTER_TO, value)
  }

  // On success, re-request the window — the journal has no live deltas, so the
  // re-queued row only shows after a refresh.
  protected async doRetry(row: HilosDeliveryRow): Promise<void> {
    if (this.retry.busy()) {
      return
    }
    this.retryPendingId.set(row.rowKey)
    if (
      await this.retry.run(this.actions().sendDeliveryRetry(Number(row.rowKey)))
    ) {
      this.deliveries().controller.start()
    }
    this.retryPendingId.set(null)
  }

  /** The status badge contextual class. */
  protected statusClass(status: string): string {
    return STATUS_CLASS[status] ?? 'text-bg-secondary'
  }

  /** The recipient label: the resolved display name, its user id, both, or a dash. */
  protected recipientLabel(row: HilosDeliveryRow): string {
    if (row.userId === null) {
      return '—'
    }
    const id = `#${row.userId}`

    return row.userLabel ? `${row.userLabel} (${id})` : id
  }
}
