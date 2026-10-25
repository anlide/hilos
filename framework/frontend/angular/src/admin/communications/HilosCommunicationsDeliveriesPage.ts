// HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
// (HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
// inside the admin shell. The journal is served straight from SQL (an unbounded
// table) and its rows arrive live: a status / period filter re-requests the
// window, and a new delivery or a status change reaches it as a delta. The
// per-channel route ({channelId}) opens the otherwise cross-cutting journal with
// a channel preset; the status picker, the period range, and the type/recipient
// search are what the table declares about its frame, drawn by the framework's
// bar, and ride the open viewport filter map (server-side, no local filtering).
// The single row action is retry, shown only on a failed delivery: it re-queues
// the delivery as a tracked action (createHilosDeliveriesActions), and the
// re-queued row arrives live. All table logic, the row view-model, and that
// declaration are the core headless's (hilosDeliveries); this view owns only the
// markup, so a project mounts it by passing its HilosDeliveriesContext. Bootstrap
// classes only (styling-rules.md).
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
  bindPageInstanceName,
  computedSignal,
  isHiddenValue,
} from '@hilos/core/base'
import {
  createHilosChannelSummary,
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  isDeliveryRetryable,
} from '@hilos/core/admin/communications'
import type {
  HilosChannelSummary,
  HilosDeliveriesContext,
  HilosDeliveryRow,
} from '@hilos/core/admin/communications'

import {
  HilosAdminPage,
  HilosHiddenMark,
  HilosHideable,
  HilosTableCell,
  HilosTableDetail,
  HilosViewportTable,
  LoadingButton,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
  createHilosTrackedAction,
} from '@hilos/angular/base'
import { HilosCommunicationsChannelHead } from './HilosCommunicationsChannelHead.js'

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
    HilosCommunicationsChannelHead,
    HilosHiddenMark,
    HilosHideable,
    HilosTableCell,
    HilosTableDetail,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="entity" tabsLabel="Channel pages">
      <ng-template #headVisual>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="visual"
        />
      </ng-template>
      <ng-template #headBadges>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="badges"
        />
      </ng-template>
      <ng-template #headDescription>
        <hilos-communications-channel-head
          [summary]="summary()"
          part="description"
        />
      </ng-template>
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
        <ng-template hilosTableCell="userLabel" let-row>
          @if (isHidden(row.userLabel)) {
            <hilos-hidden-mark />
            @if (row.userId !== null) {
              (#{{ row.userId }})
            }
          } @else {
            {{ recipientLabel(row) }}
          }
        </ng-template>
        <ng-template hilosTableDetail="notificationTitle" let-row>
          <div class="fw-semibold">
            <hilos-hideable [value]="row.notificationTitle"
              ><ng-template let-title>{{
                title || '—'
              }}</ng-template></hilos-hideable
            >
          </div>
          <code class="small text-body-secondary">{{
            row.notificationType
          }}</code>
        </ng-template>
        <ng-template hilosTableDetail="lastError" let-row>
          <hilos-hideable [value]="row.lastError"
            ><ng-template let-error>{{
              error || '—'
            }}</ng-template></hilos-hideable
          >
        </ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (isRetryable(row)) {
            <button
              hilosLoadingButton
              class="btn-outline-primary btn-sm"
              [loading]="retry.busy() && retryPendingId() === row.rowKey"
              [disabled]="retry.busy() && retryPendingId() !== row.rowKey"
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
  protected readonly summary = signal<HilosChannelSummary | null>(null)
  protected readonly isHidden = isHiddenValue
  protected readonly isRetryable = isDeliveryRetryable

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  private readonly channelSignal = computedSignal(
    () =>
      (this.router?.currentRoute.get().params['channelId'] as
        string | undefined) ?? '',
  )

  // The per-channel route ({channelId}) opens the journal with a channel preset; a
  // router-less mount (none in practice) shows the cross-cutting journal instead.
  protected readonly channel = hilosSignal(this.channelSignal)

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
      const context = this.context()
      const deliveries = this.deliveries()
      const channelSummary = createHilosChannelSummary(context)
      const currentSummary = computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.COMMUNICATIONS_CHANNEL &&
          page !== HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION &&
          page !== HilosPages.COMMUNICATIONS_DELIVERIES
        ) {
          return null
        }

        const summary = channelSummary.get()

        return summary?.channel === this.channelSignal.get() ? summary : null
      })
      const stopSummary = mirrorHilosSignal(currentSummary, this.summary)
      const unbindName = bindPageInstanceName(
        context.scopes,
        HilosPages.COMMUNICATIONS_CHANNEL,
        computedSignal(() => currentSummary.get()?.label),
      )
      deliveries.start()
      onCleanup(() => {
        unbindName()
        stopSummary()
        this.summary.set(null)
        deliveries.dispose()
      })
    })
  }

  // The re-queued row arrives live — at once for the author, as a delta for
  // everyone else — so the window is not re-requested.
  protected async doRetry(row: HilosDeliveryRow): Promise<void> {
    if (this.retry.busy()) {
      return
    }
    this.retryPendingId.set(row.rowKey)
    await this.retry.run(this.actions().sendDeliveryRetry(Number(row.rowKey)))
    this.retryPendingId.set(null)
  }

  /** The status badge contextual class. */
  protected statusClass(status: string): string {
    return STATUS_CLASS[status] ?? 'text-bg-secondary'
  }

  /**
   * The recipient label: the resolved display name, its user id, both, or a dash.
   * A hidden name is not this text: the template draws the mark beside the id.
   *
   * @param row The delivery row.
   */
  protected recipientLabel(row: HilosDeliveryRow): string {
    if (row.userId === null) {
      return '—'
    }
    const id = `#${row.userId}`

    return typeof row.userLabel === 'string' && row.userLabel !== ''
      ? `${row.userLabel} (${id})`
      : id
  }
}
