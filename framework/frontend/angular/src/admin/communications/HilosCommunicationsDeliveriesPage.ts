// HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
// (HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
// inside the admin shell. The journal is served straight from SQL (an unbounded
// table), so it has no live per-row deltas — a status / period filter or a retry
// re-requests the window. The per-channel route ({channelId}) opens the otherwise
// cross-cutting journal with a channel preset; the status picker, the period range,
// and the type/recipient search are what the table declares about its frame, drawn
// by the framework's bar, and ride the open viewport filter map (server-side, no
// local filtering). The single row action is retry, shown only on a failed delivery:
// it re-queues the delivery as a tracked action (createHilosDeliveriesActions) and
// refreshes the window. All table logic, the row view-model, and that declaration
// are the core headless's (hilosDeliveries); this view owns only the markup, so a
// project mounts it by passing its HilosDeliveriesContext. Bootstrap classes only
// (styling-rules.md).
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
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  isDeliveryRetryable,
} from '@hilos/core'
import type { HilosDeliveriesContext, HilosDeliveryRow } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosTableDetail } from '../../HilosTableDetail.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal } from '../../hilosSignal.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

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
  imports: [
    HilosAdminPage,
    HilosTableCell,
    HilosTableDetail,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <!-- The channel the route opened the journal on is the table's preset, not a
      filter of its bar: it is named here, above the table, and nothing on the page
      offers to take it off. -->
      @if (channel()) {
        <div class="mb-3">
          <span class="form-label d-block">Channel</span>
          <span
            class="badge text-bg-secondary-subtle text-secondary-emphasis fs-6"
          >
            <code>{{ channel() }}</code>
          </span>
        </div>
      }

      <hilos-viewport-table [controller]="deliveries().controller">
        <ng-template hilosTableCell="createdAt" let-row>{{
          row.createdAt || '—'
        }}</ng-template>
        <ng-template hilosTableCell="channel" let-row>
          <code>{{ row.channel || '—' }}</code>
        </ng-template>
        <ng-template hilosTableCell="status" let-row>
          <span class="badge" [class]="statusClass(row.status)">{{
            row.status || '—'
          }}</span>
        </ng-template>
        <ng-template hilosTableCell="attempts" let-row>{{
          row.attempts
        }}</ng-template>
        <ng-template hilosTableCell="deliveredAt" let-row>{{
          row.deliveredAt || '—'
        }}</ng-template>
        <ng-template hilosTableCell="userLabel" let-row>{{
          recipientLabel(row)
        }}</ng-template>
        <ng-template hilosTableDetail="notificationTitle" let-row>
          <div class="fw-semibold">{{ row.notificationTitle || '—' }}</div>
          <code class="small text-body-secondary">{{
            row.notificationType
          }}</code>
        </ng-template>
        <ng-template hilosTableDetail="lastError" let-row>{{
          row.lastError || '—'
        }}</ng-template>
        <ng-template hilosTableCell="actions" let-row>
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
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosCommunicationsDeliveriesPage {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  readonly context = input.required<HilosDeliveriesContext>()

  protected readonly page = HilosPages.COMMUNICATIONS_DELIVERIES
  protected readonly isRetryable = isDeliveryRetryable

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  // The per-channel route ({channelId}) opens the journal with a channel preset; a
  // router-less mount (none in practice) shows the cross-cutting journal instead.
  protected readonly channel = hilosSignal(
    computedSignal(
      () =>
        (this.router?.currentRoute.get().params['channelId'] as
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
      this.deliveries().controller.refresh()
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
