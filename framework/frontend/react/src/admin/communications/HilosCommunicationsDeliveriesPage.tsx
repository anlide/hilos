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
import { useContext, useEffect, useMemo, useState } from 'react'
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
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosCommunicationsDeliveriesPage}. */
export interface HilosCommunicationsDeliveriesPageProps {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosDeliveriesContext
}

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

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'text-bg-secondary'
}

// The recipient label: the resolved display name, its user id, both, or a dash.
function recipientLabel(row: HilosDeliveryRow): string {
  if (row.userId === null) {
    return '—'
  }
  const id = `#${row.userId}`

  return row.userLabel ? `${row.userLabel} (${id})` : id
}

/**
 * The framework delivery-logs admin page: the searchable, sortable, filterable
 * delivery journal with a per-row retry on a failed delivery.
 *
 * @param props The project context (scope stores + connection + action lifecycle).
 */
export function HilosCommunicationsDeliveriesPage({
  context,
}: HilosCommunicationsDeliveriesPageProps) {
  const router = useContext(HilosRouterContext)

  // The per-channel route ({channelId}) opens the journal with a channel preset; a
  // router-less mount (none in practice) shows the cross-cutting journal instead.
  const channelSignal = useMemo(
    () =>
      computedSignal(
        () =>
          (router?.currentRoute.get().params.channelId as string | undefined) ??
          '',
      ),
    [router],
  )
  const channel = useSignal(channelSignal)

  const deliveries = useMemo(
    () =>
      createHilosDeliveriesTable(context, channel ? { channel } : undefined),
    // The channel preset seeds a fresh table; navigating to another channel's
    // journal (a route-param change) rebinds it with the new preset.
    [context, channel],
  )
  const actions = useMemo(
    () => createHilosDeliveriesActions(context),
    [context],
  )

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    deliveries.start()

    return () => deliveries.dispose()
  }, [deliveries])

  // Domain filters: status and the created_at period ride the open filter map so
  // the backend narrows the window (no local filtering). Empty clears the filter.
  const [statusFilter, setStatusFilter] = useState('')
  const [fromFilter, setFromFilter] = useState('')
  const [toFilter, setToFilter] = useState('')

  function onStatus(value: string): void {
    setStatusFilter(value)
    deliveries.controller.setFilter(DELIVERY_FILTER_STATUS, value)
  }

  function onFrom(value: string): void {
    setFromFilter(value)
    deliveries.controller.setFilter(DELIVERY_FILTER_FROM, value)
  }

  function onTo(value: string): void {
    setToFilter(value)
    deliveries.controller.setFilter(DELIVERY_FILTER_TO, value)
  }

  // Retry: a per-row tracked action on a failed delivery. On success, re-request
  // the window — the journal has no live deltas, so the re-queued row only shows
  // after a refresh.
  const retryAction = useTrackedAction()
  const [retryPendingId, setRetryPendingId] = useState<string | null>(null)

  async function retry(row: HilosDeliveryRow): Promise<void> {
    if (retryAction.busy) {
      return
    }
    setRetryPendingId(row.rowKey)
    if (await retryAction.run(actions.sendDeliveryRetry(Number(row.rowKey)))) {
      deliveries.controller.start()
    }
    setRetryPendingId(null)
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS_DELIVERIES}>
      <div className="d-flex flex-wrap align-items-end gap-2 mb-3">
        {channel ? (
          <div>
            <span className="form-label d-block">Channel</span>
            <span className="badge text-bg-secondary-subtle text-secondary-emphasis fs-6">
              <code>{channel}</code>
            </span>
          </div>
        ) : null}
        <div>
          <label className="form-label" htmlFor="hilos-delivery-status">
            Status
          </label>
          <select
            id="hilos-delivery-status"
            className="form-select"
            data-id="hilos-delivery-status"
            value={statusFilter}
            onChange={(event) => onStatus(event.target.value)}
          >
            {HILOS_DELIVERY_STATUSES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="form-label" htmlFor="hilos-delivery-from">
            From
          </label>
          <input
            id="hilos-delivery-from"
            type="date"
            className="form-control"
            data-id="hilos-delivery-from"
            value={fromFilter}
            onChange={(event) => onFrom(event.target.value)}
          />
        </div>
        <div>
          <label className="form-label" htmlFor="hilos-delivery-to">
            To
          </label>
          <input
            id="hilos-delivery-to"
            type="date"
            className="form-control"
            data-id="hilos-delivery-to"
            value={toFilter}
            onChange={(event) => onTo(event.target.value)}
          />
        </div>
      </div>

      <HilosViewportTable
        label="Deliveries"
        controller={deliveries.controller}
        columns={COLUMNS}
        searchable
        searchPlaceholder="Search type or recipient…"
        emptyText="No deliveries match."
        row={(row) => (
          <>
            <td className="text-nowrap">{row.createdAt || '—'}</td>
            <td>
              <code>{row.channel || '—'}</code>
            </td>
            <td>
              <span className={`badge ${statusClass(row.status)}`}>
                {row.status || '—'}
              </span>
            </td>
            <td className="text-end">{row.attempts}</td>
            <td className="text-nowrap">{row.deliveredAt || '—'}</td>
            <td>{recipientLabel(row)}</td>
            <td>
              <div className="fw-semibold">{row.notificationTitle || '—'}</div>
              <code className="small text-body-secondary">
                {row.notificationType}
              </code>
            </td>
            <td className="text-body-secondary">{row.lastError || '—'}</td>
            <td className="text-end">
              {isDeliveryRetryable(row) ? (
                <LoadingButton
                  className="btn-outline-primary btn-sm"
                  loading={retryAction.busy && retryPendingId === row.rowKey}
                  disabled={retryAction.busy}
                  data-id={`hilos-delivery-retry-${row.rowKey}`}
                  onClick={() => void retry(row)}
                >
                  Retry
                </LoadingButton>
              ) : null}
            </td>
          </>
        )}
      />
    </HilosAdminPage>
  )
}
